<?php

namespace Tests\Feature;

use App\Services\IpsPackageTotalsCache;
use Tests\TestCase;

class IpsPackageTotalsCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['ips.totals_cache_seconds' => 30, 'ips.totals_cache_store' => 'array']);
    }

    public function test_pagination_reuses_totals_but_offices_and_filters_are_isolated(): void
    {
        $cache = app(IpsPackageTotalsCache::class);
        $this->assertSame(50, $cache->remember(['office_cd' => 1, 'page' => 1], fn () => 50));
        $this->assertSame(50, $cache->remember(['office_cd' => '1', 'page' => 2, 'per_page' => 10], fn () => 99));
        $this->assertSame(20, $cache->remember(['office_cd' => 2], fn () => 20));
        $this->assertSame(10, $cache->remember(['office_cd' => 1, 'status' => 'pending'], fn () => 10));
        config(['database.connections.sqlsrv.database' => 'AnotherDatabase']);
        $this->assertSame(5, $cache->remember(['office_cd' => 1], fn () => 5));
    }

    public function test_external_changes_are_reflected_after_expiration(): void
    {
        $cache = app(IpsPackageTotalsCache::class);
        $this->assertSame(0, $cache->remember([], fn () => 0));
        $this->assertSame(0, $cache->remember([], fn () => 1));
        $this->travel(31)->seconds();
        $this->assertSame(1, $cache->remember([], fn () => 1));
        $this->travelBack();
    }

    public function test_code_searches_bypass_cached_counts(): void
    {
        $cache = app(IpsPackageTotalsCache::class);
        $this->assertSame(1, $cache->remember(['q' => 'TEST01'], fn () => 1));
        $this->assertSame(0, $cache->remember(['q' => 'TEST01'], fn () => 0));
    }

    public function test_invalidation_during_count_does_not_publish_old_total(): void
    {
        $cache = app(IpsPackageTotalsCache::class);
        $this->assertSame(10, $cache->remember([], function () use ($cache) {
            $cache->invalidate();

            return 10;
        }));
        $this->assertSame(9, $cache->remember([], fn () => 9));
    }

    public function test_cache_outage_does_not_prevent_reading_ips(): void
    {
        config(['ips.totals_cache_store' => 'nonexistent-store']);
        $this->assertSame(12, app(IpsPackageTotalsCache::class)->remember([], fn () => 12));
    }
}
