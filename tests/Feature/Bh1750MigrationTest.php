<?php

namespace Tests\Feature;

use App\Models\Coop;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Bh1750MigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh')->assertSuccessful();
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_additive_lux_tables_do_not_backfill_or_mutate_legacy_thresholds_history_or_outbox(): void
    {
        $migration = require database_path('migrations/2026_11_24_000004_create_bh1750_lighting_foundation.php');
        $migration->down();
        $coop = Coop::create(['name' => 'Existing legacy coop']);
        $device = $coop->devices()->create(['device_identifier' => 'REGISTERED_LEGACY', 'name' => 'Existing', 'type' => 'legacy']);
        DB::table('coop_lighting_settings')->insert(['coop_id' => $coop->id, 'lighting_mode' => 'automatic',
            'dark_threshold' => 200, 'bright_threshold' => 800, 'threshold_unit' => 'adc', 'measurement_version' => 'ldr-v1', 'config_version' => 7]);
        DB::table('lighting_configuration_outbox')->insert(['coop_id' => $coop->id, 'config_version' => 7, 'payload' => '{"threshold_unit":"adc"}', 'status' => 'blocked']);
        $coop->readings()->create(['device_id' => $device->id, 'sensor_identifier' => 'outdoor_light_adc', 'sensor_type' => 'outdoor_light',
            'unit' => 'adc', 'value' => 400, 'recorded_at' => now()]);
        $before = [DB::table('coop_lighting_settings')->get()->toArray(), DB::table('lighting_configuration_outbox')->get()->toArray(), DB::table('sensor_readings')->get()->toArray()];
        $migration->up();
        $this->assertDatabaseCount('coop_lighting_lux_settings', 0);
        $this->assertDatabaseCount('lighting_lux_configuration_outbox', 0);
        $this->assertEquals($before, [DB::table('coop_lighting_settings')->get()->toArray(), DB::table('lighting_configuration_outbox')->get()->toArray(), DB::table('sensor_readings')->get()->toArray()]);
        $migration->down();
        $migration->up();
        $this->assertEquals($before, [DB::table('coop_lighting_settings')->get()->toArray(), DB::table('lighting_configuration_outbox')->get()->toArray(), DB::table('sensor_readings')->get()->toArray()]);
    }

    public function test_rollback_refuses_to_discard_new_lux_policy_or_outbox_intent(): void
    {
        $migration = require database_path('migrations/2026_11_24_000004_create_bh1750_lighting_foundation.php');
        $coop = Coop::create(['name' => 'Lux draft coop']);
        DB::table('coop_lighting_lux_settings')->insert(['coop_id' => $coop->id, 'lighting_mode' => 'auto', 'min_lux' => null, 'max_lux' => null]);
        try {
            $migration->down();
            $this->fail('Draft must prevent lossy rollback.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('forward migration', $error->getMessage());
        }
        $this->assertDatabaseCount('coop_lighting_lux_settings', 1);
        DB::table('coop_lighting_lux_settings')->delete(); // Isolated fixture; test outbox guard independently.
        DB::table('lighting_lux_configuration_outbox')->insert(['coop_id' => $coop->id, 'config_version' => 1,
            'idempotency_key' => hash('sha256', 'test'), 'payload' => '{}']);
        try {
            $migration->down();
            $this->fail('Outbox intent must prevent lossy rollback.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('forward migration', $error->getMessage());
        }
        $this->assertDatabaseCount('lighting_lux_configuration_outbox', 1);
    }
}
