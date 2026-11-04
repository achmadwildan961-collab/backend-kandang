<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PaginationRequest;
use App\Http\Requests\StatusRequest;
use App\Http\Requests\UserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    public function index(PaginationRequest $request)
    {
        return UserResource::collection(User::where('role', 'user')->orderBy('id')->paginate($request->integer('per_page', 20))->withQueryString());
    }

    public function store(UserRequest $request)
    {
        return (new UserResource(User::create($request->safe()->only(['name', 'email', 'phone', 'password', 'status']) + ['role' => 'user'])))->response()->setStatusCode(201);
    }

    public function show(User $user)
    {
        abort_unless($user->role === 'user', 403, 'Only regular users can be managed here.');

        return new UserResource($user);
    }

    public function update(UserRequest $request, User $user)
    {
        return $this->save($user, $request->safe()->only(['name', 'email', 'phone', 'status']));
    }

    public function status(StatusRequest $request, User $user)
    {
        return $this->save($user, $request->safe()->only(['status']));
    }

    private function save(User $user, array $data)
    {
        return DB::transaction(function () use ($user, $data) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->role === 'user', 403, 'Only regular users can be managed here.');
            $user->update($data);
            if ($user->status === 'inactive') {
                $user->tokens()->delete();
            }

            return new UserResource($user);
        });
    }
}
