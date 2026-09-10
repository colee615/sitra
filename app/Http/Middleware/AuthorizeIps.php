<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class AuthorizeIps
{
    public function handle(Request $request, Closure $next, string $ability)
    {
        abort_unless($request->user()?->hasRole('admin'), 403);
        abort_unless($request->bearerToken() && $request->user()->currentAccessToken() instanceof PersonalAccessToken, 403);
        abort_unless($request->user()->tokenCan($ability), 403, 'El token no tiene el permiso requerido.');

        return $next($request);
    }
}
