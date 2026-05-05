<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    $exists = DB::select("SELECT to_regclass('public.personal_access_tokens') AS t");
    $table = $exists[0]->t ?? null;
    if (!$table) {
        echo "NO_TABLE\n";
        exit;
    }

    $rows = DB::table('personal_access_tokens')
        ->select('id','name','tokenable_type','tokenable_id','abilities','last_used_at','expires_at','created_at')
        ->orderByDesc('id')
        ->limit(20)
        ->get();

    echo 'count=' . $rows->count() . PHP_EOL;
    foreach ($rows as $r) {
        echo json_encode($r, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }
} catch (Throwable $e) {
    echo 'ERROR: '.$e->getMessage().PHP_EOL;
}
