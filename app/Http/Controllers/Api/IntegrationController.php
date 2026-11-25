<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Coop;
use App\Services\Integration\ControlEngine;
use App\Services\Integration\IntegrationMonitor;
use App\Services\Integration\LcdPayload;
use Illuminate\Support\Facades\Gate;

class IntegrationController extends Controller
{
    public function delivery(Coop $coop, IntegrationMonitor $monitor)
    {
        Gate::authorize('view', $coop);

        return response()->json(['data' => $monitor->deliveries($coop)]);
    }

    public function monitoring(Coop $coop, IntegrationMonitor $monitor, ControlEngine $engine, LcdPayload $lcd)
    {
        Gate::authorize('view', $coop);

        return response()->json(['data' => ['reported_actuators' => $monitor->reports($coop),
            'control_preview' => $engine->preview($coop), 'lcd_preview' => $lcd->generate($coop)]]);
    }

    public function command(Coop $coop)
    {
        Gate::authorize('updateSettings', $coop);

        return response()->json(['code' => 'actuator_commands_unsupported', 'message' => 'Firmware, feedback and safety acceptance are unverified.'], 409);
    }
}
