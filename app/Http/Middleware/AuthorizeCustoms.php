<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class AuthorizeCustoms
{
    public function handle(Request $request, Closure $next, string $ability = 'customs.read')
    {
        $user = $request->user();
        abort_unless($user && ($user->hasRole('admin') || $user->checkPermissionTo($ability, 'web')
            || ($ability === 'customs.read' && $user->checkPermissionTo('customs.process', 'web'))), 403);
        abort_unless($request->bearerToken() && $user->currentAccessToken() instanceof PersonalAccessToken, 403);
        abort_unless($user->tokenCan($ability) || ($ability === 'customs.read' && $user->tokenCan('customs.process')), 403,
            'El token no tiene permiso para esta operación aduanera.');

        return $next($request);
    }
}
