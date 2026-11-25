<?php

namespace Tests\Unit;

use App\Services\Firmware\ContractTime;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class FirmwareTimeTest extends TestCase
{
    public function test_valid_utc_whole_and_fractional_seconds_are_exact(): void
    {
        $this->assertSame('2026-11-25T08:00:00.000000Z', ContractTime::parse('2026-11-25T08:00:00Z')->toISOString());
        $this->assertSame('2026-11-25T08:00:00.123456Z', ContractTime::parse('2026-11-25T08:00:00.123456Z')->toISOString());
    }

    public function test_invalid_calendar_offset_and_precision_are_never_normalized_silently(): void
    {
        foreach (['2026-02-30T08:00:00Z', '2026-11-25T25:00:00Z', '2026-11-25T08:00:00+00:00',
            '2026-11-25T08:00:00.1234567Z', 'tomorrow', '2026-11-25T08:00:60Z'] as $value) {
            try {
                ContractTime::parse($value);
                $this->fail('Invalid timestamp accepted.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }
}
