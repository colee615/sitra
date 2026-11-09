<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\IpsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class IpsUserOfficesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.connections.ips_user_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'ips.connection' => 'ips_user_fixture',
        ]);
        $db = DB::connection('ips_user_fixture');
        $db->statement("ATTACH DATABASE ':memory:' AS dbo");
        $db->statement('CREATE TABLE dbo.L_USERS (USER_PID INTEGER, USER_FID TEXT, USER_NM TEXT, USER_DOMAIN TEXT, USER_TYPE TEXT, OWN_OFFICE_CD INTEGER, VALID_IND TEXT, IPSWEB TEXT, RESTRICT_USER_OFFICES INTEGER, E_MAIL_ADDRESS TEXT)');
        $db->statement('CREATE TABLE dbo.N_OWN_OFFICES (OWN_OFFICE_CD INTEGER, OFFICE_FCD TEXT, OFFICE_NM TEXT, VALID_IND TEXT)');
        $db->statement('CREATE TABLE dbo.L_USERS_OFFICES (USER_PID INTEGER, OWN_OFFICE_CD INTEGER)');
        $db->table('dbo.N_OWN_OFFICES')->insert([
            ['OWN_OFFICE_CD' => 1, 'OFFICE_FCD' => 'CORE', 'OFFICE_NM' => 'Oficina principal', 'VALID_IND' => '3'],
            ['OWN_OFFICE_CD' => 2, 'OFFICE_FCD' => 'BOLPBA', 'OFFICE_NM' => 'La Paz', 'VALID_IND' => '1'],
            ['OWN_OFFICE_CD' => 3, 'OFFICE_FCD' => 'BOSRZA', 'OFFICE_NM' => 'Santa Cruz', 'VALID_IND' => '3'],
            ['OWN_OFFICE_CD' => 4, 'OFFICE_FCD' => 'INACTIVE', 'OFFICE_NM' => 'Inactiva', 'VALID_IND' => '0'],
        ]);
        foreach ([73 => 1, 74 => null] as $pid => $office) {
            $db->table('dbo.L_USERS')->insert([
                'USER_PID' => $pid, 'USER_FID' => 'operador'.$pid, 'USER_NM' => 'Operador '.$pid,
                'USER_DOMAIN' => 'AGBC', 'USER_TYPE' => '1', 'OWN_OFFICE_CD' => $office,
                'VALID_IND' => '3', 'IPSWEB' => 'Y', 'RESTRICT_USER_OFFICES' => 1,
            ]);
        }
        $db->table('dbo.L_USERS_OFFICES')->insert([
            ['USER_PID' => 73, 'OWN_OFFICE_CD' => 1],
            ['USER_PID' => 73, 'OWN_OFFICE_CD' => 2],
            ['USER_PID' => 73, 'OWN_OFFICE_CD' => 2],
            ['USER_PID' => 73, 'OWN_OFFICE_CD' => 4],
            ['USER_PID' => 74, 'OWN_OFFICE_CD' => 3],
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge('ips_user_fixture');
        parent::tearDown();
    }

    public function test_user_detail_includes_primary_and_all_active_assigned_offices_without_duplicates(): void
    {
        $user = app(IpsRepository::class)->user(73);

        $this->assertSame(1, $user['office_cd']);
        $this->assertSame([1, 2], array_column($user['offices'], 'office_cd'));
        $this->assertSame('BOLPBA', $user['offices'][1]['office_fcd']);
        $this->assertSame('La Paz', $user['offices'][1]['office_name']);
    }

    public function test_user_without_primary_office_keeps_their_assigned_offices(): void
    {
        $user = app(IpsRepository::class)->user(74);

        $this->assertNull($user['office_cd']);
        $this->assertSame([3], array_column($user['offices'], 'office_cd'));
    }

    public function test_paginated_candidates_keep_each_users_own_offices(): void
    {
        $ips = app(IpsRepository::class);
        $first = $ips->users(['per_page' => 1, 'page' => 1]);
        $second = $ips->users(['per_page' => 1, 'page' => 2]);

        $this->assertTrue($first['meta']['has_more']);
        $this->assertSame([1, 2], array_column($first['data'][0]['offices'], 'office_cd'));
        $this->assertFalse($second['meta']['has_more']);
        $this->assertSame([3], array_column($second['data'][0]['offices'], 'office_cd'));
        $this->assertSame([], $ips->users(['q' => 'no-existe'])['data']);
    }

    public function test_authenticated_api_returns_all_offices_in_directory_and_user_detail(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('admin', 'web'));
        $this->withToken($admin->createToken('office-directory', ['ips.read'])->plainTextToken);

        $expected = [
            ['office_cd' => 1, 'office_fcd' => 'CORE', 'office_name' => 'Oficina principal'],
            ['office_cd' => 2, 'office_fcd' => 'BOLPBA', 'office_name' => 'La Paz'],
        ];

        $this->getJson('/api/v1/ips/usuarios?q=operador73')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.office_cd', 1)
            ->assertJsonPath('data.0.offices', $expected);

        $this->getJson('/api/v1/ips/usuarios/73')
            ->assertOk()
            ->assertJsonPath('data.office_cd', 1)
            ->assertJsonPath('data.offices', $expected);
    }
}
