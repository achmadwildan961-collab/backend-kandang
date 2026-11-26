<?php

use App\Services\ControlDemandPreview;
use App\Services\Firmware\ContractSchema;
use App\Services\Integration\CanonicalPayload;
use App\Services\LightingDemandPreview;
use App\Services\Mqtt\PayloadValidator;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;

// Pure validators only. No database, MQTT, push, scheduler or application writes.
putenv('APP_ENV=testing');
require dirname(__DIR__, 2).'/vendor/autoload.php';
// Only the container/helpers needed by these pure services. Do not load .env,
// application providers, operational database/cache settings or command boot.
$app = new Application(dirname(__DIR__, 2));
$app->instance('config', new Repository(['mqtt' => [
    'max_payload_bytes' => 16384, 'future_tolerance_seconds' => 30,
]]));
Facade::setFacadeApplication($app);
while (($line = fgets(STDIN)) !== false) {
    try {
        $request = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        Carbon\Carbon::setTestNow('2026-11-25T08:00:00Z');
        if (($request['mode'] ?? '') === 'canonical') {
            $payload = $request['payload'];
            unset($payload['payload_hash']);
            $canonical = CanonicalPayload::encode($payload);
            $payload['payload_hash'] = hash('sha256', $canonical);
            echo json_encode(['payload' => $payload, 'wire' => CanonicalPayload::encode($payload)], JSON_THROW_ON_ERROR).PHP_EOL;

            continue;
        }
        if (($request['mode'] ?? '') === 'control') {
            $p = $request['payload'];
            $gas = ($p['verified'] ?? false) && is_numeric($p['gas'] ?? null) && is_numeric($p['threshold'] ?? null)
                ? $p['gas'] > $p['threshold'] : null;
            $thermal = (new ControlDemandPreview)->evaluate($p['average'] ?? null, $p['min'] ?? null, $p['max'] ?? null, $gas);
            $lighting = (new LightingDemandPreview)->evaluate($p['lux'] ?? null, $p['min_lux'] ?? null, $p['max_lux'] ?? null,
                $p['previous'] ?? null, $p['lighting_mode'] ?? '', $p['fresh'] ?? true);
            echo json_encode(['heater' => $thermal['temperature_heating_demand'], 'fans' => $gas === true ? true : $thermal['ventilation_demand'],
                'light' => $lighting['lighting_demand']], JSON_THROW_ON_ERROR).PHP_EOL;

            continue;
        }
        $schema = $request['schema'];
        $payload = $request['payload'];
        (new ContractSchema)->validate($schema, $payload);
        if (in_array($schema, ['telemetry', 'status'], true)) {
            (new PayloadValidator)->validate($request['wire'] ?? json_encode($payload, JSON_THROW_ON_ERROR), $schema);
        }
        echo json_encode(['ok' => true]).PHP_EOL;
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]).PHP_EOL;
    }
}
