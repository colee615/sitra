<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class IpsWebAclTest extends TestCase
{
    use RefreshDatabase;

    public function test_ips_permissions_are_available_to_assign_in_acl(): void
    {
        foreach (['ips.read', 'ips.create', 'ips.events', 'ips.deliver', 'ips.operations'] as $name) {
            $this->assertDatabaseHas('permissions', ['name' => $name, 'guard_name' => 'web']);
        }
    }

    public function test_admin_role_does_not_bypass_ips_permissions(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin', 'web'));

        $this->assertFalse(Gate::forUser($user)->allows('ips.read'));
        $this->assertFalse(Gate::forUser($user)->allows('ips.deliver'));
    }

    public function test_admin_can_access_ips_when_permissions_are_assigned(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin', 'web'));
        $user->givePermissionTo([
            Permission::findByName('ips.read', 'web'),
            Permission::findByName('ips.deliver', 'web'),
        ]);

        $this->assertTrue(Gate::forUser($user)->allows('ips.read'));
        $this->assertTrue(Gate::forUser($user)->allows('ips.deliver'));
    }

    public function test_ips_read_permission_can_be_assigned_to_a_non_admin_role(): void
    {
        $user = User::factory()->create();
        $role = Role::findOrCreate('operador_ips', 'web');
        $role->givePermissionTo(Permission::findByName('ips.read', 'web'));
        $user->assignRole($role);

        $this->assertTrue(Gate::forUser($user)->allows('ips.read'));
        $this->assertFalse(Gate::forUser($user)->allows('ips.deliver'));
    }

    public function test_ips_pages_are_hidden_from_users_without_ips_read(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/operaciones')->assertForbidden();
        $this->actingAs($user)->get('/consultas')->assertForbidden();
    }

    public function test_ips_menu_is_hidden_from_admin_without_ips_read(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin', 'web'));

        $this->actingAs($user)->get('/dashboard')->assertOk()->assertDontSee('fas fa-globe-americas');
    }

    public function test_ips_menu_is_visible_when_ips_read_is_assigned(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin', 'web'));
        $user->givePermissionTo(Permission::findByName('ips.read', 'web'));

        $this->assertTrue(Gate::forUser($user)->allows('ips.read'));
        $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('fas fa-globe-americas');
    }
}
