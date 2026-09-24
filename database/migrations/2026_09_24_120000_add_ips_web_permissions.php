<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSIONS = [
        'ips.read',
        'ips.create',
        'ips.events',
        'ips.deliver',
        'ips.operations',
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::PERMISSIONS as $name) {
            DB::table('permissions')->insertOrIgnore([
                'name' => $name,
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Keep any definition already assigned to a role.
        DB::table('permissions')
            ->where('guard_name', 'web')
            ->whereIn('name', self::PERMISSIONS)
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('role_has_permissions')
                    ->whereColumn('role_has_permissions.permission_id', 'permissions.id');
            })
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
