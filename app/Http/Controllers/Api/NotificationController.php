<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\NotificationQueryRequest;
use App\Http\Resources\NotificationResource;
use App\Models\Coop;
use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    private function owned(Request $request)
    {
        return $request->user()->notifications()->where(fn ($q) => $q->whereNull('coop_id')->orWhereIn('coop_id', Coop::visibleTo($request->user())->select('id')));
    }

    public function index(NotificationQueryRequest $request)
    {
        $base = $this->owned($request);
        $unread = (clone $base)->whereNull('read_at')->count();
        if ($request->input('filter') === 'unread') {
            $base->whereNull('read_at');
        }
        if ($request->input('filter') === 'read') {
            $base->whereNotNull('read_at');
        }

        return NotificationResource::collection($base->orderByDesc('created_at')->orderByDesc('id')->paginate($request->integer('per_page', 20))->withQueryString())->additional(['unread_count' => $unread]);
    }

    public function read(Request $request, Notification $notification)
    {
        $item = $this->owned($request)->whereKey($notification->id)->firstOrFail();
        if (! $item->read_at) {
            $item->update(['read_at' => now()]);
        }

        return new NotificationResource($item);
    }

    public function readAll(Request $request)
    {
        $count = $this->owned($request)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['data' => ['updated_count' => $count, 'unread_count' => 0]]);
    }
}
