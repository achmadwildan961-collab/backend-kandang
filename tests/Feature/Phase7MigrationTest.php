<?php

namespace Tests\Feature;

use App\Models\Coop;
use App\Services\OperationalConfigurationOutbox;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Phase7MigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // DDL must not run inside RefreshDatabase's wrapping transaction (MySQL auto-commits DDL).
        $this->artisan('migrate:fresh')->assertSuccessful();
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    private function migrations(): array
    {
        // Original Phase 7 migrations; BH1750 additive migration has separate preservation tests.
        return array_map(fn ($file) => require $file, glob(database_path('migrations/2026_11_24_00000[0-3]_*.php')));
    }

    public function test_additive_roundtrip_preserves_legacy_values_events_and_readings_without_backfill(): void
    {
        $migrations = $this->migrations();
        foreach (array_reverse($migrations) as $migration) {
            $migration->down();
        }
        $coop = Coop::create(['name' => 'Historical coop']);
        $coop->settings()->create(['minimum_outdoor_temperature' => 17, 'notification_preferences' => [], 'alert_state' => ['outdoor' => true]]);
        $device = $coop->devices()->create(['device_identifier' => 'HISTORICAL', 'name' => 'Registered device', 'type' => 'legacy']);
        $coop->readings()->create(['device_id' => $device->id, 'sensor_identifier' => 'outdoor_temperature', 'sensor_type' => 'outdoor_temperature', 'value' => 16, 'unit' => 'celsius', 'recorded_at' => now()]);
        $coop->events()->create(['event_type' => 'low_outdoor_temperature', 'severity' => 'warning', 'description' => 'Historical outdoor alert', 'occurred_at' => now()]);
        foreach ($migrations as $migration) {
            $migration->up();
        }
        $settings = $coop->settings()->first();
        $this->assertNull($settings->minimum_indoor_temperature);
        $this->assertSame(17.0, $settings->minimum_outdoor_temperature);
        $this->assertSame(1, $settings->config_version);
        $this->assertTrue($settings->alert_state['outdoor']);
        $this->assertNull($coop->readings()->first()->batch_key);
        $this->assertDatabaseHas('system_events', ['event_type' => 'low_outdoor_temperature']);
        $this->assertDatabaseHas('devices', ['device_identifier' => 'HISTORICAL', 'type' => 'legacy']);
        $this->assertDatabaseCount('operational_configuration_outbox', 0);
    }

    public function test_rollback_refuses_to_destroy_populated_phase7_data(): void
    {
        $coop = Coop::create(['name' => 'Configured coop']);
        $settings = $coop->settings()->create(['minimum_indoor_temperature' => 24, 'notification_preferences' => []]);
        DB::table('coop_settings')->where('id', $settings->id)->update(['config_version' => 2]);
        $device = $coop->devices()->create(['device_identifier' => 'NEW', 'name' => 'Registered device', 'type' => 'esp32']);
        $reading = $coop->readings()->create(['device_id' => $device->id, 'sensor_identifier' => 'indoor_dht22_1', 'sensor_type' => 'indoor_temperature', 'value' => 27, 'unit' => 'celsius', 'recorded_at' => now()]);
        DB::table('sensor_readings')->where('id', $reading->id)->update(['batch_key' => hash('sha256', 'accepted')]);
        DB::transaction(fn () => app(OperationalConfigurationOutbox::class)->stage($coop, $settings->refresh()));
        foreach ($this->migrations() as $migration) {
            try {
                $migration->down();
                $this->fail('Populated Phase 7 data must block destructive rollback.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('forward migration', $exception->getMessage());
            }
        }
        $this->assertSame(24.0, $settings->refresh()->minimum_indoor_temperature);
        $this->assertSame(2, $settings->config_version);
        $this->assertSame(hash('sha256', 'accepted'), $reading->fresh()->batch_key);
        $this->assertDatabaseCount('operational_configuration_outbox', 1);
    }
}
