<?php

namespace App\Console\Commands;

use App\Services\Mqtt\DeviceStatusService;
use Illuminate\Console\Command;

class MqttCheckDevices extends Command
{
    protected $signature = 'mqtt:check-devices';

    protected $description = 'Mark devices offline after the configured heartbeat timeout';

    public function handle(DeviceStatusService $devices): int
    {
        $this->info('Device status changes: '.$devices->check());

        return self::SUCCESS;
    }
}
