<?php

namespace Tests\Unit;

use App\Services\Postal\PostalActivityReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostalActivityReportTest extends TestCase
{
    public function test_activity_report_builds_bounded_latest_event_queries_without_writing(): void
    {
        config(['postal.ips_read_connection' => 'sqlite']);
        $connection = DB::connection('sqlite');
        $from = CarbonImmutable::parse('2026-09-24 04:00:00', 'UTC');
        $until = CarbonImmutable::parse('2026-09-26 04:00:00', 'UTC');

        $queries = $connection->pretend(function () use ($from, $until) {
            return app(PostalActivityReport::class)->search($from, $until, ['office'=>'1','event'=>'32','search'=>'EC25772105BE'], 1000);
        });

        $this->assertGreaterThanOrEqual(3, count($queries));
        $sql = strtolower(implode("\n", array_column($queries, 'query')));
        $this->assertStringContainsString('row_number() over (partition by', $sql);
        $this->assertStringContainsString('2026-09-24 04:00:00', $sql);
        $this->assertStringContainsString("event_office_cd\" = '1'", $sql);
        $this->assertStringContainsString("event_type_cd\" = '32'", $sql);
        $this->assertStringContainsString('mi.mailitm_fid', $sql);

        $officeCatalogSql = collect(array_column($queries, 'query'))
            ->map(fn ($query) => strtolower($query))
            ->first(fn ($query) => str_contains($query, 'from "dbo"."n_own_offices" as "office"'));
        $this->assertNotNull($officeCatalogSql, 'The report must load offices from the IPS office catalog.');
        $this->assertStringContainsString('valid_ind', $officeCatalogSql);
        $this->assertStringNotContainsString('l_mailitm_events', $officeCatalogSql);
        $this->assertStringNotContainsString('limit', $officeCatalogSql);
    }
}
