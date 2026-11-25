<?php

namespace App\Services\Integration;

class CanonicalPayload
{
    // JSON columns may reorder object keys (notably MySQL). Hash semantic payloads,
    // recursively sorting object keys while preserving ordered lists.
    public static function encode(array $payload): string
    {
        return json_encode(self::normalize($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private static function normalize(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::normalize($item);
            }
        }

        return $value;
    }
}
