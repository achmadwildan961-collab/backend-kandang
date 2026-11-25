<?php

namespace App\Services\Firmware;

use Illuminate\Support\Str;
use InvalidArgumentException;
use PhpMqtt\Client\ConnectionSettings;

class StagingMqttFactory
{
    public function validate(): void
    {
        $c = config('firmware.staging');
        if (! app()->environment(['local', 'testing', 'staging']) || $c['enabled'] !== true || $c['virtual_only'] !== true
            || $c['host'] !== '127.0.0.1' || ! is_int($c['port']) || $c['port'] < 1024 || $c['port'] > 65535
            || ! is_string($c['username']) || $c['username'] === '' || ! is_string($c['password']) || $c['password'] === ''
            || ! is_array($c['scopes']) || $c['scopes'] === []
            || ($c['tls'] && (! is_string($c['ca_file']) || ! is_file($c['ca_file'])))) {
            throw new InvalidArgumentException('Isolated virtual staging MQTT is not explicitly configured.');
        }
        foreach ($c['scopes'] as $device => $coop) {
            if (preg_match('/\AVIRTUAL_[A-Z0-9_]{1,80}\z/', $device) !== 1 || ! is_int($coop) || $coop < 1) {
                throw new InvalidArgumentException('Invalid virtual-only scope.');
            }
        }
    }

    public function make(): StagingMqttClient
    {
        $this->validate();

        return new StagingMqttClient('127.0.0.1', config('firmware.staging.port'), 'poultriq-virtual-'.Str::random(12), StagingMqttClient::MQTT_3_1_1);
    }

    public function settings(): ConnectionSettings
    {
        $this->validate();
        $c = config('firmware.staging');
        $timeout = min(10, max(1, (int) $c['timeout_seconds']));

        return (new ConnectionSettings)->setUsername($c['username'])->setPassword($c['password'])
            ->setConnectTimeout($timeout)->setSocketTimeout($timeout)->setKeepAliveInterval(20)->setReconnectAutomatically(false)
            ->setUseTls((bool) $c['tls'])->setTlsVerifyPeer(true)->setTlsVerifyPeerName(true)->setTlsSelfSignedAllowed(false)
            ->setTlsCertificateAuthorityFile($c['ca_file'] ?: null);
    }
}
