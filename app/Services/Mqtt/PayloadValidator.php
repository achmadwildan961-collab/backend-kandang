<?php

namespace App\Services\Mqtt;

use App\Services\SensorCatalog;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use JsonException;

class PayloadValidator
{
    public function validate(string $payload, string $kind): array
    {
        if (strlen($payload) > config('mqtt.max_payload_bytes')) {
            throw new InvalidArgumentException('Payload exceeds size limit.');
        }
        try {
            $data = json_decode($payload, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Invalid JSON.');
        }
        $fields = ['message_id', 'sent_at', $kind === 'telemetry' ? 'readings' : 'status'];
        if (! is_array($data) || count($data) !== 3 || array_diff(array_keys($data), $fields)) {
            throw new InvalidArgumentException('Invalid message fields.');
        }
        if (! is_string($data['message_id']) || ! preg_match('/\A[A-Za-z0-9_.:-]{1,128}\z/', $data['message_id'])) {
            throw new InvalidArgumentException('Invalid message ID.');
        }
        $time = $data['sent_at'];
        if (! is_string($time) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z\z/', $time)) {
            throw new InvalidArgumentException('sent_at must be a UTC ISO timestamp.');
        }
        $format = str_contains($time, '.') ? '!Y-m-d\TH:i:s.u\Z' : '!Y-m-d\TH:i:s\Z';
        try {
            $sent = CarbonImmutable::createFromFormat($format, $time, 'UTC');
            $errors = CarbonImmutable::getLastErrors();
            if (! $sent || ($errors && ($errors['warning_count'] || $errors['error_count']))) {
                throw new InvalidArgumentException;
            }
        } catch (\Exception) {
            throw new InvalidArgumentException('Invalid timestamp.');
        }
        if ($sent->lt('1970-01-01T00:00:01Z') || $sent->gt('2038-01-19T03:14:07Z') || $sent->gt(now()->addSeconds(max(0, config('mqtt.future_tolerance_seconds'))))) {
            throw new InvalidArgumentException('Timestamp outside accepted range.');
        }
        $data['sent_at'] = $sent;
        if ($kind === 'status') {
            if (! in_array($data['status'], ['online', 'offline'], true)) {
                throw new InvalidArgumentException('Invalid heartbeat status.');
            }

            return $data;
        }
        if (! is_array($data['readings']) || ! array_is_list($data['readings']) || count($data['readings']) < 1 || count($data['readings']) > count(SensorCatalog::series())) {
            throw new InvalidArgumentException('Invalid readings list.');
        }
        $seen = [];
        foreach ($data['readings'] as &$reading) {
            if (! is_array($reading) || count($reading) !== 2 || ! isset($reading['sensor_identifier'], $reading['value'])) {
                throw new InvalidArgumentException('Invalid reading fields.');
            }
            $id = $reading['sensor_identifier'];
            $spec = is_string($id) ? (SensorCatalog::series()[$id] ?? null) : null;
            $value = $reading['value'];
            if (! $spec || isset($seen[$id]) || (! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || $value < $spec['min'] || $value > $spec['max']) {
                throw new InvalidArgumentException('Unknown, duplicate, or invalid sensor reading.');
            }
            $seen[$id] = true;
            $reading = ['sensor_identifier' => $id, 'sensor_type' => $spec['sensor_type'], 'unit' => $spec['unit'], 'value' => $value];
        }
        unset($reading);

        return $data;
    }
}
