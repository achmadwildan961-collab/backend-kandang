<?php

namespace App\Console\Commands;

use App\Services\Firmware\StagingMqttFactory;
use App\Services\Firmware\StagingMqttTransport;
use App\Services\Integration\CanonicalPayload;
use Illuminate\Console\Command;

class FirmwareBrokerSmoke extends Command
{
    protected $signature = 'firmware:broker-smoke';

    protected $description = 'Opt-in loopback virtual broker QoS1 publish/subscribe smoke; no database or physical commands';

    public function handle(StagingMqttFactory $factory, StagingMqttTransport $transport): int
    {
        $observer = null;
        try {
            $factory->validate();
            $scopes = config('firmware.staging.scopes');
            $device = array_key_first($scopes);
            $payload = json_decode(file_get_contents(base_path('contracts/firmware/fixtures/config-operational.json')), true, flags: JSON_THROW_ON_ERROR);
            unset($payload['payload_hash']);
            $payload['coop_id'] = $scopes[$device];
            $payload['device_identifier'] = $device;
            $payload['message_id'] = hash('sha256', random_bytes(32));
            $payload['generated_at'] = now()->toISOString();
            $payload['expires_at'] = now()->addMinute()->toISOString();
            $payload['payload_hash'] = hash('sha256', CanonicalPayload::encode($payload));
            $topic = "smart-kandang/coops/{$scopes[$device]}/devices/$device/integration/v1/config/operational";
            $observer = $factory->make();
            $observer->connect($factory->settings(), true);
            $received = false;
            $observer->subscribe($topic, function ($incomingTopic, $bytes) use ($topic, $payload, &$received) {
                $incoming = json_decode($bytes, true);
                $received = $incomingTopic === $topic && is_array($incoming)
                    && CanonicalPayload::encode($incoming) === CanonicalPayload::encode($payload);
            }, 1);
            $started = microtime(true);
            do {
                $observer->loopOnce($started, true);
            } while (! $observer->brokerAcknowledged() && microtime(true) - $started < 5);
            if (! $observer->brokerAcknowledged()) {
                throw new \RuntimeException('SUBACK timeout');
            }
            $transport->publish($topic, $payload, false, $payload['message_id']);
            $started = microtime(true);
            while (! $received && microtime(true) - $started < 5) {
                $observer->loopOnce($started, true);
            }
            if (! $received) {
                throw new \RuntimeException('Virtual observer did not receive matching payload');
            }
            $this->info('PASS: isolated QoS1 broker PUBACK and matching virtual subscription. Device-applied and physical tests NOT performed.');

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Isolated broker smoke failed or disabled. Check opt-in settings and dedicated broker. No device application proven.');

            return self::FAILURE;
        } finally {
            try {
                if ($observer?->isConnected()) {
                    $observer->disconnect();
                }
            } catch (\Throwable) {
            }
        }
    }
}
