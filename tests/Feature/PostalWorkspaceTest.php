<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Postal\CdsRepository;
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
        $this->get('/aduana')->assertForbidden();
        $this->get('/consultas/documentos/marbete?codigo=TEST')->assertForbidden();
        $this->get('/operaciones-postales')->assertForbidden();
        $this->get('/operaciones-postales/reporte.csv')->assertForbidden();
        $this->get('/dashboard')->assertOk()->assertDontSee('href="'.route('postal.customs').'"', false);
    }

    public function test_admin_can_open_workspace_without_database_queries(): void
    {
        $this->mock(SqlServerSearchService::class)->shouldNotReceive('search');
        $this->mock(CdsRepository::class)->shouldNotReceive('search');
        $this->actingAs($this->operator(true))->get('/consultas')->assertOk()->assertSee('Comienza con el código');
    }

    public function test_cds_permission_does_not_allow_ips_and_renders_declaration(): void
    {
        config(['postal.cds_enabled'=>true]);
        $user = $this->operator();
        $user->givePermissionTo(Permission::findOrCreate('cds.read', 'web'));
        $this->mock(SqlServerSearchService::class)->shouldNotReceive('search');
        $this->mock(CdsRepository::class)->shouldReceive('search')->with(['EC253990191BE'])->andReturn($this->cdsData());
        $this->actingAs($user)->get('/consultas?codigo=EC253990191BE')->assertOk()->assertSee('Libros')->assertSee('Sin permiso de consulta')->assertDontSee('<script>');
        $this->get('/consultas/documentos/aduana?codigo=EC253990191BE')->assertOk()->assertSee('No sustituye un formulario oficial');
        $this->get('/consultas/documentos/marbete?codigo=EC253990191BE')->assertOk()->assertSee('MARBETE INTERNO');
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
}
