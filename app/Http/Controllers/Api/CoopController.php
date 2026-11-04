<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignmentRequest;
use App\Http\Requests\CoopRequest;
use App\Http\Requests\PaginationRequest;
use App\Http\Resources\CoopResource;
use App\Models\Coop;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CoopController extends Controller
{
    public function index(PaginationRequest $request)
    {
        return CoopResource::collection(Coop::visibleTo($request->user())->orderBy('id')->paginate($request->integer('per_page', 20))->withQueryString());
    }

    public function show(Coop $coop)
    {
        Gate::authorize('view', $coop);

        return new CoopResource($coop);
    }

    public function store(CoopRequest $request)
    {
        Gate::authorize('create', Coop::class);
        $coop = DB::transaction(function () use ($request) {
            $coop = Coop::create($request->validated());
            $coop->settings()->create(['notification_preferences' => config('monitoring.notification_preferences')]);

            return $coop;
        });

        return (new CoopResource($coop))->response()->setStatusCode(201);
    }

    public function update(CoopRequest $request, Coop $coop)
    {
        Gate::authorize('update', $coop);
        $coop->update($request->validated());

        return new CoopResource($coop);
    }

    public function assignUsers(AssignmentRequest $request, Coop $coop)
    {
        Gate::authorize('update', $coop);
        $coop->users()->sync($request->validated('user_ids'));

        return (new CoopResource($coop))->additional(['user_ids' => $coop->users()->pluck('users.id')]);
    }
}
