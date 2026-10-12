<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Postal\CdsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CdsDeclarationApiTest extends TestCase
{
    use RefreshDatabase;

    private function token(array $abilities, bool $admin = true): string
    {
        $user = User::factory()->create();
        if ($admin) {
            $user->assignRole(Role::findOrCreate('admin', 'web'));
        }

        return $user->createToken('cds-test', $abilities)->plainTextToken;
    }

    public function test_cds_declaration_lookup_returns_full_declaration_and_bolivian_department(): void
    {
        config(['postal.cds_enabled' => true]);
        $cds = Mockery::mock(CdsRepository::class);
        $cds->shouldReceive('declarationIndex')
            ->once()
            ->with(['LP666718164MY'], 1000, true)
            ->andReturn([
                'packages' => [(object) [
                    'MAIL_OBJECT_PID' => 5767,
                    'MAIL_OBJECT_ID' => 'LP666718164MY',
                    'MAIL_OBJECT_LOCAL_ID' => null,
                    'MAIL_OBJECT_LOCAL_ID2' => null,
                    'MAIL_STATE_NM' => 'Sent to customs',
                    'POSTING_DATE' => '2026-07-16 00:00:00',
                ]],
                'declarations' => [[
                    'id' => 5867,
                    'package_id' => 5767,
                    'declaration_number' => 'CN23-1',
                    'state' => 'Sent to customs',
                    'workflow_stage' => 'Enviada a Aduana',
                    'data_status' => 'ok',
                    'content_summary' => '1 artículo(s)',
                    'piece_count' => 1,
                    'document_count' => 0,
                    'data' => [
                        'fields' => [
                            'RNm' => 'Destinatario',
                            'RCty' => 'Tarija',
                            'RSta' => 'Tarija',
                            'RCtr' => 'BO',
                        ],
                        'pieces' => [['Desc' => 'Pieza de prueba']],
                        'documents' => [],
                    ],
                ]],
                'truncated' => false,
            ]);
        $this->app->instance(CdsRepository::class, $cds);

        $this->withToken($this->token(['cds.read']))
            ->postJson('/api/tracking/cds/declaraciones', ['codes' => ['lp666718164my']])
            ->assertOk()
            ->assertJsonPath('data.0.codigo', 'LP666718164MY')
            ->assertJsonPath('data.0.declaracion_cds.estado', 'declarada')
            ->assertJsonPath('data.0.declaracion_cds.objetos.0.declaraciones.0.destino_declarado.departamento', 'Tarija')
            ->assertJsonPath('data.0.declaracion_cds.objetos.0.declaraciones.0.destino_declarado.ciudad', 'Tarija')
            ->assertJsonPath('data.0.destinos_declarados.0.departamento', 'Tarija')
            ->assertJsonPath('data.0.declaracion_cds.objetos.0.declaraciones.0.articulos.0.Desc', 'Pieza de prueba');
    }

    public function test_get_cds_declaration_lookup_accepts_codes_as_query_parameters(): void
    {
        config(['postal.cds_enabled' => true]);
        $cds = Mockery::mock(CdsRepository::class);
        $cds->shouldReceive('declarationIndex')
            ->once()
            ->with(['LP666718164MY'], 1000, true)
            ->andReturn([
                'packages' => [],
                'declarations' => [],
                'truncated' => false,
            ]);
        $this->app->instance(CdsRepository::class, $cds);

        $this->withToken($this->token(['cds.read']))
            ->getJson('/api/tracking/cds/declaraciones?'.http_build_query([
                'codes' => ['lp666718164my'],
            ]))
            ->assertOk()
            ->assertJsonPath('data.0.codigo', 'LP666718164MY');
    }

    public function test_get_cds_declaration_lookup_lists_all_packages_with_pagination(): void
    {
        config(['postal.cds_enabled' => true]);
        $cds = Mockery::mock(CdsRepository::class);
        $cds->shouldReceive('declarationPackageCodes')
            ->once()
            ->with(2, 2)
            ->andReturn([
                'codes' => ['EC257727105BE', 'EC257727106BE'],
                'total' => 3,
            ]);
        $cds->shouldReceive('declarationIndex')
            ->once()
            ->with(['EC257727105BE', 'EC257727106BE'], 1000, true)
            ->andReturn([
                'packages' => [
                    (object) ['MAIL_OBJECT_PID' => 101, 'MAIL_OBJECT_ID' => 'EC257727105BE'],
                    (object) ['MAIL_OBJECT_PID' => 102, 'MAIL_OBJECT_ID' => 'EC257727106BE'],
                ],
                'declarations' => [],
                'truncated' => false,
            ]);
        $this->app->instance(CdsRepository::class, $cds);

        $this->withToken($this->token(['cds.read']))
            ->getJson('/api/tracking/cds/declaraciones?page=2&per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.codigo', 'EC257727105BE')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_existing_tracking_token_can_access_cds_after_admin_grants_permission(): void
    {
        config(['postal.cds_enabled' => true]);
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin', 'web'));
        $plainToken = $user->createToken('existing-tracking', ['sqlserver.read'])->plainTextToken;
        $tokenId = $user->tokens()->firstOrFail()->id;

        $this->actingAs($user)
            ->post(route('api-credentials.grant-cds-read', $tokenId))
            ->assertRedirect(route('api-credentials.index'));

        $cds = Mockery::mock(CdsRepository::class);
        $cds->shouldReceive('declarationIndex')
            ->once()
            ->with(['LP666718164MY'], 1000, true)
            ->andReturn([
                'packages' => [],
                'declarations' => [],
                'truncated' => false,
            ]);
        $this->app->instance(CdsRepository::class, $cds);

        $this->withToken($plainToken)
            ->getJson('/api/tracking/cds/declaraciones?'.http_build_query([
                'codes' => ['LP666718164MY'],
            ]))
            ->assertOk()
            ->assertJsonPath('data.0.codigo', 'LP666718164MY');
    }

    public function test_cds_declaration_lookup_requires_only_cds_read_ability_for_admin_token(): void
    {
        $this->mock(CdsRepository::class)->shouldNotReceive('declarationIndex');

        $this->withToken($this->token(['sqlserver.read']))
            ->postJson('/api/tracking/cds/declaraciones', ['codes' => ['LP666718164MY']])
            ->assertForbidden()
            ->assertJsonPath('message', 'El token no tiene permiso para consultar declaraciones de CDS.');
    }

    public function test_cds_declaration_lookup_rejects_non_admin_tokens(): void
    {
        $this->mock(CdsRepository::class)->shouldNotReceive('declarationIndex');

        $this->withToken($this->token(['cds.read'], false))
            ->postJson('/api/tracking/cds/declaraciones', ['codes' => ['LP666718164MY']])
            ->assertForbidden()
            ->assertJsonPath('message', 'No autorizado para consultar declaraciones CDS.');
    }
}
