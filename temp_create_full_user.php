<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

$email = 'qa.admin@sitra.test';
$plainPassword = 'Admin12345!';

$user = User::withTrashed()->firstOrNew(['email' => $email]);
$user->name = 'QA Admin';
$user->password = bcrypt($plainPassword);

if ($user->exists && method_exists($user, 'trashed') && $user->trashed()) {
    $user->restore();
} else {
    $user->save();
}

$adminRole = Role::firstOrCreate([
    'name' => 'admin',
    'guard_name' => 'web',
]);

$user->syncRoles([$adminRole->name]);

$allPermissions = Permission::pluck('name')->all();
if (!empty($allPermissions)) {
    $user->syncPermissions($allPermissions);
}

echo "USER_CREATED\n";
echo "email: {$email}\n";
echo "password: {$plainPassword}\n";
echo "roles: " . implode(',', $user->getRoleNames()->toArray()) . "\n";
echo "permissions_count: " . $user->getAllPermissions()->count() . "\n";
