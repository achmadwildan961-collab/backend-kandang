<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DeviceRequest;
use App\Http\Requests\PaginationRequest;
use App\Http\Resources\DeviceResource;
use App\Models\Coop;
use App\Models\Device;
use App\Services\AlertEvaluationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DeviceController extends Controller
{
    public function index(PaginationRequest $request, Coop $coop)
    {
        Gate::authorize('view', $coop);

        return DeviceResource::collection($coop->devices()->orderBy('id')->paginate($request->integer('per_page', 20))->withQueryString());
    }

    public function show(Device $device)
    {
        Gate::authorize('view', $device);

        return new DeviceResource($device);
    }

    public function store(DeviceRequest $request, Coop $coop, AlertEvaluationService $alerts)
    {
        Gate::authorize('update', $coop);
        $device = DB::transaction(function () use ($request, $coop, $alerts) {
            Coop::whereKey($coop->id)->lockForUpdate()->firstOrFail();
            $device = $coop->devices()->create($request->safe()->only(['device_identifier', 'name', 'type', 'connection_status']));
            $alerts->evaluate($coop);

            return $device;
        });

        return (new DeviceResource($device))->response()->setStatusCode(201);
    }

    public function update(DeviceRequest $request, Device $device, AlertEvaluationService $alerts)
    {
        Gate::authorize('update', $device);
        DB::transaction(function () use ($request, $device, $alerts) {
            Coop::whereKey($device->coop_id)->lockForUpdate()->firstOrFail();
            $device->update($request->safe()->only(['device_identifier', 'name', 'type', 'connection_status']));
            $alerts->evaluate($device->coop);
        });

        return new DeviceResource($device);
    }
}
