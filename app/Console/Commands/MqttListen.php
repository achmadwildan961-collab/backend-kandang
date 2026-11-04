<?php

namespace App\Console\Commands;

use App\Services\Mqtt\MqttConnectionFactory;
use App\Services\Mqtt\MqttSubscriber;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class MqttListen extends Command
{
    protected $signature = 'mqtt:listen {--once : Exit after one session or connection failure} {--max-seconds=0 : Optional session time limit}';

    protected $description = 'Receive MQTT telemetry and heartbeats in a dedicated process';

    public function handle(MqttConnectionFactory $connections, MqttSubscriber $subscriber): int
    {
        try {
            $connections->validateConfiguration();
        } catch (InvalidArgumentException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
        $stopping = false;
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            foreach ([SIGTERM, SIGINT] as $signal) {
                pcntl_signal($signal, function () use (&$stopping) {
                    $stopping = true;
                });
            }
        }
        $delay = max(1, config('mqtt.retry_min_seconds'));
        $maximum = max($delay, config('mqtt.retry_max_seconds'));
        $this->info('Starting MQTT subscriber. Stop with Ctrl+C.');
        while (! $stopping) {
            try {
                $subscriber->runSession(function () use (&$stopping) {
                    return $stopping;
                }, max(0, (int) $this->option('max-seconds')));

                return self::SUCCESS;
            } catch (\Throwable $error) {
                Log::error('MQTT session failed; reconnect scheduled.', ['exception_type' => $error::class, 'retry_seconds' => $delay]);
                $this->warn('MQTT session failed. Check broker, ACL and database availability.');
                if ($this->option('once')) {
                    return self::FAILURE;
                }
                // Short sleep increments also allow prompt Linux signal handling.
                for ($i = 0; $i < $delay * 10 && ! $stopping; $i++) {
                    usleep(100000);
                }
                $delay = min($maximum, $delay * 2);
            }
        }

        return self::SUCCESS;
    }
}
