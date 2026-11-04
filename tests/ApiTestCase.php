<?php

namespace Tests;

use App\Models\Coop;
use App\Models\Device;
use App\Models\User;
use App\Services\SensorCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $user;

    protected User $outsider;

    protected Coop $coop;

    protected Coop $otherCoop;

    protected Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-03T08:00:00Z'));
        $this->admin = User::factory()->admin()->create();
        $this->user = User::factory()->create();
        $this->outsider = User::factory()->create();
        $this->coop = Coop::create(['name' => 'Kandang Utama']);
        $this->otherCoop = Coop::create(['name' => 'Private coop']);
        foreach ([$this->coop, $this->otherCoop] as $coop) {
            $coop->settings()->create(['notification_preferences' => config('monitoring.notification_preferences')]);
        }
        $this->coop->users()->attach($this->user);
        $this->device = $this->coop->devices()->create(['device_identifier' => 'ESP32_DEVICE_A', 'name' => 'ESP32 Device A', 'type' => 'esp32', 'connection_status' => 'online', 'last_seen_at' => now()]);
    }

    protected function asUser(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('test')->plainTextToken);
    }

    protected function reading(string $id, float $value, $time = null): void
    {
        $spec = SensorCatalog::series()[$id];
        $this->coop->readings()->create(['device_id' => $this->device->id, 'sensor_identifier' => $id, 'sensor_type' => $spec['sensor_type'], 'value' => $value, 'unit' => $spec['unit'], 'recorded_at' => $time ?? now()]);
    }

    protected function readings(array $values = [28, 29.2, 31, 33.5, 30.2]): void
    {
        foreach (array_combine(SensorCatalog::indoorIdentifiers(), $values) as $id => $value) {
            $this->reading($id, $value);
        }
        $this->reading('outdoor_temperature', 29.2);
        $this->reading('gas_adc', 1200);
    }

    protected function settings(array $overrides = []): array
    {
        return array_merge(['minimum_outdoor_temperature' => 25, 'maximum_indoor_temperature' => 30, 'gas_threshold' => 1500], $overrides);
    }
}
