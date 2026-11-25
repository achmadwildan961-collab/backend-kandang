<?php

namespace App\Jobs;

use App\Services\Integration\ConfigurationDispatcher;
use App\Services\Push\PushDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DrainIntegrations implements ShouldQueue
{
    use Queueable;

    public int $tries = 1; // Per-delivery retry budgets are persisted in the outboxes.

    public function handle(ConfigurationDispatcher $configurations, PushDispatcher $push): void
    {
        $configurations->run();
        $push->run();
    }
}
