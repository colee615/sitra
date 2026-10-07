<?php

namespace App\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class TrackingDestinationCacheService
{
    private const CACHE_HOURS = 24;
    private const LOCK_SECONDS = 30;
    private const LOCK_WAIT_SECONDS = 12;
    private const CACHE_READ_CHUNK = 400;
    private const CACHE_WRITE_CHUNK = 250;

    public function __construct(private readonly SqlServerSearchService $searchService) {}

    /**
     * Resolve a code batch from the shared cache and only query SQL Server for
     * uncached codes. The batch lock coalesces identical concurrent requests.
     *
     * @param  array<int, string>  $codes
     * @return array<string, array<string, mixed>>
     */
    public function destinations(array $codes): array
    {
        $codes = collect($codes)
            ->map(fn ($code) => strtoupper(trim((string) $code)))
            ->filter()
            ->unique()
            ->sort()
            ->values();

        if ($codes->isEmpty()) {
            return [];
        }

        $cacheKeys = $codes->mapWithKeys(fn (string $code) => [$code => $this->cacheKey($code)])->all();
        $cached = $this->readCacheMany(array_values($cacheKeys));
        if ($this->allCached($codes, $cacheKeys, $cached)) {
            return $this->formatResults($codes, $cacheKeys, $cached);
        }

        $lockKey = 'tracking:destinations:batch-lock:v1:'.sha1(implode("\n", $codes->all()));

        try {
            return Cache::lock($lockKey, self::LOCK_SECONDS)->block(
                self::LOCK_WAIT_SECONDS,
                fn () => $this->resolveAfterLock($codes, $cacheKeys)
            );
        } catch (LockTimeoutException) {
            // If the shared lock backend is slow, preserve availability and
            // perform one fallback lookup for this request.
            $cached = $this->readCacheMany(array_values($cacheKeys));
            return $this->resolveMissing($codes, $cacheKeys, $cached);
        }
    }

    private function resolveAfterLock(Collection $codes, array $cacheKeys): array
    {
        $cached = $this->readCacheMany(array_values($cacheKeys));

        return $this->allCached($codes, $cacheKeys, $cached)
            ? $this->formatResults($codes, $cacheKeys, $cached)
            : $this->resolveMissing($codes, $cacheKeys, $cached);
    }

    private function resolveMissing(Collection $codes, array $cacheKeys, array $cached): array
    {
        $missing = $codes
            ->filter(fn (string $code) => ! is_array($cached[$cacheKeys[$code]] ?? null))
            ->values();

        if ($missing->isNotEmpty()) {
            $resolved = $this->searchService->searchManyDestinations($missing->all());
            $toCache = [];

            foreach ($missing as $code) {
                $toCache[$cacheKeys[$code]] = $resolved[$code] ?? $this->emptyDestination($code);
            }

            foreach (array_chunk($toCache, self::CACHE_WRITE_CHUNK, true) as $chunk) {
                Cache::putMany($chunk, now()->addHours(self::CACHE_HOURS));
            }

            $cached = array_merge($cached, $toCache);
        }

        return $this->formatResults($codes, $cacheKeys, $cached);
    }

    private function allCached(Collection $codes, array $cacheKeys, array $cached): bool
    {
        foreach ($codes as $code) {
            if (! is_array($cached[$cacheKeys[$code]] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function formatResults(Collection $codes, array $cacheKeys, array $cached): array
    {
        $results = [];

        foreach ($codes as $code) {
            $results[$code] = is_array($cached[$cacheKeys[$code]] ?? null)
                ? $cached[$cacheKeys[$code]]
                : $this->emptyDestination($code);
        }

        return $results;
    }

    private function emptyDestination(string $code): array
    {
        return [
            'codigo' => $code,
            'ciudad' => null,
            'pais' => null,
            'pais_codigo' => null,
            'destino' => null,
        ];
    }

    private function cacheKey(string $code): string
    {
        return 'tracking:destination:v1:'.sha1($code);
    }

    private function readCacheMany(array $keys): array
    {
        $cached = [];
        foreach (array_chunk($keys, self::CACHE_READ_CHUNK) as $keyChunk) {
            $cached = array_merge($cached, Cache::many($keyChunk));
        }

        return $cached;
    }
}
