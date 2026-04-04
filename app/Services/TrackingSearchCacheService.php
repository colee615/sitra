<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Throwable;

class TrackingSearchCacheService
{
    public function __construct(
        private readonly SqlServerSearchService $searchService
    ) {
    }

    public function search(string $codigo): array
    {
        $normalizedCode = strtoupper(trim($codigo));

        if ($normalizedCode === '') {
            return [
                'data' => $this->searchService->search($codigo),
                'cache_status' => 'bypass',
                'stale_fallback' => false,
            ];
        }

        $freshKey = $this->freshKey($normalizedCode);
        $staleKey = $this->staleKey($normalizedCode);
        $lockKey = $this->lockKey($normalizedCode);

        $fresh = Cache::get($freshKey);
        if (is_array($fresh)) {
            return [
                'data' => $fresh,
                'cache_status' => 'hit',
                'stale_fallback' => false,
            ];
        }

        if (Cache::add($lockKey, 1, $this->lockSeconds())) {
            try {
                return $this->refreshAndRemember($normalizedCode, 'miss');
            } catch (Throwable $exception) {
                $stale = Cache::get($staleKey);

                if (is_array($stale)) {
                    return [
                        'data' => $stale,
                        'cache_status' => 'stale',
                        'stale_fallback' => true,
                    ];
                }

                throw $exception;
            } finally {
                Cache::forget($lockKey);
            }
        }

        $waitUntil = microtime(true) + ($this->waitMilliseconds() / 1000);

        while (microtime(true) < $waitUntil) {
            usleep($this->waitIntervalMilliseconds() * 1000);

            $fresh = Cache::get($freshKey);
            if (is_array($fresh)) {
                return [
                    'data' => $fresh,
                    'cache_status' => 'coalesced',
                    'stale_fallback' => false,
                ];
            }
        }

        $stale = Cache::get($staleKey);
        if (is_array($stale)) {
            return [
                'data' => $stale,
                'cache_status' => 'stale',
                'stale_fallback' => true,
            ];
        }

        return $this->refreshAndRemember($normalizedCode, 'late-miss');
    }

    private function refreshAndRemember(string $codigo, string $cacheStatus): array
    {
        $result = $this->searchService->search($codigo);

        Cache::put($this->freshKey($codigo), $result, $this->freshTtlSeconds());
        Cache::put($this->staleKey($codigo), $result, $this->staleTtlSeconds());

        return [
            'data' => $result,
            'cache_status' => $cacheStatus,
            'stale_fallback' => false,
        ];
    }

    private function freshKey(string $codigo): string
    {
        return "tracking:search:fresh:{$codigo}";
    }

    private function staleKey(string $codigo): string
    {
        return "tracking:search:stale:{$codigo}";
    }

    private function lockKey(string $codigo): string
    {
        return "tracking:search:lock:{$codigo}";
    }

    private function freshTtlSeconds(): int
    {
        return max(5, (int) config('tracking.cache.fresh_ttl_seconds', 60));
    }

    private function staleTtlSeconds(): int
    {
        return max($this->freshTtlSeconds(), (int) config('tracking.cache.stale_ttl_seconds', 300));
    }

    private function lockSeconds(): int
    {
        return max(5, (int) config('tracking.cache.lock_seconds', 15));
    }

    private function waitMilliseconds(): int
    {
        return max(100, (int) config('tracking.cache.wait_milliseconds', 1500));
    }

    private function waitIntervalMilliseconds(): int
    {
        return max(50, (int) config('tracking.cache.wait_interval_milliseconds', 150));
    }
}
