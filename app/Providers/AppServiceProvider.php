<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useBootstrapFive();
        RateLimiter::for('customs-api', function (Request $request) {
            $token = $request->user()?->currentAccessToken();
            $key = $token instanceof PersonalAccessToken
                ? 'token:'.$token->getKey()
                : 'user:'.($request->user()?->getAuthIdentifier() ?? $request->ip());

            return Limit::perMinute(30)->by('customs-api:'.$key);
        });

        // Administrators can consult postal data. Operators need an explicit
        // read permission; write/delivery abilities remain separately controlled.
        Gate::define('postal.ips', fn ($user) => $user->hasRole('admin') || $user->checkPermissionTo('ips.read', 'web'));
        Gate::define('postal.cds', fn ($user) => $user->hasRole('admin') || $user->checkPermissionTo('cds.read', 'web'));
        Gate::define('postal.access', fn ($user) => $user->can('postal.ips') || $user->can('postal.cds'));
        Gate::define('customs.read', fn ($user) => $user->hasRole('admin')
            || $user->can('postal.cds')
            || $user->checkPermissionTo('customs.read', 'web')
            || $user->checkPermissionTo('customs.process', 'web'));
        Gate::define('customs.process', fn ($user) => $user->hasRole('admin') || $user->checkPermissionTo('customs.process', 'web'));
        Gate::define('customs.prealerts.read', fn ($user) => $user->hasRole('admin')
            || $user->checkPermissionTo('customs.read', 'web')
            || $user->checkPermissionTo('customs.process', 'web')
            || $user->can('postal.cds'));

        foreach (['ips.read', 'ips.create', 'ips.events', 'ips.deliver', 'ips.operations'] as $ability) {
            Gate::define($ability, function ($user) use ($ability) {
                return $user->checkPermissionTo($ability, 'web');
            });
        }

        Gate::define('admin-only', function ($user) {
            return method_exists($user, 'hasRole') && $user->hasRole('admin');
        });
    }
}
