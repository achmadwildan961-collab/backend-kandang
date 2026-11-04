<?php

namespace Tests\Feature;

use App\Services\Mqtt\CommitAwareMqttClient;
use App\Services\Mqtt\MqttConnectionFactory;
use App\Services\Mqtt\MqttSubscriber;
use App\Services\Mqtt\TelemetryIngestor;
use Illuminate\Support\Facades\Log;
use Mockery;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\Message;
use PhpMqtt\Client\MessageType;
use Tests\TestCase;

class MqttTransportTest extends TestCase
{
    private function publication(string $content = 'payload'): Message
    {
        return (new Message(MessageType::PUBLISH(), 1))->setMessageId(7)->setTopic('test/topic')->setContent($content);
    }

    private function client(): CommitAwareMqttClient
    {
        return new class('unused.invalid') extends CommitAwareMqttClient
        {
            public array $acknowledgements = [];

            public function deliver(Message $message): void
            {
                $this->handleMessage($message);
            }

            protected function sendPublishAcknowledgement(int $messageId): void
            {
                $this->acknowledgements[] = $messageId;
            }
        };
    }

    public function test_qos_one_acknowledgement_only_occurs_after_successful_processing(): void
    {
        $client = $this->client();
        $client->receiveUsing(function ($topic, $payload, $retained) use ($client) {
            $this->assertSame([], $client->acknowledgements);
            $this->assertSame('test/topic', $topic);
            $this->assertSame('payload', $payload);
            $this->assertFalse($retained);
        });
        $client->deliver($this->publication());
        $this->assertSame([7], $client->acknowledgements);
    }

    public function test_persistence_failure_propagates_without_acknowledgement(): void
    {
        $client = $this->client();
        $client->receiveUsing(fn () => throw new \RuntimeException('database unavailable'));
        try {
            $client->deliver($this->publication());
            $this->fail('Expected failure.');
        } catch (\RuntimeException $error) {
            $this->assertSame('database unavailable', $error->getMessage());
            $this->assertSame([], $client->acknowledgements);
        }
    }

    public function test_subscriber_uses_one_persistent_connection_both_qos_one_topics_and_survives_bad_json(): void
    {
        Log::spy();
        $client = Mockery::mock(CommitAwareMqttClient::class);
        $factory = Mockery::mock(MqttConnectionFactory::class);
        $ingestor = Mockery::mock(TelemetryIngestor::class);
        $receiver = null;
        $client->shouldReceive('receiveUsing')->once()->andReturnUsing(function ($callback) use (&$receiver) {
            $receiver = $callback;
        });
        $loopHandler = null;
        $client->shouldReceive('registerLoopEventHandler')->once()->andReturnUsing(function ($callback) use (&$loopHandler, $client) {
            $loopHandler = $callback;

            return $client;
        });
        $client->shouldReceive('interrupt')->once();
        $client->shouldReceive('connect')->once()->with(Mockery::type(ConnectionSettings::class), false);
        $client->shouldReceive('subscribe')->once()->with('smart-kandang/coops/+/devices/+/telemetry', null, 1);
        $client->shouldReceive('subscribe')->once()->with('smart-kandang/coops/+/devices/+/status', null, 1);
        $client->shouldReceive('isConnected')->once()->andReturnTrue();
        $client->shouldReceive('disconnect')->once();
        $factory->shouldReceive('make')->once()->andReturn($client);
        $factory->shouldReceive('settings')->once()->andReturn(new ConnectionSettings);
        $ingestor->shouldReceive('ingest')->once()->with('topic', '{', false)->andThrow(new \InvalidArgumentException('Invalid JSON.'));
        $ingestor->shouldReceive('ingest')->once()->with('topic', 'valid', false)->andReturn('accepted');
        $client->shouldReceive('loop')->once()->with(true)->andReturnUsing(function () use (&$receiver, &$loopHandler, $client) {
            $receiver('topic', '{', false);
            $receiver('topic', 'valid', false);
            $loopHandler($client);
        });
        (new MqttSubscriber($factory, $ingestor))->runSession(fn () => true);
        Log::shouldHaveReceived('warning')->once()->with('MQTT message rejected.', ['reason' => 'Invalid JSON.']);
    }

    public function test_listener_reconnects_after_a_temporary_failure(): void
    {
        Log::spy();
        config(['mqtt.retry_min_seconds' => 1, 'mqtt.retry_max_seconds' => 1]);
        $this->mock(MqttConnectionFactory::class)->shouldReceive('validateConfiguration')->once();
        $subscriber = $this->mock(MqttSubscriber::class);
        $subscriber->shouldReceive('runSession')->once()->ordered()->andThrow(new \RuntimeException('private connection details'));
        $subscriber->shouldReceive('runSession')->once()->ordered()->andReturnNull();
        $this->artisan('mqtt:listen')->assertSuccessful();
        Log::shouldHaveReceived('error')->once()->with('MQTT session failed; reconnect scheduled.', ['exception_type' => \RuntimeException::class, 'retry_seconds' => 1]);
    }

    public function test_disabled_listener_never_attempts_a_connection(): void
    {
        config(['mqtt.enabled' => false]);
        $this->mock(MqttSubscriber::class)->shouldNotReceive('runSession');
        $this->artisan('mqtt:listen', ['--once' => true])->assertFailed();
    }

    public function test_anonymous_connections_are_refused_and_tls_verification_stays_enabled(): void
    {
        config(['mqtt.enabled' => true, 'mqtt.username' => '', 'mqtt.password' => '']);
        $this->mock(MqttSubscriber::class)->shouldNotReceive('runSession');
        $this->artisan('mqtt:listen', ['--once' => true])->assertFailed();
        config(['mqtt.username' => 'test-user', 'mqtt.password' => 'test-secret', 'mqtt.tls' => true]);
        $factory = app(MqttConnectionFactory::class);
        $factory->validateConfiguration();
        $settings = $factory->settings();
        $this->assertTrue($settings->shouldUseTls());
        $this->assertTrue($settings->shouldTlsVerifyPeer());
        $this->assertTrue($settings->shouldTlsVerifyPeerName());
        $this->assertFalse($settings->isTlsSelfSignedAllowed());
    }
}
