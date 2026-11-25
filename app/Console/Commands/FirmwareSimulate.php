<?php

namespace App\Console\Commands;

use App\Services\Firmware\VirtualDevice;
use Illuminate\Console\Command;

class FirmwareSimulate extends Command
{
    protected $signature = 'firmware:simulate {board : indoor or outdoor} {--scenario=complete}';

    protected $description = 'Preview virtual firmware telemetry without database or broker access';

    public function handle(): int
    {
        try {
            $board = $this->argument('board');
            if (! in_array($board, ['indoor', 'outdoor'], true)) {
                throw new \InvalidArgumentException('Choose indoor or outdoor.');
            }
            $virtual = new VirtualDevice(1, 'VIRTUAL_'.strtoupper($board), $board === 'indoor' ? 'esp32' : 'nodemcu_esp8266');
            $this->line(json_encode(['simulated' => true, 'physical_feedback' => false, 'topic' => $virtual->topic('telemetry'),
                'qos' => 1, 'retained' => false, 'payload' => $virtual->telemetry($this->option('scenario'))], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
