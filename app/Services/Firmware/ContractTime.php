<?php

namespace App\Services\Firmware;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

class ContractTime
{
    public static function parse(string $value): CarbonImmutable
    {
        if (! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z\z/', $value)) {
            throw new InvalidArgumentException('Contract timestamp must be UTC ISO.');
        }
        try {
            $parsed = CarbonImmutable::createFromFormat(str_contains($value, '.') ? '!Y-m-d\TH:i:s.u\Z' : '!Y-m-d\TH:i:s\Z', $value, 'UTC');
            $errors = CarbonImmutable::getLastErrors();
            if (! $parsed || ($errors && ($errors['warning_count'] || $errors['error_count']))) {
                throw new InvalidArgumentException;
            }

            return $parsed;
        } catch (\Throwable) {
            throw new InvalidArgumentException('Invalid contract calendar timestamp.');
        }
    }
}
