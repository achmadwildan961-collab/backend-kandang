<?php

use App\Jobs\DrainIntegrations;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('mqtt:check-devices')->everyMinute()->withoutOverlapping();

Artisan::command('integration:queue', function () {
    DrainIntegrations::dispatch()->afterCommit();
    $this->info('Integration sweep queued; external transports remain disabled.');
})->purpose('Queue a bounded integration outbox sweep (external delivery disabled)');
