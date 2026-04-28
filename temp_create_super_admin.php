<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$email = 'superadmin@sitra.local';
$passwordPlain = 'AdminSitra2026!';

DB::beginTransaction();
try {
    $existing = DB::table('users')->where('email', $email)->first();

    if ($existing) {
        DB::table('users')->where('id', $existing->id)->update([
            'name' => 'Super Admin',
            'password' => Hash::make($passwordPlain),
            'updated_at' => now(),
            'deleted_at' => null,
        ]);
        $superAdminId = $existing->id;
    } else {
        $superAdminId = DB::table('users')->insertGetId([
            'name' => 'Super Admin',
            'email' => $email,
            'password' => Hash::make($passwordPlain),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    DB::table('users')->where('id', '!=', $superAdminId)->delete();

    $adminRole = DB::table('roles')->where('name', 'admin')->first();
    if ($adminRole) {
        DB::table('model_has_roles')
            ->where('model_id', $superAdminId)
            ->where('model_type', 'App\\Models\\User')
            ->delete();

        DB::table('model_has_roles')->updateOrInsert([
            'role_id' => $adminRole->id,
            'model_id' => $superAdminId,
            'model_type' => 'App\\Models\\User',
        ], []);
    }

    DB::commit();

    echo "OK\n";
    echo "email={$email}\n";
    echo "password={$passwordPlain}\n";
    echo "id={$superAdminId}\n";
} catch (Throwable $e) {
    DB::rollBack();
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
