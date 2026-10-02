<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Postal\DashboardFilters;
use App\Services\Postal\OperationalDashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OperationalDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function filters(array $extra = []): array
    {
        return array_merge(['from' => '2026-09-01', 'to' => '2026-09-02', 'office' => null, 'service' => null, 'state' => null, 'origin' => null, 'destination' => null, 'type' => null], $extra);
    }

    private function fixture(): void
    {
        config(['database.connections.dashboard_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'postal.ips_read_connection' => 'dashboard_fixture']);
        $db = DB::connection('dashboard_fixture');
        $db->statement("ATTACH DATABASE ':memory:' AS dbo");
        $db->statement('CREATE TABLE dbo.L_MAILITMS (MAILITM_PID TEXT PRIMARY KEY, MAILITM_FID TEXT, MAIL_CLASS_CD TEXT, ORIG_COUNTRY_CD TEXT, DEST_COUNTRY_CD TEXT, MAILITM_WEIGHT NUMERIC, POSTAL_STATUS_CD INTEGER)');
        $db->statement('CREATE TABLE dbo.L_MAILITM_EVENTS (MAILITM_PID TEXT, EVENT_GMT_DT TEXT, EVENT_TYPE_CD INTEGER, EVENT_OFFICE_CD INTEGER, USER_PID INTEGER)');
        $db->statement('CREATE TABLE dbo.C_MAIL_CLASSES (MAIL_CLASS_CD TEXT, MAIL_CLASS_NM TEXT)');
        $db->statement('CREATE TABLE dbo.C_COUNTRIES (COUNTRY_CD TEXT, COUNTRY_NM TEXT)');
        $db->statement('CREATE TABLE dbo.N_OWN_OFFICES (OWN_OFFICE_CD INTEGER, OFFICE_NM TEXT)');
        $db->statement('CREATE TABLE dbo.C_EVENT_TYPES (EVENT_TYPE_CD INTEGER, EVENT_TYPE_NM TEXT)');
        $db->statement('CREATE TABLE dbo.CT_EVENT_TYPES (EVENT_TYPE_CD INTEGER, LANGUAGE_CD TEXT, LOCAL_EVENT_TYPE_NM TEXT)');
        $db->table('dbo.C_MAIL_CLASSES')->insert([['MAIL_CLASS_CD' => 'E', 'MAIL_CLASS_NM' => 'EMS'], ['MAIL_CLASS_CD' => 'C', 'MAIL_CLASS_NM' => 'Encomiendas']]);
        $db->table('dbo.N_OWN_OFFICES')->insert([['OWN_OFFICE_CD' => 0, 'OFFICE_NM' => 'Cochabamba'], ['OWN_OFFICE_CD' => 1, 'OFFICE_NM' => 'La Paz']]);
        $db->table('dbo.C_COUNTRIES')->insert([['COUNTRY_CD' => 'BO', 'COUNTRY_NM' => 'Bolivia'], ['COUNTRY_CD' => 'US', 'COUNTRY_NM' => 'Estados Unidos']]);
        foreach ([['A', 'E', 'US', 'BO', 2, 0], ['B', 'C', 'BO', 'BO', 3, 7], ['C', 'E', null, 'BO', null, 0], ['OLD', 'E', 'US', 'BO', 1, 0], ['AFTER', 'E', 'BO', 'BO', 1, 0]] as [$id,$service,$origin,$destination,$weight,$status]) {
            $db->table('dbo.L_MAILITMS')->insert(['MAILITM_PID' => $id, 'MAILITM_FID' => $id, 'MAIL_CLASS_CD' => $service, 'ORIG_COUNTRY_CD' => $origin, 'DEST_COUNTRY_CD' => $destination, 'MAILITM_WEIGHT' => $weight, 'POSTAL_STATUS_CD' => $status]);
        }
        foreach ([
            ['OLD', '2026-09-01 03:59:59', 32, 0], // Aug 31 in Bolivia: previous period.
            ['A', '2026-09-01 04:00:00', 32, 0], ['A', '2026-09-01 06:00:00', 32, 0], // two receipts, one package.
            ['A', '2026-09-02 02:00:00', 35, 0], // Sep 1 in Bolivia.
            ['A', '2026-09-02 15:00:00', 37, 1], ['A', '2026-09-02 16:00:00', 61, 1], // technical update preserves delivery.
            ['B', '2026-09-02 12:00:00', 32, 0], ['C', '2026-09-02 13:00:00', 36, 1],
            ['AFTER', '2026-09-03 04:00:00', 37, 0], // outside exclusive end.
        ] as [$id,$date,$event,$office]) {
            $db->table('dbo.L_MAILITM_EVENTS')->insert(['MAILITM_PID' => $id, 'EVENT_GMT_DT' => $date, 'EVENT_TYPE_CD' => $event, 'EVENT_OFFICE_CD' => $office, 'USER_PID' => 1]);
        }
    }

    public function test_counts_unique_packages_full_events_and_local_date_boundaries(): void
    {
        $this->fixture();
        $data = app(OperationalDashboard::class)->ips($this->filters());
        $this->assertSame(3, $data['totals']['packages']);
        $this->assertSame(7, $data['totals']['movements']);
        $this->assertSame(2, $data['totals']['received']);
        $this->assertSame(3, $data['totals']['entries']);
        $this->assertSame(1, $data['totals']['delivered']);
        $this->assertSame(1, (int) $data['states']['delivered']);
        $this->assertSame(1, $data['returns']);
        $this->assertSame(1, $data['previous']['packages']);
        $this->assertSame(3, $data['timeline'][0]['movements']);
        $this->assertSame('2026-09-01', $data['timeline'][0]['date']);
        $this->assertSame(4, $data['timeline'][1]['movements']);
        $this->assertSame(3, array_sum(array_column($data['services'], 'total')));
        $this->assertSame(5.0, (float) $data['weight']->total);
    }

    public function test_office_zero_and_state_filters_apply_to_every_aggregate(): void
    {
        $this->fixture();
        $dashboard = app(OperationalDashboard::class);
        $office = $dashboard->ips($this->filters(['office' => '0']));
        $this->assertSame(2, $office['totals']['packages']);
        $this->assertSame(4, $office['totals']['movements']);
        $this->assertSame(0, $office['totals']['delivered']);
        $delivered = $dashboard->ips($this->filters(['state' => 'delivered']));
        $this->assertSame(1, $delivered['totals']['packages']);
        $this->assertSame(5, $delivered['totals']['movements']);
        $this->assertSame(1, array_sum(array_column($delivered['services'], 'total')));
        $this->assertSame(5, array_sum(array_column($delivered['timeline'], 'movements')));
        $this->assertSame(['A'], array_column($delivered['recent'], 'code'));
    }

    public function test_filters_and_empty_period_do_not_fabricate_zero_source_failures(): void
    {
        $this->fixture();
        $dashboard = app(OperationalDashboard::class);
        foreach (['national' => 'B', 'international' => 'A', 'unknown' => 'C'] as $type => $id) {
            $data = $dashboard->ips($this->filters(['type' => $type]));
            $this->assertSame(1, $data['totals']['packages']);
            $this->assertSame($id, $data['recent'][0]->code);
        }
        $data = $dashboard->ips($this->filters(['service' => 'C', 'origin' => 'BO', 'destination' => 'BO']));
        $this->assertSame(1, $data['totals']['packages']);
        $empty = $dashboard->ips($this->filters(['from' => '2020-01-01', 'to' => '2020-01-02']));
        $this->assertSame('ok', $empty['status']);
        $this->assertSame(0, $empty['totals']['packages']);
        $this->assertCount(2, $empty['timeline']);
    }

    public function test_source_permissions_and_failure_are_independent(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('cds.read', 'web'));
        $mock = $this->mock(OperationalDashboard::class);
        $mock->shouldNotReceive('ips');
        $mock->shouldNotReceive('catalog');
        $mock->shouldReceive('cds')->once()->andThrow(new \RuntimeException('Private connection detail'));
        $this->actingAs($user)->getJson('/dashboard/datos?from=2026-09-01&to=2026-09-02')->assertOk()
            ->assertJsonPath('ips.status', 'forbidden')->assertJsonPath('cds.status', 'unavailable')->assertDontSee('Private connection detail');
    }

    public function test_invalid_filters_rejected_before_database_access(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('ips.read', 'web'));
        $this->mock(OperationalDashboard::class)->shouldNotReceive('ips');
        foreach (['from=2026-09-03&to=2026-09-01', 'from=2020-01-01&to=2026-01-01', 'from=not-a-date', 'office=-1', 'state=unknown', 'origin=BO%27'] as $query) {
            $this->actingAs($user)->getJson('/dashboard/datos?'.$query)->assertUnprocessable();
        }
    }

    public function test_guest_and_unprivileged_cannot_read_dashboard_data(): void
    {
        $this->getJson('/dashboard/datos')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/dashboard/datos')->assertForbidden();
    }

    public function test_cds_rejects_unmapped_filters_and_retains_office_zero(): void
    {
        config(['postal.cds_enabled' => true]);
        $filters = DashboardFilters::fromRequest(Request::create('/dashboard/datos', 'GET', $this->filters(['office' => '0'])));
        $this->assertSame('0', $filters['office']);
        $this->assertSame('unsupported', app(OperationalDashboard::class)->cds($filters)['status']);
    }

    public function test_admin_forms_render_without_legacy_form_dependency(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('admin', 'web'));
        foreach (['/role/create', '/permission/create', '/role-has-permission/create', '/users/create', '/profile', '/centro-de-trabajo'] as $url) {
            $this->actingAs($admin)->get($url)->assertOk()->assertSee('sitra-design.css');
        }
    }

    public function test_staff_exports_are_downloadable_and_do_not_expose_passwords(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('admin', 'web'));
        $this->actingAs($admin)->get('/users/excel')->assertOk()->assertDownload('sitra-personal.xlsx');
        $this->actingAs($admin)->get('/users/pdf')->assertOk()->assertDownload('sitra-personal.pdf');
        $export = new \App\Exports\UsersExport(collect([$admin]));
        $this->assertCount(4, $export->array()[0]);
        $this->assertNotContains($admin->password, $export->array()[0]);
    }

    public function test_administration_edit_forms_use_their_registered_put_routes(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('admin', 'web'));
        $role = Role::findOrCreate('operador-prueba', 'web');
        $permission = Permission::findOrCreate('prueba.consultar', 'web');
        foreach (['/role/'.$role->id.'/edit', '/permission/'.$permission->id.'/edit'] as $url) {
            $this->actingAs($admin)->get($url)->assertOk()->assertSee('name="_method" value="PUT"', false);
        }
        $this->actingAs($admin)->put('/role/'.$role->id, ['name' => 'operador-actualizado'])->assertRedirect('/roles');
        $this->actingAs($admin)->put('/permission/'.$permission->id, ['name' => 'prueba.actualizada'])->assertRedirect('/permissions');
        $this->assertDatabaseHas('roles', ['id' => $role->id, 'name' => 'operador-actualizado']);
        $this->assertDatabaseHas('permissions', ['id' => $permission->id, 'name' => 'prueba.actualizada']);
        $this->actingAs($admin)->post('/permission', ['name' => 'prueba.actualizada'])->assertSessionHasErrors('name');
    }

    public function test_an_ips_outage_does_not_hide_available_cds_data(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('admin', 'web'));
        $mock = $this->mock(OperationalDashboard::class);
        $mock->shouldReceive('catalog')->once()->andReturn([]);
        $mock->shouldReceive('ips')->once()->andThrow(new \RuntimeException('Offline'));
        $mock->shouldReceive('cds')->once()->andReturn(['status' => 'ok', 'objects' => 2]);
        $this->actingAs($admin)->getJson('/dashboard/datos')->assertOk()->assertJsonPath('ips.status', 'unavailable')->assertJsonPath('cds.objects', 2);
    }

    public function test_cds_totals_do_not_multiply_declarations_by_responses(): void
    {
        config(['postal.cds_enabled' => true, 'postal.cds_connection' => 'cds_fixture', 'database.connections.cds_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        $db = DB::connection('cds_fixture');
        $db->statement("ATTACH DATABASE ':memory:' AS dbo");
        $db->statement('CREATE TABLE dbo.O_MAIL_OBJECTS (MAIL_OBJECT_PID TEXT, MAIL_CLASS_CD TEXT, POSTING_DATE TEXT)');
        $db->statement('CREATE TABLE dbo.O_DECLARATIONS (MAIL_OBJECT_PID TEXT, CDS_STATE_CD INTEGER)');
        $db->statement('CREATE TABLE dbo.O_RESPONSES (MAIL_OBJECT_PID TEXT)');
        $db->statement('CREATE TABLE dbo.M_CDS_STATES (CDS_STATE_CD INTEGER, CDS_STATE_NM TEXT)');
        $db->table('dbo.O_MAIL_OBJECTS')->insert([
            ['MAIL_OBJECT_PID' => 'A', 'MAIL_CLASS_CD' => 'E', 'POSTING_DATE' => '2026-09-01 00:00:00'],
            ['MAIL_OBJECT_PID' => 'B', 'MAIL_CLASS_CD' => 'C', 'POSTING_DATE' => '2026-09-02 23:59:59'],
            ['MAIL_OBJECT_PID' => 'C', 'MAIL_CLASS_CD' => 'E', 'POSTING_DATE' => '2026-09-03 00:00:00'],
        ]);
        $db->table('dbo.O_DECLARATIONS')->insert([['MAIL_OBJECT_PID' => 'A', 'CDS_STATE_CD' => 1], ['MAIL_OBJECT_PID' => 'A', 'CDS_STATE_CD' => 1]]);
        $db->table('dbo.O_RESPONSES')->insert([['MAIL_OBJECT_PID' => 'A'], ['MAIL_OBJECT_PID' => 'A'], ['MAIL_OBJECT_PID' => 'A']]);
        $data = app(OperationalDashboard::class)->cds($this->filters());
        $this->assertSame(2, $data['objects']);
        $this->assertSame(2, $data['declarations']);
        $this->assertSame(3, $data['responses']);
        $this->assertSame(1, $data['withoutResponse']);
        $filtered = app(OperationalDashboard::class)->cds($this->filters(['service' => 'C']));
        $this->assertSame(1, $filtered['objects']);
        $this->assertSame(0, $filtered['declarations']);
    }
}
