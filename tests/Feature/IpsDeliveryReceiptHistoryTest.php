<?php

namespace Tests\Feature;

use App\Services\IpsRepository;
use App\Services\IpsStagePolicy;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IpsDeliveryReceiptHistoryTest extends TestCase
{
    public static function receiptHistories(): array
    {
        return [
            [[[75, 18], [32, 18]], true],
            [[[75, 18]], false],
            [[[75, 18], [32, 17]], false],
            [[[75, 18], [35, 18], [32, 18]], false],
        ];
    }

    #[DataProvider('receiptHistories')]
    public function test_delivery_permission_comes_from_the_persisted_ips_history(array $events, bool $allowed): void
    {
        config(['database.connections.receipt_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'ips.connection' => 'receipt_fixture']);
        $db = DB::connection('receipt_fixture');
        $db->statement("ATTACH DATABASE ':memory:' AS dbo");
        $db->statement('CREATE TABLE dbo.L_MAILITM_CUSTOMERS (MAILITM_PID TEXT, SENDER_PAYEE_IND TEXT)');
        $db->statement('CREATE TABLE dbo.L_MAILITM_EVENTS (MAILITM_PID TEXT, EVENT_TYPE_CD INTEGER, EVENT_OFFICE_CD INTEGER, EVENT_GMT_DT TEXT, EVENT_LOCAL_OFFSET INTEGER, NEXT_OFFICE_CD INTEGER)');
        $db->statement('CREATE TABLE dbo.L_MAILITM_DELIV_INFOS (MAILITM_PID TEXT, EVENT_TYPE_CD INTEGER, EVENT_GMT_DT TEXT, DELIVERY_AGENT_CD TEXT)');
        $db->statement('CREATE TABLE dbo.N_OWN_OFFICES (OWN_OFFICE_CD INTEGER, OFFICE_FCD TEXT, OFFICE_NM TEXT, VALID_IND TEXT)');
        $db->table('dbo.N_OWN_OFFICES')->insert(['OWN_OFFICE_CD' => 18, 'OFFICE_FCD' => 'BOLPBB', 'OFFICE_NM' => 'La Paz', 'VALID_IND' => '1']);
        foreach ($events as $index => [$event, $office]) {
            $db->table('dbo.L_MAILITM_EVENTS')->insert([
                'MAILITM_PID' => 'letter', 'EVENT_TYPE_CD' => $event, 'EVENT_OFFICE_CD' => $office,
                'EVENT_GMT_DT' => '2026-11-13 12:'.str_pad((string) (59 - $index), 2, '0', STR_PAD_LEFT).':00',
            ]);
        }
        $item = (object) [
            'MAILITM_PID' => 'letter', 'MAILITM_FID' => 'LW945318163DE',
            'EVT_TYPE_CD' => 75, 'EVT_OFFICE_CD' => 18, 'EVT_GMT_DT' => '2026-11-13 12:59:00',
            'STATE_IND_CD' => 0, 'ORIG_COUNTRY_CD' => 'DE', 'DEST_COUNTRY_CD' => 'BO',
        ];
        $repository = new IpsRepository;
        $repository->enrich(collect([$item]));
        $package = $repository->present($item);
        $this->assertSame($allowed, $package['destination_receipt_confirmed']);
        $this->assertSame($allowed, in_array('EMI', (new IpsStagePolicy)->actions($package, 18), true));
    }
}
