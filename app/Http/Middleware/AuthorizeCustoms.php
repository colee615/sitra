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
        $prealertsAbility = $ability === 'customs.prealerts.read';
        $accountAllowed = $user && ($user->hasRole('admin')
            || $user->checkPermissionTo('customs.read', 'web')
            || $user->checkPermissionTo('customs.process', 'web')
            || ($prealertsAbility && $user->can('postal.cds')));
        abort_unless($accountAllowed, 403);
        abort_unless($request->bearerToken() && $user->currentAccessToken() instanceof PersonalAccessToken, 403);
        $tokenAllowed = $user->tokenCan($ability)
            || $user->tokenCan('customs.read')
            || $user->tokenCan('customs.process');
        abort_unless($tokenAllowed, 403,
            'El token no tiene permiso para esta operación aduanera.');

        return $next($request);
    }
}
