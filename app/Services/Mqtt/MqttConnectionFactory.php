<?php

namespace App\Services\Mqtt;

use InvalidArgumentException;
use PhpMqtt\Client\ConnectionSettings;

class MqttConnectionFactory
{
    public function validateConfiguration(): void
    {
        if (! config('mqtt.enabled')) {
            throw new InvalidArgumentException('MQTT is disabled. Configure the broker and set MQTT_ENABLED=true when ready.');
        }
        if (! config('mqtt.host') || ! config('mqtt.username') || ! config('mqtt.password')) {
            throw new InvalidArgumentException('MQTT host, username and password are required; anonymous connections are disabled.');
        }
        if (config('mqtt.port') < 1 || config('mqtt.port') > 65535 || ! preg_match('/\A[A-Za-z0-9_-]{1,100}\z/', config('mqtt.client_id')) || ! preg_match('~\A[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*\z~', config('mqtt.topic_prefix'))) {
            throw new InvalidArgumentException('Invalid MQTT port, client ID or topic prefix.');
        }
    }

    public function make(?string $clientId = null): CommitAwareMqttClient
    {
        // Keep the library's verbose packet logger disconnected from app logs.
        return new CommitAwareMqttClient(config('mqtt.host'), config('mqtt.port'), $clientId ?? config('mqtt.client_id'), CommitAwareMqttClient::MQTT_3_1_1);
    }

    public function settings(): ConnectionSettings
    {
        return (new ConnectionSettings)
            ->setUsername(config('mqtt.username'))->setPassword(config('mqtt.password'))
            ->setConnectTimeout(5)->setSocketTimeout(5)->setKeepAliveInterval(20)
            ->setReconnectAutomatically(false)
            ->setUseTls(config('mqtt.tls'))->setTlsVerifyPeer(true)->setTlsVerifyPeerName(true)
            ->setTlsSelfSignedAllowed(false)->setTlsCertificateAuthorityFile(config('mqtt.tls_ca_file') ?: null);
    }
}
