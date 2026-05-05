<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Spatie\Permission\Models\Role;

$roles = ['admin','operador','consulta'];
foreach ($roles as $name) {
    Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
}

echo 'roles=' . Role::count() . PHP_EOL;
foreach (Role::orderBy('id')->get(['id','name']) as $r) {
    echo $r->id . ':' . $r->name . PHP_EOL;
}
