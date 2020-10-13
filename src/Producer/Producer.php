<?php

declare(strict_types=1);

namespace Longyan\Kafka\Producer;

use Longyan\Kafka\Broker;
use Longyan\Kafka\Protocol\ErrorCode;
use Longyan\Kafka\Protocol\Produce\PartitionProduceData;
use Longyan\Kafka\Protocol\Produce\ProduceRequest;
use Longyan\Kafka\Protocol\Produce\ProduceResponse;
use Longyan\Kafka\Protocol\Produce\TopicProduceData;
use Longyan\Kafka\Protocol\RecordBatch\Record;
use Longyan\Kafka\Protocol\RecordBatch\RecordBatch;

class Producer
{
    /**
     * @var ProducerConfig
     */
    protected $config;

    /**
     * @var Broker
     */
    protected $broker;

    public function __construct(ProducerConfig $config)
    {
        $this->config = $config;
        $this->broker = $broker = new Broker($config);
        if ($config->getUpdateBrokers()) {
            $broker->updateBrokers();
        } else {
            $broker->setBrokers($config->getBrokers());
        }
    }

    public function send(string $topic, ?string $value, ?string $key = null, array $headers = [], int $partitionIndex = 0)
    {
        $config = $this->config;
        $request = new ProduceRequest();
        $request->setAcks($acks = $config->getAcks());
        $recvTimeout = $config->getRecvTimeout();
        if ($recvTimeout < 0) {
            $request->setTimeoutMs(60000);
        } else {
            $request->setTimeoutMs((int) ($recvTimeout * 1000));
        }

        $topicData = new TopicProduceData();
        $topicData->setName($topic);
        $partition = new PartitionProduceData();
        $partition->setPartitionIndex($partitionIndex);
        $recordBatch = new RecordBatch();
        $recordBatch->setProducerId($config->getProducerId());
        $recordBatch->setProducerEpoch($config->getProducerEpoch());
        $recordBatch->setPartitionLeaderEpoch($config->getPartitionLeaderEpoch());
        $recordBatch->setMagic(2);
        $record = new Record();
        $record->setKey($key);
        $record->setValue($value);
        $record->setHeaders($headers);
        $recordBatch->setRecords([$record]);
        $timestamp = (int) (microtime(true) * 1000);
        $recordBatch->setFirstTimestamp($timestamp);
        $recordBatch->setMaxTimestamp($timestamp);
        $partition->setRecords($recordBatch);
        $topicData->setPartitions([$partition]);

        $request->setTopics([$topicData]);

        $hasResponse = 0 !== $acks;
        $client = $this->broker->getRandomClient();
        $correlationId = $client->send($request, null, $hasResponse);
        if (!$hasResponse) {
            return;
        }
        /** @var ProduceResponse $response */
        $response = $client->recv($correlationId);
        foreach ($response->getResponses() as $response) {
            foreach ($response->getPartitions() as $partition) {
                ErrorCode::check($partition->getErrorCode());
            }
        }
    }

    /**
     * @param ProduceMessage[] $messages
     *
     * @return void
     */
    public function sendBatch(array $messages)
    {
        $config = $this->config;
        $request = new ProduceRequest();
        $request->setAcks($acks = $config->getAcks());
        $recvTimeout = $config->getRecvTimeout();
        if ($recvTimeout < 0) {
            $request->setTimeoutMs(60000);
        } else {
            $request->setTimeoutMs((int) ($recvTimeout * 1000));
        }

        $timestamp = (int) (microtime(true) * 1000);
        $topicsMap = [];
        $partitionsMap = [];
        foreach ($messages as $message) {
            $topicName = $message->getTopic();
            $partitionIndex = $message->getPartitionIndex();
            if (isset($topicsMap[$topicName])) {
                /** @var TopicProduceData $topicData */
                $topicData = $topicsMap[$topicName];
                $partitions = $topicData->getPartitions();
            } else {
                $topicData = $topicsMap[$topicName] = new TopicProduceData();
                $topicData->setName($topicName);
                $partitions = [];
            }
            if (isset($partitionsMap[$topicName][$partitionIndex])) {
                /** @var PartitionProduceData $partition */
                $partition = $partitionsMap[$topicName][$partitionIndex];
                $recordBatch = $partition->getRecords();
                $records = $recordBatch->getRecords();
            } else {
                $partition = $partitions[] = $partitionsMap[$topicName][$partitionIndex] = new PartitionProduceData();
                $partition->setPartitionIndex($partitionIndex);
                $partition->setRecords($recordBatch = new RecordBatch());
                $recordBatch->setMagic(2);
                $recordBatch->setProducerId($config->getProducerId());
                $recordBatch->setProducerEpoch($config->getProducerEpoch());
                $recordBatch->setPartitionLeaderEpoch($config->getPartitionLeaderEpoch());
                $recordBatch->setFirstTimestamp($timestamp);
                $recordBatch->setMaxTimestamp($timestamp);
                $recordBatch->setLastOffsetDelta(-1);
                $records = [];
            }
            $offsetDelta = $recordBatch->getLastOffsetDelta() + 1;
            $recordBatch->setLastOffsetDelta($offsetDelta);
            $record = $records[] = new Record();
            $record->setKey($message->getKey());
            $record->setValue($message->getValue());
            $record->setHeaders($message->getHeaders());
            $record->setOffsetDelta($offsetDelta);
            $record->setTimestampDelta(((int) (microtime(true) * 1000)) - $timestamp);
            $recordBatch->setRecords($records);

            $topicData->setPartitions($partitions);
        }
        $request->setTopics($topicsMap);

        $hasResponse = 0 !== $acks;
        $client = $this->broker->getRandomClient();
        $correlationId = $client->send($request, null, $hasResponse);
        if (!$hasResponse) {
            return;
        }
        /** @var ProduceResponse $response */
        $response = $client->recv($correlationId);
        foreach ($response->getResponses() as $response) {
            foreach ($response->getPartitions() as $partition) {
                ErrorCode::check($partition->getErrorCode());
            }
        }
    }

    public function close()
    {
        $this->broker->close();
    }

    /**
     * @return ProducerConfig
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * @return Broker
     */
    public function getBroker()
    {
        return $this->broker;
    }
}