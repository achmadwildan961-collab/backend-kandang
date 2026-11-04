<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureActive
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user()?->status !== 'active') {
            $request->user()?->tokens()->delete();
            abort(403, 'This account is inactive.');
        }

        return $next($request);
    }
}
