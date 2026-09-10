<?php

namespace Tests\Feature;

use App\Exceptions\IpsOperationException;
use App\Models\User;
use App\Services\IpsRepository;
use App\Services\IpsWorkflowService;
use App\Services\SqlServerSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class IpsApiTest extends TestCase
{
    use RefreshDatabase;

    private function token(array $abilities, bool $admin = true): string
    {
        $user = User::factory()->create();
        if ($admin) {
            $user->assignRole(Role::findOrCreate('admin', 'web'));
        }

        return $user->createToken('test', $abilities)->plainTextToken;
    }

    private function delivery(): array
    {
        return [
            'occurred_at' => '2026-01-02T12:00:00-04:00',
            'office_cd' => 1, 'expected_event_cd' => 75,
            'expected_event_at' => '2026-01-01T12:00:00+00:00',
            'signatory' => 'Receptor de prueba',
        ];
    }

    public function test_anonymous_cannot_read_ips(): void
    {
        $this->getJson('/api/v1/ips/paquetes')->assertUnauthorized();
    }

    public function test_read_token_cannot_deliver(): void
    {
        $this->withToken($this->token(['ips.read']))
            ->postJson('/api/v1/ips/paquetes/TEST01/entrega', $this->delivery())->assertForbidden();
        $this->assertDatabaseCount('ips_operations', 0);
    }

    public function test_web_session_with_fake_bearer_does_not_bypass_token_permissions(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin', 'web'));
        $this->actingAs($user)->withToken('not-a-real-token')
            ->getJson('/api/v1/ips/paquetes')->assertForbidden();
    }

    public function test_non_admin_cannot_read_even_with_ability(): void
    {
        $this->withToken($this->token(['ips.read'], false))->getJson('/api/v1/ips/paquetes')->assertForbidden();
    }

    public function test_event_permission_cannot_bypass_delivery_permission(): void
    {
        $this->withToken($this->token(['ips.events']))->withHeader('Idempotency-Key', 'delivery-test-01')
            ->postJson('/api/v1/ips/paquetes/TEST01/eventos', $this->delivery() + ['event' => 'EMI'])
            ->assertForbidden();
    }

    public function test_delivery_requires_idempotency_signatory_and_expected_state(): void
    {
        $this->withToken($this->token(['ips.deliver']))->postJson('/api/v1/ips/paquetes/TEST01/entrega', [])
            ->assertUnprocessable()->assertJsonValidationErrors(['idempotency_key', 'signatory', 'expected_event_cd', 'expected_event_at', 'occurred_at', 'office_cd']);
    }

    public function test_delivery_rejects_future_or_timezone_less_timestamp(): void
    {
        $this->withToken($this->token(['ips.deliver']))->withHeader('Idempotency-Key', 'delivery-test-01');
        $this->postJson('/api/v1/ips/paquetes/TEST01/entrega', array_replace($this->delivery(), ['occurred_at' => '2099-01-01T12:00:00+00:00']))
            ->assertUnprocessable()->assertJsonValidationErrors(['occurred_at']);
        $this->postJson('/api/v1/ips/paquetes/TEST01/entrega', array_replace($this->delivery(), ['occurred_at' => '2026-01-01 12:00:00']))
            ->assertUnprocessable()->assertJsonValidationErrors(['occurred_at']);
    }

    public function test_delivery_is_durable_and_replayed_without_second_write(): void
    {
        $workflow = Mockery::mock(IpsWorkflowService::class);
        $workflow->shouldReceive('execute')->once()->with('event', Mockery::on(fn ($v) => $v['event'] === 'EMI'))
            ->andReturn(['codigo' => 'TEST01', 'event' => 'EMI', 'event_cd' => 37]);
        $this->app->instance(IpsWorkflowService::class, $workflow);
        $this->withToken($this->token(['ips.deliver']))->withHeader('Idempotency-Key', 'delivery-test-01');
        $first = $this->postJson('/api/v1/ips/paquetes/TEST01/entrega', $this->delivery())
            ->assertOk()->assertJsonPath('status', 'succeeded')->assertHeader('Idempotency-Replayed', 'false');
        $this->postJson('/api/v1/ips/paquetes/TEST01/entrega', $this->delivery())
            ->assertOk()->assertJsonPath('operation_id', $first->json('operation_id'))->assertHeader('Idempotency-Replayed', 'true');
        $this->assertDatabaseCount('ips_operations', 1);
        $this->assertDatabaseHas('ips_operations', ['status' => 'succeeded']);
    }

    public function test_reused_key_with_different_payload_is_conflict(): void
    {
        $workflow = Mockery::mock(IpsWorkflowService::class);
        $workflow->shouldReceive('execute')->once()->andReturn(['codigo' => 'TEST01', 'event' => 'EMI']);
        $this->app->instance(IpsWorkflowService::class, $workflow);
        $this->withToken($this->token(['ips.deliver']))->withHeader('Idempotency-Key', 'delivery-test-01');
        $this->postJson('/api/v1/ips/paquetes/TEST01/entrega', $this->delivery())->assertOk();
        $this->postJson('/api/v1/ips/paquetes/TEST01/entrega', array_replace($this->delivery(), ['signatory' => 'Otra persona']))->assertConflict();
    }

    public function test_uncertain_result_is_not_retried_or_reported_as_delivered(): void
    {
        $workflow = Mockery::mock(IpsWorkflowService::class);
        $workflow->shouldReceive('execute')->once()->andThrow(new \RuntimeException('secret connection detail'));
        $this->app->instance(IpsWorkflowService::class, $workflow);
        $this->withToken($this->token(['ips.deliver']))->withHeader('Idempotency-Key', 'delivery-test-01');
        $this->postJson('/api/v1/ips/paquetes/TEST01/entrega', $this->delivery())->assertStatus(503)
            ->assertJsonPath('status', 'uncertain')->assertDontSee('secret connection detail');
        $this->postJson('/api/v1/ips/paquetes/TEST01/entrega', $this->delivery())->assertConflict()->assertJsonPath('status', 'uncertain');
    }

    public function test_business_rejection_is_recorded_without_claiming_success(): void
    {
        $workflow = Mockery::mock(IpsWorkflowService::class);
        $workflow->shouldReceive('execute')->once()->andThrow(new IpsOperationException('El paquete ya fue entregado.'));
        $this->app->instance(IpsWorkflowService::class, $workflow);
        $this->withToken($this->token(['ips.deliver']))->withHeader('Idempotency-Key', 'delivery-test-01');
        $this->postJson('/api/v1/ips/paquetes/TEST01/entrega', $this->delivery())->assertConflict()->assertJsonPath('status', 'rejected');
        $this->assertDatabaseHas('ips_operations', ['status' => 'rejected']);
    }

    public function test_operation_status_is_visible_only_to_its_owner(): void
    {
        $workflow = Mockery::mock(IpsWorkflowService::class);
        $workflow->shouldReceive('execute')->once()->andReturn(['codigo' => 'TEST01']);
        $this->app->instance(IpsWorkflowService::class, $workflow);
        $ownerToken = $this->token(['ips.deliver', 'ips.operations']);
        $this->withToken($ownerToken)->withHeader('Idempotency-Key', 'delivery-test-01');
        $id = $this->postJson('/api/v1/ips/paquetes/TEST01/entrega', $this->delivery())->json('operation_id');
        $this->getJson('/api/v1/ips/operaciones/'.$id)->assertOk()->assertJsonPath('data.status', 'succeeded');
        $this->app['auth']->forgetGuards();
        $this->withToken($this->token(['ips.operations']))->getJson('/api/v1/ips/operaciones/'.$id)->assertNotFound();
    }

    public function test_pending_route_forces_pending_filter_and_handles_outage(): void
    {
        $ips = Mockery::mock(IpsRepository::class);
        $ips->shouldReceive('packages')->once()->with(Mockery::on(fn ($f) => $f['status'] === 'pending'))
            ->andThrow(new \RuntimeException('secret SQL'));
        $this->app->instance(IpsRepository::class, $ips);
        $this->withToken($this->token(['ips.read']))->getJson('/api/v1/ips/paquetes/pendientes-entrega?status=all')
            ->assertStatus(503)->assertDontSee('secret SQL');
    }

    public function test_create_requires_full_package_and_cannot_start_with_delivery(): void
    {
        $this->withToken($this->token(['ips.create']))->withHeader('Idempotency-Key', 'create-test-01');
        $this->postJson('/api/v1/ips/paquetes', ['codigo' => 'TEST01', 'event' => 'EMA'])
            ->assertUnprocessable()->assertJsonValidationErrors(['mail_class', 'origin_country', 'weight_kg', 'sender', 'recipient']);
    }

    public function test_web_routes_require_admin_and_old_cds_route_is_gone(): void
    {
        $this->get('/operaciones')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/operaciones')->assertForbidden();
        $this->get('/cds/datos')->assertNotFound();
        $this->assertNull(config('database.connections.sqlsrv2'));
    }

    public function test_existing_tracking_routes_remain_registered(): void
    {
        $uris = collect(app('router')->getRoutes())->map->uri();
        $this->assertTrue($uris->contains('api/tracking/eventos'));
        $this->assertTrue($uris->contains('api/tracking/eventos/batch'));
        $this->assertTrue($uris->contains('api/tracking/eventos-todos'));
        $this->assertTrue($uris->contains('api/tracking/paquetes'));
    }

    public function test_tracking_batch_endpoint_returns_grouped_events_for_requested_codes(): void
    {
        $service = Mockery::mock(SqlServerSearchService::class);
        $service->shouldReceive('searchManyEvents')
            ->once()
            ->with(['LX001NL', 'LX002NL'])
            ->andReturn([
                'LX001NL' => [
                    'codigo' => 'LX001NL',
                    'packageRows' => collect([(object) [
                        'MAILITM_PID' => 10,
                        'MAILITM_FID' => 'LX001NL',
                        'ORIG_COUNTRY_CD' => 'NL',
                        'ORIG_COUNTRY_NM' => 'Paises Bajos',
                        'DEST_COUNTRY_CD' => 'BO',
                        'DEST_COUNTRY_NM' => 'Bolivia',
                    ]]),
                    'trackingRows' => collect([(object) [
                        'MAILITM_PID' => 10,
                        'MAILITM_FID' => 'LX001NL',
                        'EVENT_TYPE_CD' => 31,
                        'EVENT_TYPE_NM_ES' => 'Enviado a control aduanero',
                        'EVENT_GMT_DT' => '2026-09-10 14:21:16',
                        'OFFICE_FCD' => 'LPB',
                        'OFFICE_NM' => 'LA PAZ',
                        'SOURCE_DB' => 'IPS5Db',
                    ]]),
                ],
                'LX002NL' => [
                    'codigo' => 'LX002NL',
                    'packageRows' => collect(),
                    'trackingRows' => collect(),
                ],
            ]);
        $this->app->instance(SqlServerSearchService::class, $service);

        $this->withToken($this->token(['sqlserver.read']))
            ->postJson('/api/tracking/eventos/batch', ['codigos' => [' lx001nl ', 'LX002NL']])
            ->assertOk()
            ->assertJsonPath('tipo', 'tracking_eventos_batch')
            ->assertJsonPath('total_codigos', 2)
            ->assertJsonPath('resultado.0.codigo', 'LX001NL')
            ->assertJsonPath('resultado.0.eventos_externos.0.codigo_evento', 31)
            ->assertJsonPath('resultado.1.codigo', 'LX002NL')
            ->assertJsonCount(0, 'resultado.1.eventos_externos');
    }

    public function test_disabled_writes_return_503_without_connecting_to_ips(): void
    {
        config(['ips.writes_enabled' => false, 'ips.connection' => 'connection_that_does_not_exist']);
        $this->withToken($this->token(['ips.deliver']))->withHeader('Idempotency-Key', 'disabled-write-01')
            ->postJson('/api/v1/ips/paquetes/TEST01/entrega', $this->delivery())->assertStatus(503)
            ->assertJsonPath('status', 'rejected')->assertJsonPath('message', 'Escrituras IPS deshabilitadas. Revise ips:diagnose y la configuración de integración.');
    }

    public function test_admin_operations_screen_renders_forms_without_external_calls(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin', 'web'));
        $ips = Mockery::mock(IpsRepository::class);
        $ips->shouldReceive('catalog')->once()->andReturn([
            'offices' => [], 'countries' => [], 'mail_classes' => [], 'non_delivery_reasons' => [], 'non_delivery_measures' => [],
        ]);
        $ips->shouldReceive('packages')->once()->andReturn(['data' => [], 'meta' => ['page' => 1, 'has_more' => false]]);
        $this->app->instance(IpsRepository::class, $ips);
        $this->actingAs($user)->get('/operaciones')->assertOk()->assertSee('Crear paquete en IPS')->assertDontSee('CDSDb');
    }
}
