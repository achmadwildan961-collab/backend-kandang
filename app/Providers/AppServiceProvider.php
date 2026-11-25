<?php

namespace App\Providers;

use App\Services\Integration\DisabledMqttTransport;
use App\Services\Integration\MqttTransport;
use App\Services\Push\DisabledPushProvider;
use App\Services\Push\PushProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MqttTransport::class, DisabledMqttTransport::class);
        $this->app->bind(PushProvider::class, DisabledPushProvider::class);
    }

    public function boot(): void
    {
        RateLimiter::for('login', fn (Request $r) => [
            Limit::perMinute(5)->by(strtolower((string) $r->input('email')).'|'.$r->ip()),
            Limit::perMinute(30)->by($r->ip()),
        ]);
    }
}
