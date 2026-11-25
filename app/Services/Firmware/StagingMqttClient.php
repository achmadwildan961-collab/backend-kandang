<?php

namespace App\Services\Firmware;

use PhpMqtt\Client\MqttClient;

class StagingMqttClient extends MqttClient
{
    public function brokerAcknowledged(): bool
    {
        // A bounded library loop can return on timeout with outstanding packets.
        return $this->allQueuesAreEmpty();
    }
}
