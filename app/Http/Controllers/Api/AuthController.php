<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request)
    {
        return DB::transaction(function () use ($request) {
            $user = User::where('email', $request->validated('email'))->lockForUpdate()->first();
            if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
                throw ValidationException::withMessages(['email' => ['The provided credentials are incorrect.']]);
            }
            abort_unless($user->status === 'active', 403, 'This account is inactive.');
            $token = $user->createToken($request->validated('device_name'), ['*'], now()->addMinutes(config('sanctum.expiration') ?: 43200));

            return response()->json(['data' => ['token' => $token->plainTextToken, 'token_type' => 'Bearer', 'user' => new UserResource($user)]]);
        });
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['data' => null, 'message' => 'Logged out.']);
    }
}
