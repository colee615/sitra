<?php

namespace Tests\Feature;

use App\Exceptions\IpsOperationException;
use App\Models\User;
use App\Services\IpsOperationService;
use App\Services\IpsRepository;
use App\Services\Postal\CdsRepository;
use App\Services\Postal\CustomsOperationsService;
use App\Services\Postal\CustomsRemittanceBuilder;
use App\Services\Postal\ReceptacleSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CustomsOperationsServiceTest extends TestCase
{
    use RefreshDatabase;

    private function bagResult(bool $truncated = false): array
    {
        return [
            'search_type'=>'receptacle', 'truncated'=>$truncated,
            'receptacles'=>[(object)['RECPTCL_PID'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'RECPTCL_FID'=>'S9TEST01']],
            'items'=>[(object)[
                'MAILITM_PID'=>'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'MAILITM_FID'=>'LX123456789US',
                'MAILITM_LOCAL_ID'=>'LOCAL001', 'DESPTCH_FID'=>'S8TEST01',
            ]],
        ];
    }

    private function service(ReceptacleSearchService $search, IpsRepository $ips): CustomsOperationsService
    {
        return new CustomsOperationsService(
            $search,
            new CustomsRemittanceBuilder(Mockery::mock(CdsRepository::class)),
            $ips,
            Mockery::mock(IpsOperationService::class),
        );
    }

    public function test_session_opens_after_ips_confirms_sack_open_and_scans_are_reconciled(): void
    {
        config(['postal.cds_enabled'=>true]);
        $operator = User::factory()->create();
        $search = Mockery::mock(ReceptacleSearchService::class);
        $search->shouldReceive('search')->once()->with('S9TEST01')->andReturn($this->bagResult());
        $ips = Mockery::mock(IpsRepository::class);
        $ips->shouldReceive('assertReady')->once();
        $ips->shouldReceive('receptacleDetail')->once()->with('S9TEST01', 123)
            ->andReturn(['receptacle'=>['event_cd'=>131, 'office_cd'=>123]]);

        $customs = $this->service($search, $ips);
        $session = $customs->openSession('S9TEST01', 123, $operator->id, 'customs_api');

        $this->assertSame('open', $session->status);
        $this->assertDatabaseHas('customs_work_sessions', [
            'id'=>$session->id, 'receptacle_code'=>'S9TEST01', 'office_cd'=>123, 'status'=>'open',
        ]);
        $this->assertDatabaseHas('customs_session_items', [
            'session_id'=>$session->id, 'postal_code'=>'LX123456789US', 'local_code'=>'LOCAL001', 'scan_status'=>'pending',
        ]);

        $expected = $customs->recordPhysicalScan($session->id, 'lx123456789us', $operator->id);
        $duplicate = $customs->recordPhysicalScan($session->id, 'LX123456789US', $operator->id);
        $unexpected = $customs->recordPhysicalScan($session->id, 'LX987654321US', $operator->id);

        $this->assertSame('expected', $expected['result']);
        $this->assertSame('duplicate', $duplicate['result']);
        $this->assertSame('unexpected', $unexpected['result']);
        $this->assertDatabaseCount('customs_physical_scans', 3);
        $this->assertDatabaseHas('customs_session_items', ['session_id'=>$session->id, 'scan_status'=>'scanned']);
    }

    public function test_incomplete_sack_read_cannot_open_customs_session(): void
    {
        config(['postal.cds_enabled'=>true]);
        $operator = User::factory()->create();
        $search = Mockery::mock(ReceptacleSearchService::class);
        $search->shouldReceive('search')->once()->with('S9TEST01')->andReturn($this->bagResult(truncated:true));
        $ips = Mockery::mock(IpsRepository::class);
        $ips->shouldReceive('assertReady')->once();
        $ips->shouldNotReceive('receptacleDetail');

        try {
            $this->service($search, $ips)->openSession('S9TEST01', 123, $operator->id);
            $this->fail('Una consulta incompleta no debe crear expediente.');
        } catch (IpsOperationException $exception) {
            $this->assertSame(409, $exception->status);
        }

        $this->assertDatabaseCount('customs_work_sessions', 0);
    }

    public function test_customs_decision_requires_a_scanned_package_and_non_deleted_cds_declaration(): void
    {
        config(['postal.cds_enabled'=>true]);
        $operator = User::factory()->create();
        $sessionId = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
        $this->insertOpenSession($operator, $sessionId, 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', true);

        $search = Mockery::mock(ReceptacleSearchService::class);
        $ips = Mockery::mock(IpsRepository::class);
        $ips->shouldReceive('find')->once()->with('LX123456789US')->andReturn((object)['MAILITM_FID'=>'LX123456789US']);
        $ips->shouldReceive('enrich')->once();
        $ips->shouldReceive('present')->once()->andReturn([
            'code'=>'LX123456789US', 'event_cd'=>31, 'operational_event_cd'=>31, 'event_at'=>'2026-10-13T10:00:00+00:00',
            'office_cd'=>123, 'operational_office_cd'=>123, 'state_cd'=>1,
            'origin_country'=>'US', 'destination_country'=>'BO', 'terminal'=>false,
        ]);
        $cds = Mockery::mock(CdsRepository::class);
        $cds->shouldReceive('declarationIndex')->once()->with(['LX123456789US', 'LOCAL001'], 1000)
            ->andReturn([
                'truncated'=>false, 'packages_truncated'=>false, 'declarations_truncated'=>false, 'responses_truncated'=>false,
                'packages'=>[(object)[
                    'MAIL_OBJECT_PID'=>555, 'MAIL_OBJECT_ID'=>'LX123456789US',
                    'MAIL_OBJECT_LOCAL_ID'=>'LOCAL001', 'MAIL_OBJECT_LOCAL_ID2'=>null,
                ]],
                'declarations'=>[
                    ['id'=>'DECL-1', 'package_id'=>555, 'workflow_stage'=>'Enviada a Aduana', 'state'=>'Sent to customs'],
                    ['id'=>'DECL-DELETED', 'package_id'=>555, 'workflow_stage'=>'Eliminada en CDS', 'state'=>'Deleted'],
                ],
                'responses'=>[],
            ]);
        $remittances = new CustomsRemittanceBuilder($cds);
        $operations = Mockery::mock(IpsOperationService::class);
        $operations->shouldReceive('submit')->once()->with($operator->id, 'event', Mockery::on(function (array $input): bool {
            return $input['codigo']==='LX123456789US' && $input['event']==='CUSTOMS_INFO'
                && $input['office_cd']===123 && $input['customs_release_status_cd']==='01';
        }))->andReturn([
            'body'=>['status'=>'succeeded', 'operation_id'=>'dddddddd-dddd-4ddd-8ddd-dddddddddddd'],
            'status'=>200, 'replayed'=>false,
        ]);

        $customs = new CustomsOperationsService($search, $remittances, $ips, $operations);
        $result = $customs->recordCaseAction($sessionId, 'LX123456789US', 'decision', [
            'idempotency_key'=>'decision-test-20261013', 'customs_release_status_cd'=>'01',
        ], $operator->id, 'customs_api');

        $this->assertSame('succeeded', $result['status']);
        $this->assertDatabaseHas('customs_case_actions', [
            'session_id'=>$sessionId, 'postal_code'=>'LX123456789US', 'action'=>'decision', 'status'=>'succeeded',
            'declaration_ids'=>'["DECL-1"]',
        ]);
    }

    private function insertOpenSession(User $operator, string $sessionId, string $itemPid, bool $scanned): void
    {
        $now = now();
        \Illuminate\Support\Facades\DB::table('customs_work_sessions')->insert([
            'id'=>$sessionId, 'dispatch_code'=>'S8TEST01', 'receptacle_pid'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'receptacle_code'=>'S9TEST01', 'office_cd'=>123, 'status'=>'open', 'operator_mode'=>'customs_api',
            'opened_by'=>$operator->id, 'opened_at'=>$now, 'created_at'=>$now, 'updated_at'=>$now,
        ]);
        \Illuminate\Support\Facades\DB::table('customs_session_items')->insert([
            'id'=>'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', 'session_id'=>$sessionId, 'ips_mailitm_pid'=>$itemPid,
            'postal_code'=>'LX123456789US', 'local_code'=>'LOCAL001', 'scan_status'=>$scanned ? 'scanned' : 'pending',
            'scanned_by'=>$scanned ? $operator->id : null, 'scanned_at'=>$scanned ? $now : null,
            'created_at'=>$now, 'updated_at'=>$now,
        ]);
    }
}
