<?php

namespace Tests\Feature;

use App\Models\Coop;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class IntegrationMigrationTest extends TestCase
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

    public function test_additive_migration_preserves_phase7_data_and_empty_rollback_is_reversible(): void
    {
        $coop = Coop::create(['name' => 'Legacy']);
        $coop->settings()->create(['minimum_indoor_temperature' => 20, 'config_version' => 8,
            'notification_preferences' => config('monitoring.notification_preferences')]);
        DB::table('coop_lighting_lux_settings')->insert(['coop_id' => $coop->id, 'min_lux' => 10, 'max_lux' => 100, 'config_version' => 4]);
        $before = [DB::table('coop_settings')->get()->toArray(), DB::table('coop_lighting_lux_settings')->get()->toArray()];
        $migration = require database_path('migrations/2026_11_25_000000_create_integration_foundations.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('integration_deliveries'));
        $migration->up();
        $this->assertEquals($before, [DB::table('coop_settings')->get()->toArray(), DB::table('coop_lighting_lux_settings')->get()->toArray()]);
        $this->assertDatabaseCount('integration_deliveries', 0);
    }

    public function test_populated_integration_history_refuses_lossy_rollback(): void
    {
        $coop = Coop::create(['name' => 'Session']);
        $device = $coop->devices()->create(['name' => 'ESP32', 'device_identifier' => 'TEST', 'type' => 'esp32']);
        DB::table('integration_sessions')->insert(['coop_id' => $coop->id, 'device_id' => $device->id, 'boot_id' => (string) Str::uuid()]);
        $migration = require database_path('migrations/2026_11_25_000000_create_integration_foundations.php');
        try {
            $migration->down();
            $this->fail('Lossy rollback accepted.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('forward migration', $error->getMessage());
        }
        $this->assertDatabaseCount('integration_sessions', 1);
        $this->assertTrue(Schema::hasTable('push_tokens'));
    }
}
