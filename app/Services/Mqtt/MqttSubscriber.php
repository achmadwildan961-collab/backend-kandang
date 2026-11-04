<?php

namespace App\Services\Mqtt;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class MqttSubscriber
{
    public function __construct(private MqttConnectionFactory $connections, private TelemetryIngestor $ingestor) {}

    public function runSession(callable $shouldStop, int $maxSeconds = 0): void
    {
        $client = $this->connections->make();
        $client->receiveUsing(function (string $topic, string $payload, bool $retained) {
            try {
                $result = $this->ingestor->ingest($topic, $payload, $retained);
                Log::info('MQTT message processed.', ['result' => $result]);
            } catch (InvalidArgumentException $error) {
                // Invalid messages are deliberately acknowledged, preventing poison
                // message loops. Never log payloads, tokens or broker credentials.
                Log::warning('MQTT message rejected.', ['reason' => $error->getMessage()]);
            }
            // Database/runtime failures propagate: no ACK, disconnect and retry.
        });
        $started = microtime(true);
        $client->registerLoopEventHandler(function (CommitAwareMqttClient $client) use ($shouldStop, $started, $maxSeconds) {
            if ($shouldStop() || ($maxSeconds > 0 && microtime(true) - $started >= $maxSeconds)) {
                $client->interrupt();
            }
        });
        try {
            // Stable client ID and persistent broker session retain queued QoS 1
            // messages through subscriber restarts. One connection for both topics.
            $client->connect($this->connections->settings(), false);
            foreach (['telemetry', 'status'] as $kind) {
                $client->subscribe(config('mqtt.topic_prefix').'/coops/+/devices/+/'.$kind, null, 1);
            }
            Log::info('MQTT subscriber connected.');
            $client->loop(true);
        } finally {
            try {
                if ($client->isConnected()) {
                    $client->disconnect();
                }
            } catch (\Throwable) {
                // Preserve the original failure; the outer loop reconnects.
            }
        }
    }
}
