<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Postal\CdsRepository;
use App\Services\Postal\CustomsRemittanceBuilder;
use App\Services\Postal\ReceptacleSearchService;
use App\Services\Postal\PostalActivityReport;
use App\Services\Postal\PostalWorkspace;
use App\Services\SqlServerSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PostalWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function operator(bool $admin = false): User
    {
        $user = User::factory()->create();
        if ($admin) $user->assignRole(Role::findOrCreate('admin', 'web'));
        return $user;
    }

    public function test_unprivileged_users_cannot_query_or_print(): void
    {
        $this->actingAs($this->operator());
        $this->get('/consultas')->assertForbidden();
        $this->get('/consultas/documentos/marbete?codigo=TEST')->assertForbidden();
        $this->get('/consultas/declaracion/imprimir?codigo=TEST&declaracion=TEST-ID')->assertForbidden();
        $this->get('/operaciones-postales')->assertForbidden();
        $this->get('/operaciones-postales/reporte.csv')->assertForbidden();
        $this->get('/despachos')->assertForbidden();
        $this->get('/dashboard')->assertOk()->assertDontSee('Declaraciones CDS');
    }

    public function test_admin_can_open_workspace_without_database_queries(): void
    {
        $this->mock(SqlServerSearchService::class)->shouldNotReceive('search');
        $this->mock(CdsRepository::class)->shouldNotReceive('search');
        $this->actingAs($this->operator(true))->get('/consultas')->assertOk()->assertSee('Comienza con el código');
    }

    public function test_ips_user_can_search_a_upu_s8_dispatch_and_open_its_s9_receptacle(): void
    {
        $user = $this->operator();
        $user->givePermissionTo(Permission::findOrCreate('ips.read', 'web'));
        $dispatchCode = 'BEBRUABOLPBBAEN60073';
        $this->mock(ReceptacleSearchService::class)->shouldReceive('search')->once()->with($dispatchCode)->andReturn([
            'identifier' => $dispatchCode,
            'search_type' => 's8',
            'code_analysis' => \App\Services\Postal\UpuDispatchIdentifier::parse($dispatchCode),
            'receptacle_total' => 1,
            'dispatches' => [(object)[
                'DESPTCH_PID'=>91,'DESPTCH_FID'=>$dispatchCode,'ORIG_OFFICE_FCD'=>'BEBRUA','DEST_OFFICE_FCD'=>'BOLPBB',
                'DESPTCH_WEIGHT'=>22.7,'DESPTCH_DEPARTURE_DT'=>'2026-09-25','DESPTCH_RECPTCLS_NO'=>1,'LINKED_RECPTCLS_NO'=>1,'CONVEYANCE_TYPE_CD'=>'Aereo',
            ]],
            'receptacles' => [(object)[
                'RECPTCL_PID'=>501,'RECPTCL_FID'=>'BEBRUABOLPBBAEN60073003000227','DESPTCH_PID'=>91,
                'RECPTCL_WEIGHT'=>22.7,'RECPTCL_MAILITMS_NO'=>4,'LINKED_MAILITMS_NO'=>4,
            ]],
            'events'=>[],'items'=>[],'manifests'=>[],'truncated'=>false,
        ]);

        $this->actingAs($user)->get('/marbetes?marbete='.$dispatchCode)->assertOk()
            ->assertSee('Identificador UPU S8')
            ->assertSee('CTCI de origen')
            ->assertSee('DESPACHO UPU ENCONTRADO')
            ->assertSee('Sacas relacionadas con este despacho')
            ->assertSee('Sacas vinculadas en IPS')
            ->assertSee('La cantidad coincide')
            ->assertSee('BEBRUABOLPBBAEN60073003000227')
            ->assertSeeText('Paquetes en eventos / declarados')
            ->assertSeeText('4 en eventos')
            ->assertSee('Abrir saca');
    }

    public function test_ips_user_can_search_an_s9_receptacle_and_see_its_decoded_upu_fields(): void
    {
        $user = $this->operator();
        $user->givePermissionTo(Permission::findOrCreate('ips.read', 'web'));
        $bagCode = 'BEBRUABOLPBBAEN60073003000227';
        $this->mock(ReceptacleSearchService::class)->shouldReceive('search')->once()->with($bagCode)->andReturn([
            'identifier'=>$bagCode,
            'search_type'=>'s9',
            'code_analysis'=>\App\Services\Postal\UpuDispatchIdentifier::parse($bagCode),
            'dispatches'=>[],
            'receptacles'=>[(object)[
                'RECPTCL_PID'=>501,'RECPTCL_FID'=>$bagCode,'RECPTCL_WEIGHT'=>22.7,'RECPTCL_MAILITMS_NO'=>0,
                'RECPTCL_SEAL_NUMBER'=>null,'MAIL_SUBCLASS_FCD'=>'N','RECPTCL_REG_NO'=>null,'RECPTCL_INS_NO'=>null,
                'COMMENTS'=>null,'DESPTCH_PID'=>91,'DESPTCH_FID'=>'BEBRUABOLPBBAEN60073','ORIG_OFFICE_FCD'=>'BEBRUA',
                'DEST_OFFICE_FCD'=>'BOLPBB','DESPTCH_DEPARTURE_DT'=>'2026-09-25','DESPTCH_RECPTCLS_NO'=>1,
                'DESPTCH_WEIGHT'=>22.7,'CONVEYANCE_TYPE_CD'=>'Aereo',
            ]],
            'events'=>[],'items'=>[],'manifests'=>[],'truncated'=>false,
        ]);

        $this->actingAs($user)->get('/marbetes?marbete='.$bagCode)->assertOk()
            ->assertSee('Identificador UPU S9')
            ->assertSee('Número de saca (21–23)')
            ->assertSee('22,7 kg')
            ->assertSee('BEBRUABOLPBBAEN60073')
            ->assertSee('Historial de la saca')
            ->assertSee('Ver despacho y sus sacas')
            ->assertSee('Paquetes vinculados en eventos IPS')
            ->assertSee('Paquetes registrados en la saca');
    }

    public function test_ips_user_can_search_and_page_through_the_dispatch_register(): void
    {
        $user = $this->operator();
        $user->givePermissionTo(Permission::findOrCreate('ips.read', 'web'));
        $page = new \Illuminate\Pagination\LengthAwarePaginator(collect([(object)[
            'DESPTCH_PID'=>91,'DESPTCH_FID'=>'BOLPBCUSMIAAACN60027','ORIG_OFFICE_FCD'=>'BOLPBC','DEST_OFFICE_FCD'=>'USMIAA',
            'DESPTCH_WEIGHT'=>58.3,'DESPTCH_DEPARTURE_DT'=>'2026-09-25 14:40:00','DESPTCH_RECPTCLS_NO'=>4,
            'LINKED_RECPTCLS_NO'=>4,'DECLARED_MAILITMS_NO'=>7,'LINKED_MAILITMS_NO'=>7,'CONVEYANCE_TYPE_CD'=>'Aéreo',
        ]]), 1, 25, 1, ['path'=>url('/despachos')]);
        $this->mock(ReceptacleSearchService::class)->shouldReceive('listDispatches')->once()->with([
            'buscar'=>'BOLPBCUSMIAAACN60027','desde'=>'2026-09-25','hasta'=>null,'por_pagina'=>25,
        ])->andReturn($page);

        $this->actingAs($user)->get('/despachos?buscar=BOLPBCUSMIAAACN60027&desde=2026-09-25&por_pagina=25')
            ->assertOk()->assertSee('Registro de despachos')->assertSee('Despachos IPS')
            ->assertSee('BOLPBCUSMIAAACN60027')->assertSee('4')
            ->assertSee('Coincide')->assertSee('Ver sacas')
            ->assertSeeText('Despacho S8')->assertSeeText('Saca S9')->assertSeeText('Paquetes')
            ->assertSeeText('7 asociados en eventos')->assertSeeText('Cantidad anotada en sacas: 7');
    }

    public function test_cds_permission_does_not_allow_ips_and_renders_declaration(): void
    {
        config(['postal.cds_enabled'=>true]);
        $user = $this->operator();
        $user->givePermissionTo(Permission::findOrCreate('cds.read', 'web'));
        $this->mock(SqlServerSearchService::class)->shouldNotReceive('search');
        $this->mock(CdsRepository::class)->shouldReceive('search')->with(['EC253990191BE'])->andReturn($this->cdsData());
        $this->actingAs($user)->get('/consultas?codigo=EC253990191BE')->assertOk()->assertSee('Libros')->assertSee('Sin permiso de consulta')->assertSee('Imprimir CN23')->assertDontSee('<script>')
            ->assertDontSee('id="shipment"', false)->assertDontSee('id="movements"', false)->assertDontSee('id="documents"', false);
        $this->followingRedirects()->get('/consultas/documentos/aduana?codigo=EC253990191BE')
            ->assertOk()->assertSee('CN 23')->assertSee('Customs declaration')
            ->assertSee('Detailed description of contents')->assertSee('not the original official PDF')
            ->assertDontSee('COPIA RECONSTRUIDA')->assertSee('Remitente');
        $this->get('/consultas/documentos/marbete?codigo=EC253990191BE')->assertOk()->assertSee('MARBETE INTERNO');
    }

    public function test_deleted_cds_declarations_are_not_counted_as_active(): void
    {
        $code = 'EC257727105BE';
        $this->mock(CdsRepository::class)->shouldReceive('declarationIndex')->once()->with([$code], 1000)->andReturn([
            'packages'=>[(object)['MAIL_OBJECT_PID'=>501,'MAIL_OBJECT_ID'=>$code]],
            'declarations'=>[['id'=>'deleted-1','package_id'=>501,'state'=>'Deleted']],
            'truncated'=>false, 'packages_truncated'=>false, 'declarations_truncated'=>false,
        ]);

        $result = app(CustomsRemittanceBuilder::class)->indexForPackages([$code]);
        $this->assertSame('Solo declaración eliminada', $result['packages'][0]['status']);
        $this->assertFalse($result['packages'][0]['has_active_declaration']);
        $this->assertSame(0, $result['declaration_count']);
        $this->assertSame([$code], $result['deleted_declaration_codes']);
    }

    public function test_ips_failure_does_not_hide_cds(): void
    {
        config(['postal.cds_enabled'=>true]);
        $ips = $this->mock(SqlServerSearchService::class);
        $ips->shouldReceive('onConnection')->andReturnSelf();
        $ips->shouldReceive('search')->andThrow(new \RuntimeException('private connection detail'));
        $this->mock(CdsRepository::class)->shouldReceive('search')->andReturn($this->cdsData());
        $result = app(PostalWorkspace::class)->search('EC253990191BE', true, true);
        $this->assertSame('unavailable', $result['sources']['IPS']);
        $this->assertSame('ok', $result['sources']['CDS']);
        $this->assertStringNotContainsString('private connection detail', json_encode($result));
    }

    public function test_cds_failure_does_not_hide_ips(): void
    {
        config(['postal.cds_enabled'=>true]);
        $ips = $this->mock(SqlServerSearchService::class);
        $ips->shouldReceive('onConnection')->andReturnSelf();
        $ips->shouldReceive('search')->andReturn(['packageRows'=>collect([(object)['MAILITM_FID'=>'EC253990191BE']])]);
        $this->mock(CdsRepository::class)->shouldReceive('search')->andThrow(new \RuntimeException('offline'));
        $result = app(PostalWorkspace::class)->search('EC253990191BE', true, true);
        $this->assertSame('ok', $result['sources']['IPS']);
        $this->assertSame('unavailable', $result['sources']['CDS']);
    }

    public function test_ips_operator_can_filter_the_activity_report(): void
    {
        $user = $this->operator();
        $user->givePermissionTo(Permission::findOrCreate('ips.read', 'web'));
        $row = (object) [
            'EVENT_GMT_DT'=>'2026-09-25 10:00:00', 'MAILITM_FID'=>'EC25772105BE', 'MAILITM_LOCAL_ID'=>'LOCAL-1',
            'EVENT_NAME'=>'Paquete recibido', 'EVENT_TYPE_CD'=>'32', 'POSTAL_STATUS_NM'=>'En oficina',
            'MAIL_CLASS_NM'=>'Paquete', 'MAIL_CLASS_CD'=>'U', 'OFFICE_FCD'=>'LP-AO', 'OFFICE_NM'=>'La Paz',
            'NEXT_OFFICE_FCD'=>null, 'NEXT_OFFICE_NM'=>null, 'RECPTCL_FID'=>null,
            'USER_NM'=>'Operador de prueba', 'USER_FID'=>'operador', 'RETENTION_REASON_CD'=>null,
        ];
        $fixture = [
            'rows'=>collect([$row]), 'total'=>1, 'truncated'=>false,
            'offices'=>collect([(object)['code'=>'1','short_name'=>'LP-AO','name'=>'La Paz']]),
        ];
        $this->mock(PostalActivityReport::class)->shouldReceive('search')->once()->andReturn($fixture);

        $this->actingAs($user)
            ->get('/operaciones-postales?desde=2026-09-24&hasta=2026-09-25&oficina=1&buscar=EC25772105BE')
            ->assertOk()->assertSee('Actividad postal por oficina')->assertSee('EC25772105BE')
            ->assertSee('Paquete recibido')->assertSee('1 vigentes en IPS')->assertSee('aunque no tengan movimientos en este periodo');
    }

    public function test_ips_operator_can_download_a_formula_safe_postal_csv(): void
    {
        $user = $this->operator();
        $user->givePermissionTo(Permission::findOrCreate('ips.read', 'web'));
        $event = (object) [
            'MAILITM_FID'=>'EC25772105BE','MAILITM_LOCAL_ID'=>null,'EVENT_GMT_DT'=>'2026-09-25 10:00:00',
            'EVENT_TYPE_CD'=>'32','EVENT_TYPE_NM_ES'=>'Paquete recibido','OFFICE_FCD'=>'LP-AO','OFFICE_NM'=>'La Paz',
            'NEXT_OFFICE_FCD'=>null,'NEXT_OFFICE_NM'=>null,'USER_NM'=>'=FORMULA()','USER_FID'=>'operator',
            'CONDITION_TXT'=>null,'RETENTION_REASON_CD'=>null,'ATTEMPTED_DELIVERY_LOCATION'=>null,
        ];
        $data = [
            'code'=>'EC25772105BE', 'sources'=>['IPS'=>'ok','CDS'=>'forbidden'], 'cds'=>[],
            'ips'=>['trackingRows'=>collect([$event])],
        ];
        $this->mock(PostalWorkspace::class)->shouldReceive('search')->once()->with('EC25772105BE', true, false)->andReturn($data);

        $response = $this->actingAs($user)->get('/consultas/expediente.csv?codigo=EC25772105BE')->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Tipo de registro', $csv);
        $this->assertStringContainsString("'=FORMULA()", $csv);
    }

    private function cdsData(): array
    {
        return ['packages'=>[(object)['MAIL_OBJECT_ID'=>'EC253990191BE','MAIL_STATE_NM'=>'Registrado']], 'declarations'=>[
            ['id'=>'declaration-test','package_id'=>'package-test','state'=>'Registrada', 'data'=>['status'=>'ok','fields'=>['SNm'=>'Remitente'], 'pieces'=>[['Desc'=>'Libros','No'=>'2']], 'documents'=>[]]],
        ],'responses'=>[], 'events'=>[], 'truncated'=>false];
    }

    public function test_expediente_preserves_details_and_groups_cds_documents_once_under_their_object(): void
    {
        $user = $this->operator(true);
        $code = 'EC253990191BE';
        $declaration = $this->cdsData()['declarations'][0];
        $declaration['package_id'] = '402';
        $unassigned = array_replace($declaration, ['id'=>'unassigned-declaration', 'package_id'=>'999']);
        $response = ['id'=>'response-inbound', 'package_id'=>'402', 'decision'=>'Liberado', 'state'=>'Final',
            'data'=>['status'=>'ok', 'fields'=>['DecisReasNm'=>'Decisión de prueba']]];
        $dispatch = 'BEBRUABOLPBBAEN60073';
        $bag = 'BEBRUABOLPBBAEN60073003000227';
        $workspaceData = [
            'code'=>$code, 'identifier'=>['type'=>'Código S10', 'valid'=>true], 'sources'=>['IPS'=>'ok', 'CDS'=>'ok'],
            'ips'=>[
                'packageRows'=>[(object)['MAILITM_FID'=>$code, 'ORIG_COUNTRY_NM'=>'Bélgica', 'DEST_COUNTRY_NM'=>'Bolivia', 'MAILITM_WEIGHT'=>0.3]],
                'trackingRows'=>[(object)['EVENT_TYPE_CD'=>40, 'EVENT_TYPE_NM_ES'=>'Entrega de prueba', 'EVENT_GMT_DT'=>now('UTC')->format('Y-m-d H:i:s'), 'OFFICE_NM'=>'La Paz', 'USER_NM'=>'Operador IPS']],
                'deliveryRows'=>[(object)['EVENT_TYPE_NM_ES'=>'Entrega de prueba', 'NON_DELIVERY_REASON_CD'=>null, 'NON_DELIVERY_MEASURE_CD'=>null, 'SIGNATORY_NM'=>'Receptor de prueba', 'DELIV_LOCATION'=>'Ventanilla', 'DELIV_POSTCODE'=>'0000']],
                'ediRows'=>[(object)['EVENT_LOCAL_DT'=>'2026-09-25', 'EVENT_TYPE_NM_ES'=>'Aviso internacional', 'EVENT_TYPE_CD'=>'EMD', 'LOCATION_ID'=>'BOLPBB', 'SENDER_ID'=>'BE', 'DESPATCH_NUMBER'=>'0073']],
                'logisticRows'=>[(object)['RECPTCL_PID'=>50, 'RECPTCL_FID'=>$bag, 'DESPTCH_FID'=>$dispatch, 'ORIG_OFFICE_FCD'=>'BEBRUA', 'DEST_OFFICE_FCD'=>'BOLPBB']],
                'manifestRows'=>[(object)['MANIFEST_LIST_ID'=>'manifest-test', 'FORM_NM'=>'CN31', 'USER_NM'=>'Operador de formulario']],
                'contentPieceRows'=>[(object)['DESCRIPTION'=>'Contenido IPS de prueba', 'TARIFF_HEADING'=>'4901', 'ORIGIN_LOCATION'=>'BE']],
            ],
            'cds'=>[
                'packages'=>[(object)['MAIL_OBJECT_PID'=>'501', 'MAIL_OBJECT_ID'=>$code, 'MAIL_FLOW_CD'=>'O', 'MAIL_STATE_NM'=>'Departed OOE'],
                    (object)['MAIL_OBJECT_PID'=>'402', 'MAIL_OBJECT_ID'=>$code, 'MAIL_FLOW_CD'=>'I', 'MAIL_STATE_NM'=>'Registered']],
                'declarations'=>[$declaration, $unassigned], 'responses'=>[$response],
                'events'=>[['occurred_at'=>'2026-09-25T12:00:00Z', 'name'=>'Registro de prueba', 'user_name'=>'Operador CDS', 'office'=>'La Paz', 'kind'=>'declarations', 'record_id'=>'declaration-test']],
                'truncated'=>false,
            ],
        ];
        $this->mock(PostalWorkspace::class)->shouldReceive('search')->once()->with($code, true, true)->andReturn($workspaceData);
        $result = $this->actingAs($user)->get('/conjunto?codigo='.$code)->assertOk()
            ->assertSee('Receptor de prueba')->assertSee('Aviso internacional')->assertSee('Contenido IPS de prueba')
            ->assertSee('CN31')->assertSee('Operador de formulario')->assertSee('Operador CDS')
            ->assertSee('href="'.route('postal.receptacles', ['marbete'=>$bag]).'"', false)
            ->assertSee('href="'.route('postal.receptacles', ['marbete'=>$dispatch]).'"', false);
        $document = new \DOMDocument();
        $document->loadHTML('<?xml encoding="UTF-8">'.$result->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        $inbound = '//article[contains(@class, "postal-cds-record")][.//span[normalize-space(.)="ID objeto 402"]]';
        $outbound = '//article[contains(@class, "postal-cds-record")][.//span[normalize-space(.)="ID objeto 501"]]';
        $this->assertSame(2, $xpath->query('//article[@class="postal-declaration"]')->length);
        $this->assertSame(1, $xpath->query($inbound.'//article[@class="postal-declaration"]')->length);
        $this->assertSame(0, $xpath->query($outbound.'//article[@class="postal-declaration"]')->length);
        $this->assertSame(1, $xpath->query('//article[@class="postal-response"]')->length);
        $this->assertSame(1, $xpath->query($inbound.'//article[@class="postal-response"]')->length);
        $this->assertSame(1, $xpath->query('//div[contains(@class, "postal-cds-record")]/article[@class="postal-declaration"][contains(., "unassigned-declaration")]')->length);
        foreach (['delivery-records', 'international'] as $section) {
            $this->assertSame(1, $xpath->query('//section[@id="movements"]//details[@id="'.$section.'"]')->length);
        }
        $this->assertSame(1, $xpath->query('//section[@id="customs"]//details[@id="responsibles"]')->length);
    }

    public function test_only_admin_can_change_permissions_or_assign_roles(): void
    {
        $this->actingAs($this->operator());
        $this->get('/accesos')->assertForbidden();
        $this->get('/users')->assertForbidden();
        $this->post('/role-has-permission', ['permission_id'=>1,'role_id'=>1])->assertForbidden();
        $this->post('/users', [])->assertForbidden();
    }

    public function test_admin_can_assign_and_revoke_cds_permission_using_access_screen(): void
    {
        $operator = $this->operator();
        $role = Role::findOrCreate('consulta_aduana', 'web');
        $operator->assignRole($role);
        $permission = Permission::findByName('cds.read', 'web');
        $this->actingAs($this->operator(true))->get('/accesos?role='.$role->id)->assertOk()->assertSee('Consultar CDS');
        $this->put('/accesos/'.$role->id, ['permissions'=>[$permission->id]])->assertRedirect();
        $this->assertTrue($operator->fresh()->can('postal.cds'));
        $this->put('/accesos/'.$role->id, [])->assertRedirect();
        $this->assertFalse($operator->fresh()->can('postal.cds'));
    }

    public function test_cds_print_requires_cds_permission_even_when_ips_is_allowed(): void
    {
        $user = $this->operator();
        $user->givePermissionTo(Permission::findByName('ips.read', 'web'));
        $this->mock(CdsRepository::class)->shouldNotReceive('search');
        $this->actingAs($user)->get('/consultas/documentos/aduana?codigo=TEST')->assertForbidden();
    }

    public function test_cds_declaration_can_be_printed_in_cn23_ems_layout_from_available_fields(): void
    {
        config(['postal.cds_enabled'=>true]);
        $user = $this->operator();
        $user->givePermissionTo(Permission::findOrCreate('cds.read', 'web'));
        $workspaceData = [
            'code'=>'EE000722051BO',
            'sources'=>['IPS'=>'forbidden','CDS'=>'ok'],
            'ips'=>['packageRows'=>collect(), 'trackingRows'=>collect(), 'deliveryRows'=>collect()],
            'cds'=>[
                'packages'=>[(object)[
                    'MAIL_OBJECT_PID'=>'package-1', 'MAIL_OBJECT_ID'=>'EE000722051BO',
                    'MAIL_CLASS_CD'=>'E', 'DEST_POST_ORGANIZATION_CD'=>'AGBC',
                    'POSTING_DATE'=>'2026-09-25 12:43:00',
                ]],
                'declarations'=>[ [
                    'id'=>'declaration-test', 'package_id'=>'package-1', 'state'=>'Registrada',
                    'declaration_number'=>'AN123456789BO', 'nature'=>'Muestra comercial',
                    'countries'=>['US'=>'Estados Unidos','BO'=>'Bolivia'],
                    'data'=>[
                        'status'=>'ok',
                        'fields'=>[
                            'SNm'=>'Remitente de prueba','SAdL1'=>'Calle de origen','SAdL2'=>'Zona central','SCtr'=>'US',
                            'RNm'=>'Destinatario de prueba','RAdL1'=>'Avenida de destino','RCtr'=>'BO',
                            'GWgt'=>'1.2','Ptg'=>'5.00','PtgCur'=>'USD','NTyp'=>'32',
                            'TotCPNo'=>'1','TotNWgt'=>'1.0','TotCPVal'=>'10.00','TotalCPValCur'=>'USD',
                        ],
                        'pieces'=>[['Desc'=>'Libros de prueba','No'=>'1','Amt'=>'10.00','Cur'=>'USD','NWgt'=>'1.0','HS'=>'4901','OCtr'=>'US']],
                        'documents'=>[],
                    ],
                ] ],
                'events'=>[[
                    'kind'=>'declarations', 'record_id'=>'declaration-test',
                    'occurred_at'=>'2026-09-25T17:11:00Z',
                ]], 'responses'=>[],
            ],
        ];
        $this->mock(PostalWorkspace::class)->shouldReceive('search')->once()
            ->with('EE000722051BO', false, true)->andReturn($workspaceData);

        $response = $this->actingAs($user)->get('/consultas/declaracion/imprimir?codigo=EE000722051BO&declaracion=declaration-test');
        $response->assertOk()->assertSee('CN 23')->assertSee('EMS')->assertSee('Customs declaration')
            ->assertSee('Detailed description of contents')->assertSee('reference (if any)')
            ->assertSee('Remitente de prueba')->assertSee('Calle de origen Zona central')->assertSee('AGBC')
            ->assertSee('Libros de prueba')->assertSee('4901')
            ->assertSee('09/25/2026 12:43')
            ->assertSee('09/25/2026 13:11')
            ->assertSee('not the original official PDF')->assertDontSee('COPIA RECONSTRUIDA');
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
    }
}
