<?php

namespace Tests\Feature;

use App\Services\Firmware\StagingMqttClient;
use App\Services\Firmware\StagingMqttFactory;
use App\Services\Firmware\StagingMqttTransport;
use App\Services\Integration\CanonicalPayload;
use App\Services\Integration\DisabledMqttTransport;
use App\Services\Integration\MqttTransport;
use InvalidArgumentException;
use Mockery;
use PhpMqtt\Client\ConnectionSettings;
use RuntimeException;
use Tests\TestCase;

class StagingMqttTransportTest extends TestCase
{
    private function enable(): void
    {
        config(['firmware.staging.enabled' => true, 'firmware.staging.virtual_only' => true,
            'firmware.staging.username' => 'virtual-backend', 'firmware.staging.password' => 'test-secret-not-real',
            'firmware.staging.scopes' => ['VIRTUAL_INDOOR' => 1], 'firmware.staging.attempts' => 2]);
    }

    private function wire(): array
    {
        $wire = json_decode(file_get_contents(base_path('contracts/firmware/fixtures/config-operational.json')), true);
        unset($wire['payload_hash']);
        $wire['generated_at'] = now()->toISOString();
        $wire['expires_at'] = now()->addMinute()->toISOString();
        $wire['payload_hash'] = hash('sha256', CanonicalPayload::encode($wire));

        return $wire;
    }

    private function topic(): string
    {
        return 'smart-kandang/coops/1/devices/VIRTUAL_INDOOR/integration/v1/config/operational';
    }

    public function test_default_production_nonloopback_missing_credentials_and_real_identity_are_blocked(): void
    {
        $this->assertInstanceOf(DisabledMqttTransport::class, app(MqttTransport::class));
        foreach (['disabled', 'production', 'remote', 'credentials', 'identity', 'tls', 'scope', 'virtual_flag'] as $case) {
            $this->enable();
            $this->app['env'] = $case === 'production' ? 'production' : 'testing';
            config(['firmware.staging.host' => $case === 'remote' ? 'example.invalid' : '127.0.0.1',
                'firmware.staging.enabled' => $case !== 'disabled', 'firmware.staging.virtual_only' => $case !== 'virtual_flag',
                'firmware.staging.password' => $case === 'credentials' ? null : 'fake-secret',
                'firmware.staging.tls' => $case === 'tls', 'firmware.staging.ca_file' => null,
                'firmware.staging.scopes' => $case === 'identity' ? ['ESP32_REAL' => 1] : ($case === 'scope' ? [] : ['VIRTUAL_INDOOR' => 1])]);
            try {
                app(StagingMqttFactory::class)->validate();
                $this->fail('Unsafe staging enabled: '.$case);
            } catch (InvalidArgumentException $error) {
                $this->assertStringNotContainsString('fake-secret', $error->getMessage());
            }
        }
    }

    public function test_commands_wildcards_foreign_scope_retained_and_bad_hash_never_reach_factory(): void
    {
        $this->enable();
        $factory = Mockery::mock(StagingMqttFactory::class)->makePartial();
        $factory->shouldNotReceive('make');
        $transport = new StagingMqttTransport($factory);
        foreach (['command', 'wildcard', 'scope', 'retained', 'hash', 'key', 'expiry', 'unknown_field'] as $case) {
            $wire = $this->wire();
            $topic = match ($case) {
                'command' => 'smart-kandang/coops/1/devices/VIRTUAL_INDOOR/integration/v1/actuator/set',
                'wildcard' => 'smart-kandang/#', default => $this->topic(),
            };
            if ($case === 'scope') {
                $wire['coop_id'] = 2;
            } elseif ($case === 'hash') {
                $wire['payload_hash'] = str_repeat('0', 64);
            } elseif ($case === 'expiry') {
                $wire['expires_at'] = now()->subSecond()->toISOString();
            } elseif ($case === 'unknown_field') {
                $wire['actuator_commands'] = ['heating_lamp' => true];
            }
            try {
                $transport->publish($topic, $wire, $case === 'retained', $case === 'key' ? 'different' : $wire['message_id']);
                $this->fail('Unsafe publication accepted.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_mocked_qos_one_puback_success_closes_connection_without_changing_production_binding(): void
    {
        $this->enable();
        config(['firmware.staging.retained_authorized' => true]);
        $wire = $this->wire();
        $client = Mockery::mock(StagingMqttClient::class);
        $client->shouldReceive('connect')->once()->with(Mockery::type(ConnectionSettings::class), true);
        $client->shouldReceive('publish')->once()->with($this->topic(), CanonicalPayload::encode($wire), 1, true);
        $client->shouldReceive('loop')->once()->with(true, true, 5);
        $client->shouldReceive('brokerAcknowledged')->once()->andReturn(true);
        $client->shouldReceive('isConnected')->once()->andReturn(true);
        $client->shouldReceive('disconnect')->once();
        $factory = Mockery::mock(StagingMqttFactory::class)->makePartial();
        $factory->shouldReceive('make')->once()->andReturn($client);
        (new StagingMqttTransport($factory))->publish($this->topic(), $wire, true, $wire['message_id']);
        $this->assertInstanceOf(DisabledMqttTransport::class, app(MqttTransport::class));
    }

    public function test_mocked_timeout_retries_same_payload_with_bounded_budget_and_sanitized_error(): void
    {
        $this->enable();
        $wire = $this->wire();
        $client = Mockery::mock(StagingMqttClient::class);
        $client->shouldReceive('connect')->twice();
        $client->shouldReceive('publish')->twice()->with($this->topic(), CanonicalPayload::encode($wire), 1, false);
        $client->shouldReceive('loop')->twice();
        $client->shouldReceive('brokerAcknowledged')->twice()->andReturn(false);
        $client->shouldReceive('isConnected')->twice()->andReturn(true);
        $client->shouldReceive('disconnect')->twice();
        $factory = Mockery::mock(StagingMqttFactory::class)->makePartial();
        $factory->shouldReceive('make')->twice()->andReturn($client);
        try {
            (new StagingMqttTransport($factory))->publish($this->topic(), $wire, false, $wire['message_id']);
            $this->fail('Timeout incorrectly treated as broker ACK.');
        } catch (RuntimeException $error) {
            $this->assertSame('Isolated staging publish failed within its retry budget.', $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
    }

    public function test_mocked_connect_failure_can_recover_without_leaking_credentials(): void
    {
        $this->enable();
        $wire = $this->wire();
        $failed = Mockery::mock(StagingMqttClient::class);
        $failed->shouldReceive('connect')->once()->andThrow(new RuntimeException('test-secret-not-real'));
        $failed->shouldReceive('isConnected')->once()->andReturn(false);
        $success = Mockery::mock(StagingMqttClient::class);
        $success->shouldReceive('connect')->once();
        $success->shouldReceive('publish')->once();
        $success->shouldReceive('loop')->once();
        $success->shouldReceive('brokerAcknowledged')->once()->andReturn(true);
        $success->shouldReceive('isConnected')->once()->andReturn(true);
        $success->shouldReceive('disconnect')->once();
        $factory = Mockery::mock(StagingMqttFactory::class)->makePartial();
        $factory->shouldReceive('make')->twice()->andReturn($failed, $success);
        (new StagingMqttTransport($factory))->publish($this->topic(), $wire, false, $wire['message_id']);
        $this->assertTrue(true);
    }
}
