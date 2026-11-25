<?php

return [
    // No live transport is shipped. Simulation is additionally restricted to tests.
    'simulation' => false,
    'max_attempts' => 3,
    'retry_seconds' => 5,
    'lease_seconds' => 30,
    'ack_seconds' => 60,
    'snapshot_seconds' => 3600,
    'report_seconds' => 60,
    'command_seconds' => 30,
    'display_seconds' => 30,
];
