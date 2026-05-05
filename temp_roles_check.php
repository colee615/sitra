<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo 'roles=' . Spatie\Permission\Models\Role::count() . PHP_EOL;
foreach (Spatie\Permission\Models\Role::select('id','name')->get() as $r) {
  echo $r->id . ':' . $r->name . PHP_EOL;
}
