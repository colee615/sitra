<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\IpsOperationService;
use App\Services\IpsRepository;
use App\Services\Postal\CdsRepository;
use App\Services\Postal\CustomsRemittanceBuilder;
use App\Services\Postal\CustomsOperationsService;
use App\Services\Postal\PostalWorkspace;
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

    public function test_api_exposes_read_only_dispatch_and_receptacle_lookups(): void
    {
        config(['postal.cds_enabled'=>true]);
        $bag = (object)[
            'RECPTCL_PID'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'RECPTCL_FID'=>'S9TEST01',
            'DESPTCH_PID'=>'dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'DESPTCH_FID'=>'S8TEST01',
            'RECPTCL_WEIGHT'=>'1.000', 'RECPTCL_MAILITMS_NO'=>1,
            'DESPTCH_WEIGHT'=>'2.000', 'DESPTCH_RECPTCLS_NO'=>1,
            'ORIG_OFFICE_FCD'=>'BOLPBC', 'DEST_OFFICE_FCD'=>'ITMXPA',
            'DESPTCH_DEPARTURE_DT'=>'2026-10-13 10:00:00', 'CONVEYANCE_TYPE_CD'=>'P',
        ];
        $sackResult = [
            'search_type'=>'receptacle', 'truncated'=>false, 'identifier'=>'S9TEST01',
            'code_analysis'=>['type'=>'s9', 'dispatch_code'=>'S8TEST01', 'receptacle_sequence'=>'001'],
            'receptacles'=>[$bag], 'dispatches'=>[], 'events'=>[], 'manifests'=>[],
            'items'=>[(object)[
                'MAILITM_PID'=>'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'MAILITM_FID'=>'LX123456789US',
                'MAILITM_LOCAL_ID'=>'LOCAL001', 'MAILITM_WEIGHT'=>'1.000', 'EVT_TYPE_CD'=>30,
                'EVT_GMT_DT'=>'2026-10-13 10:00:00', 'EVT_OFFICE_CD'=>123, 'STATE_IND_CD'=>0,
                'ORIG_COUNTRY_CD'=>'US', 'DEST_COUNTRY'=>'BO', 'EVENT_NAME_ES'=>'Recibido en oficina de cambio',
                'MAIL_CLASS_CD'=>'E', 'MAIL_CLASS_NAME'=>'EMS', 'ORIGIN_COUNTRY'=>'Estados Unidos',
                'POSTAL_STATUS_CD'=>'1', 'POSTAL_STATUS_NAME'=>'En tránsito',
            ]],
        ];
        $search = Mockery::mock(ReceptacleSearchService::class);
        $search->shouldReceive('search')->with('S8TEST01')->once()->andReturn([
            'search_type'=>'s8', 'truncated'=>false,
            'code_analysis'=>['type'=>'s8', 'code'=>'S8TEST01'],
            'dispatches'=>[(object)[
                'DESPTCH_PID'=>'dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'DESPTCH_FID'=>'S8TEST01',
                'ORIG_OFFICE_FCD'=>'BOLPBC', 'DEST_OFFICE_FCD'=>'ITMXPA',
                'DESPTCH_WEIGHT'=>'2.000', 'DESPTCH_RECPTCLS_NO'=>1,
                'DESPTCH_DEPARTURE_DT'=>'2026-10-13 10:00:00', 'CONVEYANCE_TYPE_CD'=>'P',
            ]],
            'receptacles'=>[$bag], 'items'=>[], 'events'=>[], 'manifests'=>[],
        ]);
        $search->shouldReceive('search')->with('S9TEST01')->once()->andReturn($sackResult);
        $search->shouldReceive('search')->with('BOLPBCITMXPAACN60005')->twice()->andReturn([
            'search_type'=>'s8', 'truncated'=>false,
            'code_analysis'=>['type'=>'s8', 'code'=>'BOLPBCITMXPAACN60005'],
            'dispatches'=>[], 'receptacles'=>[], 'events'=>[], 'items'=>[], 'manifests'=>[],
        ]);
        $search->shouldReceive('search')->with('BOLPBCITMXPAACN60005001100007')->once()->andReturn([
            'search_type'=>'receptacle', 'truncated'=>false,
            'code_analysis'=>['type'=>'s9', 'code'=>'BOLPBCITMXPAACN60005001100007'],
            'dispatches'=>[], 'receptacles'=>[], 'events'=>[], 'items'=>[], 'manifests'=>[],
        ]);
        $fullS8 = 'BOLPBCITMXPAACN60004';
        $fullS9Bag = clone $bag;
        $fullS9Bag->RECPTCL_FID = 'BOLPBCITMXPAACN60004001100091';
        $fullS9Bag->DESPTCH_FID = $fullS8;
        $search->shouldReceive('search')->with($fullS8)->once()->andReturn([
            'search_type'=>'s8', 'truncated'=>false, 'identifier'=>$fullS8,
            'code_analysis'=>[
                'type'=>'s8', 'code'=>$fullS8, 'origin_ctci'=>'BOLPBC',
                'destination_ctci'=>'ITMXPA', 'dispatch_sequence'=>'0004',
            ],
            'dispatches'=>[(object)[
                'DESPTCH_PID'=>'dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'DESPTCH_FID'=>$fullS8,
                'ORIG_OFFICE_FCD'=>'BOLPBC', 'DEST_OFFICE_FCD'=>'ITMXPA',
                'DESPTCH_WEIGHT'=>'2.000', 'DESPTCH_RECPTCLS_NO'=>1, 'LINKED_RECPTCLS_NO'=>1,
                'DESPTCH_DEPARTURE_DT'=>'2026-10-13 10:00:00', 'CONVEYANCE_TYPE_CD'=>'P',
            ]],
            'receptacles'=>[$fullS9Bag], 'events'=>[], 'items'=>[], 'manifests'=>[],
        ]);
        $fullS9Result = $sackResult;
        $fullS9Result['identifier'] = $fullS9Bag->RECPTCL_FID;
        $fullS9Result['code_analysis']['dispatch_code'] = $fullS8;
        $fullS9Result['receptacles'] = [$fullS9Bag];
        $search->shouldReceive('search')->with($fullS9Bag->RECPTCL_FID)->once()->andReturn($fullS9Result);

        $ips = Mockery::mock(IpsRepository::class);

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
        $cds->shouldReceive('declarationIndex')->twice()
            ->withArgs(fn ($codes, $limit) => $codes === ['LX123456789US', 'LOCAL001'] && $limit === 1000)
            ->andReturn($cdsResult);

        $operations = Mockery::mock(IpsOperationService::class);
        $this->app->instance(CustomsOperationsService::class, new CustomsOperationsService(
            $search, new CustomsRemittanceBuilder($cds), $ips, $operations,
        ));

        $this->withToken($this->token(['customs.read']))
            ->getJson('/api/v1/aduana/despachos/S8TEST01')
            ->assertOk()->assertJsonPath('data.identifier', 'S8TEST01')
            ->assertJsonPath('data.dispatch_number', null)
            ->assertJsonPath('data.dispatches.0.origin', 'BOLPBC')
            ->assertJsonPath('data.dispatches.0.destination', 'ITMXPA')
            ->assertJsonPath('data.dispatches.0.weight_kg', 2)
            ->assertJsonPath('data.dispatches.0.departure_at', '2026-10-13 10:00:00')
            ->assertJsonPath('data.dispatches.0.transport', 'P')
            ->assertJsonPath('data.dispatches.0.sacks_declared', 1)
            ->assertJsonPath('data.dispatches.0.sacks_linked', 1)
            ->assertJsonMissingPath('data.dispatches.0.sacks')
            ->assertJsonMissingPath('data.summary')
            ->assertJsonMissingPath('data.detail')
            ->assertJsonMissingPath('data.receptacles');
        $this->getJson('/api/v1/aduana/sacas/S9TEST01')
            ->assertOk()->assertJsonPath('data.identifier', 'S9TEST01')
            ->assertJsonPath('data.sack.number', '001')
            ->assertJsonPath('data.sack.packages_declared', 1)
            ->assertJsonPath('data.sack.packages_linked', 1)
            ->assertJsonPath('data.sack.counts_match', true)
            ->assertJsonPath('data.related_identifiers.dispatch', 'S8TEST01')
            ->assertJsonPath('data.related_identifiers.packages.0', 'LX123456789US')
            ->assertJsonMissingPath('data.code_analysis')
            ->assertJsonMissingPath('data.manifests');

        $this->getJson('/api/v1/aduana/consulta/'.$fullS8)
            ->assertOk()
            ->assertJsonPath('data.type', 'S8')
            ->assertJsonPath('data.identifier', $fullS8)
            ->assertJsonPath('data.details.dispatch_number', '0004')
            ->assertJsonPath('data.identifier_analysis.origin_ctci', 'BOLPBC')
            ->assertJsonPath('data.details.dispatches.0.identifier', $fullS8)
            ->assertJsonPath('data.details.dispatches.0.sacks.0.identifier', $fullS9Bag->RECPTCL_FID)
            ->assertJsonPath('data.details.dispatches.0.sacks.0.packages_declared', 1);

        $this->getJson('/api/v1/aduana/consulta/BOLPBCITMXPAACN60004001100091')
            ->assertOk()
            ->assertJsonPath('data.type', 'S9')
            ->assertJsonPath('data.identifier', 'BOLPBCITMXPAACN60004001100091')
            ->assertJsonPath('data.details.sack.weight_kg', 1)
            ->assertJsonPath('data.identifier_analysis.receptacle_sequence', '001')
            ->assertJsonPath('data.details.packages.0.identifier', 'LX123456789US');

        $this->getJson('/api/v1/aduana/despachos/BOLPBCITMXPAACN60005')->assertNotFound();
        $this->getJson('/api/v1/aduana/consulta/BOLPBCITMXPAACN60005')->assertNotFound();
        $this->getJson('/api/v1/aduana/sacas/BOLPBCITMXPAACN60005001100007')->assertNotFound();
    }

    public function test_customs_api_requires_both_sitra_permission_and_token_ability(): void
    {
        $this->getJson('/api/v1/aduana/despachos/S8TEST01')->assertUnauthorized();

        $this->withToken($this->token(['customs.read'], admin:false))
            ->getJson('/api/v1/aduana/despachos/S8TEST01')->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->withToken($this->token(['sqlserver.read'], admin:false, permissions:['customs.read']))
            ->getJson('/api/v1/aduana/despachos/S8TEST01')
            ->assertForbidden();
    }

    public function test_api_exposes_s10_package_and_declaration_lookups(): void
    {
        $workspaceResult = [
            'code'=>'EE000738405BO',
            'identifier'=>[
                'type'=>'S10', 'valid'=>true,
                'message'=>'Dígito de control correcto.', 'issuer'=>'BO',
            ],
            'ips'=>[
                'packageRows'=>[(object)[
                    'MAILITM_FID'=>'EE000738405BO', 'MAILITM_LOCAL_ID'=>'LOCAL-001',
                ]],
                'trackingRows'=>[(object)[
                    'EVT_TYPE_CD'=>30, 'EVENT_NAME_ES'=>'Insertar envío en saca (salida)',
                ]],
            ],
            'cds'=>[
                'packages'=>[(object)[
                    'MAIL_OBJECT_PID'=>123, 'MAIL_OBJECT_ID'=>'EE000738405BO', 'MAIL_OBJECT_LOCAL_ID'=>'LOCAL-001',
                ]],
                'declarations'=>[[
                    'id'=>'DECL-1', 'declaration_number'=>'CN23-0001',
                    'package_id'=>123, 'state'=>'Enviada a Aduana',
                    'workflow_stage'=>'Enviada a Aduana',
                    'content_summary'=>'1 artículo(s) · 0 documento(s)',
                    'data'=>[
                        'status'=>'ok', 'fields'=>[],
                        'pieces'=>[['description'=>'Documentos']], 'documents'=>[],
                    ],
                    'countries'=>[], 'nature'=>'Documentos',
                ]],
                'responses'=>[], 'events'=>[], 'truncated'=>false,
            ],
            'sources'=>['IPS'=>'ok', 'CDS'=>'ok'],
        ];
        $workspace = Mockery::mock(PostalWorkspace::class);
        $workspace->shouldReceive('search')
            ->with('EE000738405BO', true, true)
            ->times(3)
            ->andReturn($workspaceResult);
        $workspace->shouldReceive('search')
            ->with('UNKNOWN001', true, true)
            ->times(2)
            ->andReturn([
                'code'=>'UNKNOWN001',
                'identifier'=>['type'=>'CÃ³digo local', 'valid'=>null],
                'ips'=>[], 'cds'=>[],
                'sources'=>['IPS'=>'empty', 'CDS'=>'empty'],
            ]);
        $this->app->instance(PostalWorkspace::class, $workspace);

        $packageResponse = $this->withToken($this->token(['customs.read']))
            ->getJson('/api/v1/aduana/paquetes/EE000738405BO')
            ->assertOk()
            ->assertJsonPath('data.identifier', 'EE000738405BO')
            ->assertJsonPath('data.related_identifiers.declarations.0', 'CN23-0001')
            ->assertJsonPath('data.summary.movement_count', 1)
            ->assertJsonPath('data.movements.0.code', 30)
            ->assertJsonMissingPath('data.package.code')
            ->assertJsonMissingPath('data.customs')
            ->assertJsonMissingPath('data.customers')
            ->assertJsonMissingPath('data.detail')
            ->assertJsonMissingPath('data.ips')
            ->assertJsonMissingPath('data.cds_package_groups');
        $this->assertStringContainsString('no-store', (string) $packageResponse->headers->get('Cache-Control'));

        $declarationsResponse = $this->getJson('/api/v1/aduana/declaraciones/EE000738405BO')
            ->assertOk()
            ->assertJsonPath('data.identifier', 'EE000738405BO')
            ->assertJsonPath('data.packages.0.declarations.0.number', 'CN23-0001')
            ->assertJsonPath('data.source_status', 'ok')
            ->assertJsonMissingPath('data.detail');
        $this->assertStringContainsString('no-store', (string) $declarationsResponse->headers->get('Cache-Control'));

        $this->getJson('/api/v1/aduana/consulta/EE000738405BO')
            ->assertOk()
            ->assertJsonPath('data.type', 'S10')
            ->assertJsonPath('data.identifier', 'EE000738405BO')
            ->assertJsonPath('data.details.package.local_code', 'LOCAL-001')
            ->assertJsonPath('data.identifier_analysis.valid', true)
            ->assertJsonPath('data.details.package.code', 'EE000738405BO')
            ->assertJsonPath('data.details.customs.cds.packages.0.declarations.0.number', 'CN23-0001')
            ->assertJsonPath('data.details.customs.cds.packages.0.declarations.0.data.pieces.0.description', 'Documentos')
            ->assertJsonPath('data.details.events.0.code', 30);

        $this->getJson('/api/v1/aduana/paquetes/UNKNOWN001')->assertNotFound();
        $this->getJson('/api/v1/aduana/declaraciones/UNKNOWN001')->assertNotFound();
    }

    public function test_s10_aduana_lookups_reject_tokens_without_customs_read(): void
    {
        $this->getJson('/api/v1/aduana/paquetes/EE000738405BO')->assertUnauthorized();

        $this->withToken($this->token(['sqlserver.read']))
            ->getJson('/api/v1/aduana/paquetes/EE000738405BO')
            ->assertForbidden();

        $this->app['auth']->forgetGuards();

        $this->withToken($this->token(['sqlserver.read']))
            ->getJson('/api/v1/aduana/declaraciones/EE000738405BO')
            ->assertForbidden();
    }
}
