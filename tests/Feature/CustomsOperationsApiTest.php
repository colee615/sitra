<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\IpsOperationService;
use App\Services\IpsRepository;
use App\Services\Postal\CdsRepository;
use App\Services\Postal\CustomsRemittanceBuilder;
use App\Services\Postal\CustomsOperationsService;
use App\Services\Postal\ReceptacleSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomsOperationsApiTest extends TestCase
{
    use RefreshDatabase;

    private function token(array $abilities, bool $admin = true, array $permissions = []): string
    {
        $user = User::factory()->create();
        if ($admin) {
            $user->assignRole(Role::findOrCreate('admin', 'web'));
        }
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $user->createToken('customs-test', $abilities)->plainTextToken;
    }

    public function test_api_exposes_the_dispatch_to_customs_return_contract(): void
    {
        $session = '11111111-1111-4111-8111-111111111111';
        config(['postal.cds_enabled'=>true]);
        $bagEvent = 0;
        $packageEvent = 30;
        $packageEventAt = '2026-10-13T10:00:00+00:00';
        $bag = (object)[
            'RECPTCL_PID'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'RECPTCL_FID'=>'S9TEST01',
            'DESPTCH_PID'=>'dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'DESPTCH_FID'=>'S8TEST01',
        ];
        $sackResult = [
            'search_type'=>'receptacle', 'truncated'=>false, 'identifier'=>'S9TEST01',
            'receptacles'=>[$bag], 'dispatches'=>[], 'events'=>[], 'manifests'=>[],
            'items'=>[(object)[
                'MAILITM_PID'=>'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'MAILITM_FID'=>'LX123456789US',
                'MAILITM_LOCAL_ID'=>'LOCAL001', 'MAILITM_WEIGHT'=>'1.000', 'EVT_TYPE_CD'=>30,
                'EVT_GMT_DT'=>'2026-10-13 10:00:00', 'EVT_OFFICE_CD'=>123, 'STATE_IND_CD'=>0,
                'ORIG_COUNTRY_CD'=>'US', 'DEST_COUNTRY'=>'BO', 'EVENT_NAME_ES'=>'Recibido en oficina de cambio',
            ]],
        ];
        $search = Mockery::mock(ReceptacleSearchService::class);
        $search->shouldReceive('search')->with('S8TEST01')->once()->andReturn([
            'search_type'=>'s8', 'truncated'=>false, 'dispatches'=>[(object)['DESPTCH_FID'=>'S8TEST01']],
            'receptacles'=>[$bag], 'items'=>[], 'events'=>[], 'manifests'=>[],
        ]);
        $search->shouldReceive('search')->with('S9TEST01')->times(3)->andReturn($sackResult);

        $ips = Mockery::mock(IpsRepository::class);
        $ips->shouldReceive('assertReady')->twice();
        $ips->shouldReceive('receptacleDetail')->times(5)->with('S9TEST01', 123)
            ->andReturnUsing(function () use (&$bagEvent) {
                return ['receptacle'=>[
                    'id'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'event_cd'=>$bagEvent,
                    'event_at'=>'2026-10-13T09:00:00+00:00', 'office_cd'=>123,
                    'allowed_actions'=>$bagEvent === 0 ? ['RECEPTACLE_RECEIVE'] : ($bagEvent === 130 ? ['RECEPTACLE_OPEN'] : []),
                ]];
            });
        $ips->shouldReceive('find')->times(3)->with('LX123456789US')->andReturn((object)['MAILITM_FID'=>'LX123456789US']);
        $ips->shouldReceive('enrich')->times(3);
        $ips->shouldReceive('present')->times(3)->andReturnUsing(function () use (&$packageEvent, &$packageEventAt) {
            $state = in_array($packageEvent, [31, 34], true) ? 1 : 0;
            return [
                'code'=>'LX123456789US', 'event_cd'=>$packageEvent, 'operational_event_cd'=>$packageEvent,
                'event_at'=>$packageEventAt, 'office_cd'=>123, 'operational_office_cd'=>123,
                'state_cd'=>$state, 'origin_country'=>'US', 'destination_country'=>'BO',
                'exchange_office_cds'=>[123], 'terminal'=>false,
            ];
        });

        $cdsResult = [
            'packages'=>[(object)[
                'MAIL_OBJECT_PID'=>555, 'MAIL_OBJECT_ID'=>'LX123456789US',
                'MAIL_OBJECT_LOCAL_ID'=>'LOCAL001', 'MAIL_OBJECT_LOCAL_ID2'=>null,
            ]],
            'declarations'=>[['id'=>'DECL-1', 'package_id'=>555, 'workflow_stage'=>'Enviada a Aduana', 'state'=>'Sent to customs']],
            'responses'=>[], 'truncated'=>false, 'packages_truncated'=>false,
            'declarations_truncated'=>false, 'responses_truncated'=>false,
        ];
        $cds = Mockery::mock(CdsRepository::class);
        $cds->shouldReceive('declarationIndex')->times(4)
            ->withArgs(fn ($codes, $limit) => $codes === ['LX123456789US', 'LOCAL001'] && $limit === 1000)
            ->andReturn($cdsResult);

        $operations = Mockery::mock(IpsOperationService::class);
        $operations->shouldReceive('submit')->times(5)->andReturnUsing(
            function ($userId, $action, array $input) use (&$bagEvent, &$packageEvent) {
                if ($action === 'receptacle') {
                    $bagEvent = $input['event'] === 'RECEPTACLE_RECEIVE' ? 130 : 131;
                } else {
                    $packageEvent = match ($input['event']) {
                        'CUSTOMS_PRESENT'=>31, 'CUSTOMS_INFO'=>34, 'CUSTOMS_RETURN'=>38,
                    };
                }
                return ['body'=>['status'=>'succeeded', 'operation_id'=>(string) \Illuminate\Support\Str::uuid()], 'status'=>200, 'replayed'=>false];
            }
        );
        $this->app->instance(CustomsOperationsService::class, new CustomsOperationsService(
            $search, new CustomsRemittanceBuilder($cds), $ips, $operations,
        ));

        $this->withToken($this->token(['customs.process']))
            ->getJson('/api/v1/aduana/despachos/S8TEST01')
            ->assertOk()->assertJsonPath('data.search_type', 's8');
        $this->getJson('/api/v1/aduana/sacas/S9TEST01?office_cd=123')
            ->assertOk()->assertJsonPath('data.identifier', 'S9TEST01');

        foreach (['RECEPTACLE_RECEIVE'=>'receive', 'RECEPTACLE_OPEN'=>'open'] as $event=>$suffix) {
            $response = $this->postJson('/api/v1/aduana/sacas/S9TEST01/eventos', [
                'event'=>$event, 'office_cd'=>123, 'physical_receipt_confirmed'=>true,
            ], ['Idempotency-Key'=>'customs-S9TEST01-'.$suffix.'-20261013'])
                ->assertOk()->assertJsonPath('status', 'succeeded');
            if ($event === 'RECEPTACLE_OPEN') $session = $response->json('result.customs_session.id');
        }

        $this->postJson('/api/v1/aduana/sesiones', ['codigo'=>'S9TEST01', 'office_cd'=>123])
            ->assertOk()->assertJsonPath('data.id', $session);
        $this->postJson("/api/v1/aduana/sesiones/{$session}/escaneos", ['codigo'=>'LX123456789US'])
            ->assertOk()->assertJsonPath('data.result', 'expected');

        $this->postJson("/api/v1/aduana/sesiones/{$session}/envios/LX123456789US/present", [
            'customs_office_cd'=>123, 'customs_office_type_cd'=>'01',
        ], ['Idempotency-Key'=>'customs-LX123456789US-present-20261013'])
            ->assertOk()->assertJsonPath('status', 'succeeded');
        $this->postJson("/api/v1/aduana/sesiones/{$session}/envios/LX123456789US/decision", [
            'customs_release_status_cd'=>'01', 'notes'=>'Revisado', 'evidence_reference'=>'ACTA-1',
        ], ['Idempotency-Key'=>'customs-LX123456789US-decision-20261013'])
            ->assertOk()->assertJsonPath('status', 'succeeded');
        $this->postJson("/api/v1/aduana/sesiones/{$session}/envios/LX123456789US/return", [
            'physical_receipt_confirmed'=>true,
        ], ['Idempotency-Key'=>'customs-LX123456789US-return-20261013'])
            ->assertOk()->assertJsonPath('status', 'succeeded');
    }

    public function test_customs_api_requires_both_sitra_permission_and_token_ability(): void
    {
        $this->getJson('/api/v1/aduana/despachos/S8TEST01')->assertUnauthorized();

        $this->withToken($this->token(['customs.read'], admin:false))
            ->getJson('/api/v1/aduana/despachos/S8TEST01')->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->withToken($this->token(['customs.read'], admin:false, permissions:['customs.read']))
            ->postJson('/api/v1/aduana/sesiones', ['codigo'=>'S9TEST01', 'office_cd'=>123])
            ->assertForbidden();
    }

    public function test_customs_api_rejects_incomplete_physical_and_customs_actions(): void
    {
        $session = '11111111-1111-4111-8111-111111111111';
        $this->withToken($this->token(['customs.process']))
            ->postJson('/api/v1/aduana/sacas/S9TEST01/eventos', [
                'event'=>'RECEPTACLE_RECEIVE', 'office_cd'=>123, 'physical_receipt_confirmed'=>false,
            ], ['Idempotency-Key'=>'customs-S9TEST01-receive-20261013'])
            ->assertUnprocessable()->assertJsonValidationErrors('physical_receipt_confirmed');

        $this->postJson("/api/v1/aduana/sesiones/{$session}/envios/LX123456789US/present", [
            'customs_office_cd'=>123,
        ], ['Idempotency-Key'=>'customs-LX123456789US-present-20261013'])
            ->assertUnprocessable()->assertJsonValidationErrors('customs_office_type_cd');
        $this->postJson("/api/v1/aduana/sesiones/{$session}/envios/LX123456789US/decision", [], [
            'Idempotency-Key'=>'customs-LX123456789US-decision-20261013',
        ])->assertUnprocessable()->assertJsonValidationErrors('customs_release_status_cd');
        $this->postJson("/api/v1/aduana/sesiones/{$session}/envios/LX123456789US/return", [], [
            'Idempotency-Key'=>'customs-LX123456789US-return-20261013',
        ])->assertUnprocessable()->assertJsonValidationErrors('physical_receipt_confirmed');
    }
}
