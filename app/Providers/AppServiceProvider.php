<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

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

        // Administrators can consult postal data. Operators need an explicit
        // read permission; write/delivery abilities remain separately controlled.
        Gate::define('postal.ips', fn ($user) => $user->hasRole('admin') || $user->checkPermissionTo('ips.read', 'web'));
        Gate::define('postal.cds', fn ($user) => $user->hasRole('admin') || $user->checkPermissionTo('cds.read', 'web'));
        Gate::define('postal.access', fn ($user) => $user->can('postal.ips') || $user->can('postal.cds'));

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
