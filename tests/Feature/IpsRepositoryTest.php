<?php

namespace Tests\Feature;

use App\Services\IpsRepository;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IpsRepositoryTest extends TestCase
{
    private IpsRepository $ips;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ips.totals_cache_seconds' => 0]);
        config(['database.connections.ips_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'ips.connection' => 'ips_fixture']);
        $db = DB::connection('ips_fixture');
        $db->statement("ATTACH DATABASE ':memory:' AS dbo");
        $db->statement('CREATE TABLE dbo.L_MAILITMS (MAILITM_PID TEXT, MAILITM_FID TEXT, MAILITM_LOCAL_ID TEXT, MAILITM_WEIGHT NUMERIC, ORIG_COUNTRY_CD TEXT, DEST_COUNTRY_CD TEXT, STATE_IND_CD INTEGER, EVT_TYPE_CD INTEGER, EVT_GMT_DT TEXT, EVT_OFFICE_CD INTEGER, POSTAL_STATUS_CD INTEGER)');
        $db->statement('CREATE TABLE dbo.C_EVENT_TYPES (EVENT_TYPE_CD INTEGER, EVENT_TYPE_NM TEXT)');
        $db->statement('CREATE TABLE dbo.N_OWN_OFFICES (OWN_OFFICE_CD INTEGER, OFFICE_NM TEXT)');
        $db->statement('CREATE TABLE dbo.L_MAILITM_EVENTS (MAILITM_PID TEXT, EVENT_TYPE_CD INTEGER, EVENT_GMT_DT TEXT, EVENT_OFFICE_CD INTEGER, NEXT_OFFICE_CD INTEGER)');
        $db->statement('CREATE TABLE dbo.L_MAILITM_CUSTOMERS (MAILITM_PID TEXT, SENDER_PAYEE_IND TEXT, CUSTOMER_NAME TEXT, CUSTOMER_FORENAME TEXT, CUSTOMER_PHONE_NO TEXT, CUSTOMER_CITY TEXT, CUSTOMER_ADDRESS TEXT)');
        $db->table('dbo.N_OWN_OFFICES')->insert(['OWN_OFFICE_CD' => 1, 'OFFICE_NM' => 'Oficina de prueba']);
        foreach ([32, 37, 61, 74, 75] as $code) {
            $db->table('dbo.C_EVENT_TYPES')->insert(['EVENT_TYPE_CD' => $code, 'EVENT_TYPE_NM' => 'Evento '.$code]);
        }
        foreach ([
            ['PICKUP', 75, 0, 'BO'], ['ROUTE', 74, 0, 'BO'], ['OFFICE', 32, 0, 'BO'],
            ['DELIVERED', 37, 5, 'BO'], ['FOREIGN', 75, 0, 'US'], ['UPDATED', 61, 5, 'BO'],
            ['HISTORIC', 75, 0, 'BO'], ['STOPPED', 75, 0, 'BO'], ['HELD', 75, 1, 'BO'],
        ] as [$code, $event, $state, $country]) {
            $db->table('dbo.L_MAILITMS')->insert([
                'MAILITM_PID' => $code, 'MAILITM_FID' => $code, 'MAILITM_LOCAL_ID' => 'LOCAL-'.$code,
                'MAILITM_WEIGHT' => 1, 'ORIG_COUNTRY_CD' => 'US', 'DEST_COUNTRY_CD' => $country,
                'STATE_IND_CD' => $state, 'EVT_TYPE_CD' => $event, 'EVT_GMT_DT' => '2026-01-01 12:00:00', 'EVT_OFFICE_CD' => 1,
            ]);
        }
        $db->table('dbo.L_MAILITM_EVENTS')->insert([
            ['MAILITM_PID' => 'HISTORIC', 'EVENT_TYPE_CD' => 37],
            ['MAILITM_PID' => 'STOPPED', 'EVENT_TYPE_CD' => 76],
        ]);
        $this->ips = new IpsRepository;
    }

    public function test_pending_excludes_terminal_history_customs_and_foreign_destination(): void
    {
        $result = $this->ips->packages(['status' => 'pending']);
        $this->assertEqualsCanonicalizing(['PICKUP', 'ROUTE', 'OFFICE'], array_column($result['data'], 'codigo'));
    }

    public function test_all_workbench_excludes_outbound_destination_packages(): void
    {
        $result = $this->ips->packages(['status' => 'all', 'office_cd' => 1]);
        $this->assertNotContains('FOREIGN', array_column($result['data'], 'codigo'));
    }

    public function test_postal_return_status_overrides_event13_label(): void
    {
        DB::connection('ips_fixture')->table('dbo.L_MAILITMS')->where('MAILITM_PID', 'FOREIGN')->update(['POSTAL_STATUS_CD' => 7]);
        $result = $this->ips->packages(['status' => 'all', 'q' => 'FOREIGN', 'office_cd' => 1]);
        $this->assertSame('returning', $result['data'][0]['stage']['key']);
        $this->assertSame('Devolución en curso', $result['data'][0]['stage']['label']);
        $this->assertSame([], $result['data'][0]['allowed_actions']);
    }

    public function test_exact_all_search_can_open_outbound_item_for_history_without_actions(): void
    {
        $result = $this->ips->packages(['status' => 'all', 'office_cd' => 1, 'q' => 'FOREIGN']);
        $this->assertSame('FOREIGN', $result['data'][0]['codigo']);
        $this->assertSame([], $result['data'][0]['allowed_actions']);
    }

    public function test_cached_total_never_caches_package_state_or_delivery_actions(): void
    {
        config(['ips.totals_cache_seconds' => 30, 'ips.totals_cache_store' => 'array']);
        $first = $this->ips->packages(['office_cd' => 1]);
        $db = DB::connection('ips_fixture');
        $db->table('dbo.L_MAILITMS')->where('MAILITM_PID', 'OFFICE')->update(['EVT_TYPE_CD' => 37, 'STATE_IND_CD' => 5]);
        $second = $this->ips->packages(['office_cd' => 1]);
        $package = collect($second['data'])->firstWhere('codigo', 'OFFICE');
        $this->assertSame($first['meta']['total'], $second['meta']['total']);
        $this->assertSame('delivered', $package['stage']['key']);
        $this->assertSame([], $package['allowed_actions']);
    }

    public function test_pagination_has_no_duplicate_on_tied_dates(): void
    {
        $first = $this->ips->packages(['status' => 'pending', 'per_page' => 2, 'page' => 1]);
        $second = $this->ips->packages(['status' => 'pending', 'per_page' => 2, 'page' => 2]);
        $this->assertTrue($first['meta']['has_more']);
        $this->assertFalse($second['meta']['has_more']);
        $this->assertCount(3, array_unique(array_merge(array_column($first['data'], 'codigo'), array_column($second['data'], 'codigo'))));
    }

    public function test_delivery_filter_uses_state_even_after_technical_update(): void
    {
        $result = $this->ips->packages(['status' => 'delivered']);
        $this->assertEqualsCanonicalizing(['DELIVERED', 'UPDATED'], array_column($result['data'], 'codigo'));
    }

    public function test_local_identifier_search_is_exact_and_dates_are_utc(): void
    {
        $result = $this->ips->packages(['q' => 'local-pickup']);
        $this->assertSame('PICKUP', $result['data'][0]['codigo']);
        $this->assertSame('2026-01-01T12:00:00.000+00:00', $result['data'][0]['event_at']);
        $this->assertCount(0, $this->ips->packages(['q' => "x' OR 1=1 --"])['data']);
    }

    public function test_missing_event_or_wrong_recipient_fails_verification(): void
    {
        $this->expectException(\App\Exceptions\IpsOperationException::class);
        $this->ips->verifyWrite('PICKUP', 37, '2026-01-01 12:00:00', 'Receptor');
    }

    protected function tearDown(): void
    {
        DB::purge('ips_fixture');
        parent::tearDown();
    }

    public function test_incoming_dispatch_uses_destination_and_customer_is_not_sender(): void
    {
        $db = DB::connection('ips_fixture');
        $db->table('dbo.L_MAILITMS')->where('MAILITM_PID', 'OFFICE')->update(['EVT_TYPE_CD' => 35]);
        $db->table('dbo.L_MAILITM_EVENTS')->insert(['MAILITM_PID' => 'OFFICE', 'EVENT_TYPE_CD' => 35, 'EVENT_GMT_DT' => '2026-01-01 12:00:00', 'EVENT_OFFICE_CD' => 1, 'NEXT_OFFICE_CD' => 2]);
        $db->table('dbo.L_MAILITM_CUSTOMERS')->insert([
            ['MAILITM_PID' => 'OFFICE', 'SENDER_PAYEE_IND' => 'S', 'CUSTOMER_NAME' => 'Sender', 'CUSTOMER_CITY' => 'Paris', 'CUSTOMER_PHONE_NO' => '111'],
            ['MAILITM_PID' => 'OFFICE', 'SENDER_PAYEE_IND' => 'A', 'CUSTOMER_NAME' => 'Recipient', 'CUSTOMER_CITY' => 'La Paz', 'CUSTOMER_PHONE_NO' => '222'],
        ]);
        $result = $this->ips->packages(['status' => 'reception', 'office_cd' => 2]);
        $this->assertSame('OFFICE', $result['data'][0]['codigo']);
        $this->assertSame(['EMG'], $result['data'][0]['allowed_actions']);
        $this->assertSame('Recipient', $result['data'][0]['recipient']);
        $this->assertSame('222', $result['data'][0]['phone']);
        $this->assertSame('La Paz', $result['data'][0]['city']);
        $this->assertCount(0, $this->ips->packages(['status' => 'reception', 'office_cd' => 1])['data']);
    }

    public function test_technical_update_preserves_operational_stage_and_expected_event(): void
    {
        $db = DB::connection('ips_fixture');
        $db->table('dbo.L_MAILITMS')->where('MAILITM_PID', 'OFFICE')->update(['EVT_TYPE_CD' => 61]);
        $db->table('dbo.L_MAILITM_EVENTS')->insert(['MAILITM_PID' => 'OFFICE', 'EVENT_TYPE_CD' => 32, 'EVENT_GMT_DT' => '2025-12-31 12:00:00', 'EVENT_OFFICE_CD' => 1]);
        $package = $this->ips->packages(['q' => 'OFFICE', 'office_cd' => 1])['data'][0];
        $this->assertSame(61, $package['event_cd']);
        $this->assertSame(32, $package['operational_event_cd']);
        $this->assertSame('received', $package['stage']['key']);
        $this->assertContains('EMI', $package['allowed_actions']);
        $this->assertNotContains('EMG', $package['allowed_actions']);
    }
}
