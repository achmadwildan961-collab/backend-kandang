<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\HistoryRequest;
use App\Http\Resources\SystemEventResource;
use App\Models\Coop;
use Illuminate\Support\Facades\Gate;

class EventController extends Controller
{
    public function index(HistoryRequest $request, Coop $coop)
    {
        Gate::authorize('view', $coop);
        [$from,$to] = $request->dateBounds();
        $query = $coop->events()->whereBetween('occurred_at', [$from, $to]);
        if ($request->filled('event_type')) {
            $query->where('event_type', $request->validated('event_type'));
        }

        return SystemEventResource::collection($query->orderByDesc('occurred_at')->orderByDesc('id')->paginate($request->integer('per_page', 20))->withQueryString());
    }
}
