<?php

namespace App\Services\Firmware;

use InvalidArgumentException;

/** Offline validator for the explicitly supported JSON Schema subset in this repo. */
class ContractSchema
{
    public function validate(string $name, mixed $payload): void
    {
        if (! preg_match('/\A[a-z-]+\z/', $name)) {
            throw new InvalidArgumentException('Unknown schema name.');
        }
        $schema = json_decode(file_get_contents(base_path("contracts/firmware/schemas/$name.json")), true, flags: JSON_THROW_ON_ERROR);
        $this->check($schema, $payload);
    }

    public function check(array $schema, mixed $value, string $path = '$'): void
    {
        $known = ['$schema', 'title', 'description', 'type', 'properties', 'required', 'additionalProperties', 'items', 'minItems', 'maxItems', 'minimum', 'maximum', 'pattern', 'enum', 'const', 'anyOf'];
        $this->require(array_diff(array_keys($schema), $known) === [], "$path unsupported schema keyword");
        if (isset($schema['anyOf'])) {
            foreach ($schema['anyOf'] as $choice) {
                try {
                    $this->check($choice, $value, $path);

                    return;
                } catch (InvalidArgumentException) {
                }
            }
            throw new InvalidArgumentException("$path does not match any schema choice");
        }
        if (array_key_exists('const', $schema)) {
            $this->require($value === $schema['const'], "$path constant mismatch");
        }
        if (isset($schema['enum'])) {
            $this->require(in_array($value, $schema['enum'], true), "$path enum mismatch");
        }
        $types = (array) ($schema['type'] ?? []);
        $matches = array_filter($types, fn ($type) => match ($type) {
            'object' => is_array($value) && (! array_is_list($value) || $value === []),
            'array' => is_array($value) && array_is_list($value),
            'integer' => is_int($value),
            'number' => (is_int($value) || is_float($value)) && is_finite((float) $value),
            'string' => is_string($value), 'boolean' => is_bool($value), 'null' => $value === null,
            default => throw new InvalidArgumentException('Unsupported schema type'),
        });
        $this->require($types === [] || $matches !== [], "$path type mismatch");
        if (is_array($value) && in_array('object', $types, true)) {
            $this->require(array_diff($schema['required'] ?? [], array_keys($value)) === [], "$path missing fields");
            if (($schema['additionalProperties'] ?? true) === false) {
                $this->require(array_diff(array_keys($value), array_keys($schema['properties'] ?? [])) === [], "$path unknown fields");
            }
            foreach ($schema['properties'] ?? [] as $key => $child) {
                if (array_key_exists($key, $value)) {
                    $this->check($child, $value[$key], "$path.$key");
                }
            }
        }
        if (is_array($value) && in_array('array', $types, true)) {
            $this->require(count($value) >= ($schema['minItems'] ?? 0) && count($value) <= ($schema['maxItems'] ?? PHP_INT_MAX), "$path item count");
            foreach ($value as $index => $item) {
                if (isset($schema['items'])) {
                    $this->check($schema['items'], $item, "$path.$index");
                }
            }
        }
        if (is_string($value) && isset($schema['pattern'])) {
            $this->require(preg_match('~'.$schema['pattern'].'~D', $value) === 1, "$path string pattern");
        }
        if (is_int($value) || is_float($value)) {
            $this->require($value >= ($schema['minimum'] ?? -INF) && $value <= ($schema['maximum'] ?? INF), "$path number range");
        }
    }

    private function require(bool $ok, string $message): void
    {
        if (! $ok) {
            throw new InvalidArgumentException($message);
        }
    }
}
