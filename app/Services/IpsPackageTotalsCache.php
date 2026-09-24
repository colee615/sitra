<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class IpsPackageTotalsCache
{
    public function remember(array $filters, callable $count): int
    {
        $ttl = max(0, (int) config('ips.totals_cache_seconds', 30));
        // Exact searches and all operational data are always read directly from IPS.
        if ($ttl === 0 || ! empty($filters['q'])) {
            return (int) $count();
        }
        $scope = array_intersect_key($filters, array_flip(['status', 'office_cd', 'event_cd', 'from', 'to']));
        $scope = array_filter($scope, fn ($value) => $value !== null && $value !== '');
        $scope['status'] = $scope['status'] ?? 'all';
        foreach (['office_cd', 'event_cd'] as $name) {
            if (isset($scope[$name])) {
                $scope[$name] = (int) $scope[$name];
            }
        }
        ksort($scope);
        try {
            $cache = Cache::store(config('ips.totals_cache_store'));
            $namespace = $this->namespace();
            $version = $cache->get($namespace.':version', 'initial');
            $key = $namespace.':'.$version.':'.hash('sha256', json_encode($scope, JSON_THROW_ON_ERROR));
            $cached = $cache->get($key);
        } catch (Throwable $e) {
            Log::warning('IPS totals cache unavailable', ['exception_type' => $e::class]);

            return (int) $count();
        }
        if ($cached !== null) {
            return (int) $cached;
        }
        $total = (int) $count();
        try {
            $cache->put($key, $total, $ttl);
        } catch (Throwable $e) {
            Log::warning('IPS total could not be cached', ['exception_type' => $e::class]);
        }

        return $total;
    }

    public function invalidate(): void
    {
        // In-flight counts stay in the old generation and cannot repopulate current totals.
        Cache::store(config('ips.totals_cache_store'))->forever($this->namespace().':version', (string) Str::uuid());
    }

    private function namespace(): string
    {
        $connection = config('database.connections.'.config('ips.connection'), []);
        $source = array_intersect_key($connection, array_flip(['driver', 'host', 'port', 'database', 'prefix', 'url']));

        return 'ips:package-totals:v1:'.hash('sha256', json_encode([
            $source, config('ips.destination_country'), config('ips.delivery_candidate_events'),
            config('ips.reception_candidate_events'), IpsStagePolicy::TECHNICAL_EVENTS,
        ], JSON_THROW_ON_ERROR));
    }
}
