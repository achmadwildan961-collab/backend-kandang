<?php

namespace Tests\Unit;

use App\Services\LightingDemandPreview;
use PHPUnit\Framework\TestCase;

class LightingDemandPreviewTest extends TestCase
{
    public function test_hysteresis_strict_boundaries_retain_previous_state_without_relay_commands(): void
    {
        foreach ([false, true] as $previous) {
            foreach ([[9, true], [10, $previous], [55, $previous], [100, $previous], [101, false]] as [$lux, $expected]) {
                $result = (new LightingDemandPreview)->evaluate($lux, 10, 100, $previous, 'auto', true);
                $this->assertTrue($result['available']);
                $this->assertSame($expected, $result['lighting_demand']);
                $this->assertFalse($result['automation_enabled']);
                $this->assertNull($result['actuator_commands']);
                $this->assertSame('lighting_lamp', $result['actuator_identifier']);
            }
        }
    }

    public function test_unknown_stale_wrong_unit_invalid_unconfigured_and_manual_never_invent_an_off_state(): void
    {
        foreach ([[null, 10, 100, false, 'auto', true], [NAN, 10, 100, false, 'auto', true], [INF, 10, 100, false, 'auto', true],
            [-1, 10, 100, false, 'auto', true], [20, null, null, false, 'auto', true], [20, 100, 100, false, 'auto', true],
            [20, 10, 100, false, 'auto', false], [20, 10, 100, false, 'auto', true, 'adc'],
            [20, 10, 100, null, 'auto', true], [20, 10, 100, true, 'manual', true], [20, 10, 100, true, 'automatic', true]] as $inputs) {
            $result = (new LightingDemandPreview)->evaluate(...$inputs);
            $this->assertFalse($result['available']);
            $this->assertNull($result['lighting_demand']);
            $this->assertNull($result['actuator_commands']);
        }
    }
}
