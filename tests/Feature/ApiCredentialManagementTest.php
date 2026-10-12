<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ApiCredentialManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin', 'web'));

        return $user;
    }

    public function test_admin_can_view_api_catalog_and_own_credentials_without_exposing_existing_token(): void
    {
        $user = $this->admin();
        $issued = $user->createToken('credential-hidden', ['cds.read'])->plainTextToken;

        $this->actingAs($user)
            ->get('/api-credentials')
            ->assertOk()
            ->assertSeeText('Credenciales API')
            ->assertSeeText('/api/tracking/cds/declaraciones')
            ->assertSeeText('/api/v1/ips/paquetes/{codigo}')
            ->assertSeeText('cds.read')
            ->assertSeeText('credential-hidden')
            ->assertDontSee($issued);
    }

    public function test_admin_can_create_and_retrieve_a_limited_token_saved_encrypted(): void
    {
        $user = $this->admin();
        $response = $this->actingAs($user)->post('/api-credentials', [
            'name' => 'CRM CDS',
            'ability' => 'cds.read',
            'expires_in' => '90',
        ]);

        $response->assertRedirect(route('api-credentials.index'));
        $record = PersonalAccessToken::query()->where('name', 'CRM CDS')->firstOrFail();
        $this->assertSame(['cds.read'], $record->abilities);
        $this->assertTrue($record->expires_at->isFuture());
        $this->assertNotSame($record->token, $record->secret_encrypted);
        $plainTextToken = Crypt::decryptString($record->secret_encrypted);

        $this->get(route('api-credentials.index'))
            ->assertSee($plainTextToken)
            ->assertSee(url('/api/tracking/cds/declaraciones'))
            ->assertSeeText('Token Bearer')
            ->assertSeeText('URLs para probar');
        $this->get(route('api-credentials.index'))->assertSee($plainTextToken);
    }

    public function test_new_credential_can_only_authorize_one_api_permission(): void
    {
        $user = $this->admin();

        $this->actingAs($user)->post('/api-credentials', [
            'name' => 'Combinada',
            'abilities' => ['sqlserver.read', 'cds.read'],
            'expires_in' => '90',
        ])->assertSessionHasErrors('ability');

        $this->assertDatabaseMissing('personal_access_tokens', ['name' => 'Combinada']);
    }

    public function test_admin_can_rotate_and_revoke_only_own_tokens(): void
    {
        $user = $this->admin();
        $oldToken = $user->createToken('CRM CDS', ['cds.read'])->accessToken;

        $response = $this->actingAs($user)->post(route('api-credentials.rotate', $oldToken->id));
        $response->assertRedirect(route('api-credentials.index'));
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $oldToken->id]);

        $newToken = PersonalAccessToken::query()->where('name', 'CRM CDS')->firstOrFail();
        $this->assertSame(['cds.read'], $newToken->abilities);
        $this->assertNotNull($newToken->secret_encrypted);
        $this->actingAs($user)->get(route('api-credentials.index'))
            ->assertSee(Crypt::decryptString($newToken->secret_encrypted));
        $this->delete(route('api-credentials.revoke', $newToken->id))
            ->assertRedirect(route('api-credentials.index'));
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $newToken->id]);
    }

    public function test_non_admin_cannot_manage_api_credentials(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/api-credentials')
            ->assertForbidden();
    }

    public function test_admin_cannot_revoke_another_users_token(): void
    {
        $admin = $this->admin();
        $otherAdmin = $this->admin();
        $otherToken = $otherAdmin->createToken('other integration', ['cds.read'])->accessToken;

        $this->actingAs($admin)
            ->delete(route('api-credentials.revoke', $otherToken->id))
            ->assertNotFound();
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherToken->id]);
    }
}
