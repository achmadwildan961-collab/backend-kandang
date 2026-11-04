<?php

namespace Database\Seeders;

use App\Models\Coop;
use App\Models\User;
use App\Services\AlertEvaluationService;
use App\Services\SensorCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Development seeding is only allowed in local/testing environments.');
        }
        $seed = config('monitoring.seed');
        Validator::make($seed, [
            'admin_email' => 'required|email|different:user_email', 'user_email' => 'required|email',
            'admin_password' => 'required|string|min:12', 'user_password' => 'required|string|min:12',
        ])->validate();
        DB::transaction(function () use ($seed) {
            $admin = User::firstOrCreate(['email' => strtolower($seed['admin_email'])], ['name' => 'Development Admin', 'password' => $seed['admin_password'], 'role' => 'admin', 'status' => 'active']);
            $user = User::firstOrCreate(['email' => strtolower($seed['user_email'])], ['name' => 'Development User', 'password' => $seed['user_password'], 'role' => 'user', 'status' => 'active']);
            if (! $admin->isAdmin() || $user->isAdmin()) {
                throw new \RuntimeException('Development emails conflict with existing roles.');
            }
            $coop = Coop::firstOrCreate(['name' => 'Kandang Utama'], ['location' => 'Bandung', 'description' => 'Local development coop']);
            $coop->users()->syncWithoutDetaching([$user->id]);
            $coop->settings()->firstOrCreate([], ['notification_preferences' => config('monitoring.notification_preferences')]);
            $a = $coop->devices()->firstOrCreate(['device_identifier' => 'ESP32_DEVICE_A'], ['name' => 'ESP32 Device A', 'type' => 'esp32', 'connection_status' => 'online', 'last_seen_at' => now()]);
            $b = $coop->devices()->firstOrCreate(['device_identifier' => 'ESP32_DEVICE_B'], ['name' => 'ESP32 Device B', 'type' => 'esp32', 'connection_status' => 'online', 'last_seen_at' => now()]);
            if ($coop->readings()->exists()) {
                return;
            } // Reruns preserve existing readings and history.
            $now = now()->toImmutable();
            $rows = [];
            $append = function ($time, array $temperatures, float $outdoor, float $humidity, int $gas) use (&$rows, $coop, $a, $b) {
                $values = array_combine(SensorCatalog::indoorIdentifiers(), $temperatures) + ['outdoor_temperature' => $outdoor, 'indoor_humidity' => $humidity, 'outdoor_humidity' => 68, 'gas_adc' => $gas];
                foreach (SensorCatalog::series() as $id => $spec) {
                    $rows[] = [
                        'coop_id' => $coop->id, 'device_id' => in_array($id, SensorCatalog::indoorIdentifiers()) || $id === 'indoor_humidity' ? $a->id : $b->id,
                        'sensor_identifier' => $id, 'sensor_type' => $spec['sensor_type'], 'value' => $values[$id], 'unit' => $spec['unit'], 'recorded_at' => $time,
                    ];
                }
            };
            for ($day = 29; $day >= 0; $day--) {
                for ($hour = 0; $hour < 24; $hour += 6) {
                    $time = $now->startOfDay()->subDays($day)->addHours($hour);
                    if ($time->gte($now)) {
                        continue;
                    }
                    $base = $hour === 12 ? 31.5 : 28 + $hour * .02;
                    $append($time, [$base - .4, $base - .2, $base, $base + .2, $base + .4], $hour === 0 ? 24 : 29, 70 + ($day % 5), $day % 7 === 0 && $hour === 12 ? 1700 : 1200);
                }
            }
            $append($now, [28, 29.2, 31, 33.5, 30.2], 29.2, 72, 1200);
            foreach (array_chunk($rows, 100) as $chunk) {
                $coop->readings()->insert($chunk);
            }
            foreach ([
                ['high_indoor_temperature', 'warning', 31.5, 12],
                ['sensor_recovered', 'info', 28.36, 18],
            ] as [$type,$severity,$value,$hour]) {
                $coop->events()->create([
                    'event_type' => $type, 'severity' => $severity, 'description' => 'Historical development indoor temperature sample.',
                    'metadata' => ['source' => 'indoor', 'value' => $value, 'threshold' => 30, 'unit' => 'celsius'],
                    'occurred_at' => $now->startOfDay()->subDay()->addHours($hour),
                ]);
            }
            app(AlertEvaluationService::class)->evaluate($coop);
        });
    }
}
