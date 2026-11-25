<?php

namespace Tests\Unit;

use App\Services\ControlDemandPreview;
use PHPUnit\Framework\TestCase;

class ControlDemandPreviewTest extends TestCase
{
    public function test_temperature_boundaries_and_gas_priority_never_issue_actuator_commands(): void
    {
        $preview = new ControlDemandPreview;
        foreach ([[23, true, false], [24, false, false], [27, false, false], [30, false, false], [31, false, true]] as [$temperature, $heater, $fan]) {
            foreach ([false, true] as $gas) {
                // Test numbers are fixtures, not safe operating recommendations.
                $result = $preview->evaluate($temperature, 24, 30, $gas);
                $this->assertTrue($result['available']);
                $this->assertSame($heater, $result['temperature_heating_demand']);
                $this->assertSame($fan, $result['temperature_ventilation_demand']);
                $this->assertSame($fan || $gas, $result['ventilation_demand']);
                $this->assertFalse($result['automation_enabled']);
                $this->assertNull($result['actuator_commands']);
                $this->assertNull($result['lighting_lamp']);
            }
        }
    }

    public function test_unknown_invalid_and_unconfigured_inputs_remain_unavailable_not_off(): void
    {
        foreach ([[null, 24, 30, true], [NAN, 24, 30, false], [INF, 24, 30, false], [28, null, 30, false],
            [28, 24, null, false], [28, 30, 30, false], [28, 31, 30, false], [28, 24, 30, null]] as $input) {
            $result = (new ControlDemandPreview)->evaluate(...$input);
            $this->assertFalse($result['available']);
            $this->assertNull($result['ventilation_demand']);
            $this->assertNull($result['temperature_heating_demand']);
            $this->assertNull($result['actuator_commands']);
        }
    }
}
