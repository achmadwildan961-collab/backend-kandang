<?php

namespace App\Console\Commands;

use App\Services\Mqtt\DeviceResolver;
use App\Services\Mqtt\MqttConnectionFactory;
use App\Services\Mqtt\PayloadValidator;
use App\Services\Mqtt\TelemetryIngestor;
use App\Services\SensorCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MqttSimulate extends Command
{
    protected $signature = 'mqtt:simulate {coop} {device}
        {--scenario=normal : normal, sample, high-indoor, low-outdoor, high-gas}
        {--status= : online or offline heartbeat instead of telemetry}
        {--message-id= : Defaults to a new UUID}
        {--sent-at= : UTC ISO timestamp; defaults to now}
        {--transport=preview : preview, local (direct ingestion), or mqtt (broker)}
        {--repeat=1 : Repeat the exact same payload to test deduplication}';

    protected $description = 'Generate development telemetry for an existing registered device';

    public function handle(DeviceResolver $devices, PayloadValidator $validator, TelemetryIngestor $ingestor, MqttConnectionFactory $connections): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('The simulator is only available in local/testing environments.');

            return self::FAILURE;
        }
        $client = null;
        try {
            $mode = $this->option('transport');
            $repeat = filter_var($this->option('repeat'), FILTER_VALIDATE_INT);
            if (! in_array($mode, ['preview', 'local', 'mqtt'], true) || $repeat === false || $repeat < 1 || $repeat > 100) {
                throw new InvalidArgumentException('Invalid transport or repeat count (1-100).');
            }
            $scenario = $this->option('scenario');
            if (! in_array($scenario, ['normal', 'sample', 'high-indoor', 'low-outdoor', 'high-gas'], true)) {
                throw new InvalidArgumentException('Invalid simulation scenario.');
            }
            $kind = $this->option('status') ? 'status' : 'telemetry';
            $topic = config('mqtt.topic_prefix').'/coops/'.$this->argument('coop').'/devices/'.$this->argument('device').'/'.$kind;
            $devices->resolve($topic);
            $data = ['message_id' => $this->option('message-id') ?: (string) Str::uuid(), 'sent_at' => $this->option('sent-at') ?: now()->utc()->format('Y-m-d\TH:i:s.u\Z')];
            if ($kind === 'status') {
                $data['status'] = $this->option('status');
            } else {
                $indoor = match ($scenario) {
                    'sample' => [28, 29.2, 31, 33.5, 30.2],
                    'high-indoor' => [35, 35, 35, 35, 35],
                    default => [28, 28, 28, 28, 28],
                };
                $values = array_combine(SensorCatalog::indoorIdentifiers(), $indoor) + [
                    'outdoor_temperature' => $scenario === 'low-outdoor' ? 20 : 28,
                    'indoor_humidity' => 70, 'outdoor_humidity' => 68,
                    'gas_adc' => $scenario === 'high-gas' ? 2500 : 1000,
                ];
                $data['readings'] = [];
                foreach ($values as $id => $value) {
                    $data['readings'][] = ['sensor_identifier' => $id, 'value' => $value];
                }
            }
            $payload = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $validator->validate($payload, $kind);
            if ($mode === 'preview') {
                $this->line(json_encode(['topic' => $topic, 'payload' => $data], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

                return self::SUCCESS;
            }
            if ($mode === 'mqtt') {
                $connections->validateConfiguration();
                $client = $connections->make('sim-'.Str::random(16));
                $client->connect($connections->settings(), true);
            }
            for ($i = 0; $i < $repeat; $i++) {
                if ($client) {
                    $client->publish($topic, $payload, 1, false);
                    $client->loop(true, true, 10);
                    $this->info('Publish sent; verify receipt and readings separately.');
                } else {
                    $this->info('Local ingestion: '.$ingestor->ingest($topic, $payload));
                }
            }

            return self::SUCCESS;
        } catch (InvalidArgumentException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        } catch (\Throwable) {
            $this->error('Simulation failed. Check broker/database availability and configuration.');

            return self::FAILURE;
        } finally {
            try {
                if ($client?->isConnected()) {
                    $client->disconnect();
                }
            } catch (\Throwable) {
            }
        }
    }
}
