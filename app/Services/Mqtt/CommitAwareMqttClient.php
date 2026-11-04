<?php

namespace App\Services\Mqtt;

use Closure;
use PhpMqtt\Client\Message;
use PhpMqtt\Client\MessageType;
use PhpMqtt\Client\MqttClient;

class CommitAwareMqttClient extends MqttClient
{
    private ?Closure $receiver = null;

    public function receiveUsing(Closure $receiver): void
    {
        $this->receiver = $receiver;
    }

    protected function handleMessage(Message $message): void
    {
        // php-mqtt/client 2.3 acknowledges QoS 1 before invoking callbacks and
        // swallows callback exceptions. Our ingestion must commit before PUBACK.
        // Subscribe at QoS 1; all other protocol packets remain library-owned.
        if ($this->receiver && $message->getType()->equals(MessageType::PUBLISH()) && $message->getQualityOfService() <= 1) {
            ($this->receiver)($message->getTopic(), $message->getContent(), $message->getRetained());
            if ($message->getQualityOfService() === 1) {
                $this->sendPublishAcknowledgement($message->getMessageId());
            }

            return;
        }
        parent::handleMessage($message);
    }
}
