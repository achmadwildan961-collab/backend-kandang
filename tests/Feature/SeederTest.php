<?php

namespace Tests\Feature;

use App\Models\SensorReading;
use App\Models\SystemEvent;
use App\Models\User;
use App\Services\SensorAggregationService;
use Database\Seeders\DatabaseSeeder;
use Tests\ApiTestCase;

class SeederTest extends ApiTestCase
{
    public function test_development_seed_matches_frontend_and_reruns_without_duplicates(): void
    {
        config(['monitoring.seed' => ['admin_email' => 'seed-admin@example.com', 'admin_password' => 'Seed-admin-password!', 'user_email' => 'seed-user@example.com', 'user_password' => 'Seed-user-password!']]);
        $this->seed(DatabaseSeeder::class);
        $data = app(SensorAggregationService::class)->latest($this->coop);
        $this->assertSame(30.38, $data['indoor_temperature']['average']);
        $this->assertTrue($data['indoor_temperature']['complete']);
        $this->assertSame(2, $this->coop->devices()->count());
        $this->assertTrue($this->coop->users()->where('email', 'seed-user@example.com')->exists());
        $counts = [SensorReading::count(), SystemEvent::count(), User::count()];
        $this->seed(DatabaseSeeder::class);
        $this->assertSame($counts, [SensorReading::count(), SystemEvent::count(), User::count()]);
    }

    public function test_development_seeder_refuses_production(): void
    {
        $this->app['env'] = 'production';
        $this->expectException(\RuntimeException::class);
        app(DatabaseSeeder::class)->run();
    }
}
