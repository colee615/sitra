<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

try {
    $conn = Illuminate\Support\Facades\DB::connection('sqlsrv');
    $db = $conn->select('SELECT DB_NAME() AS dbname');
    echo 'OK DB: ' . ($db[0]->dbname ?? 'N/A') . PHP_EOL;

    $limit = (int) env('SQLSRV_SAMPLE_LIMIT', 50);
    $table = env('SQLSRV_SAMPLE_TABLE', 'dbo.A_USERS');
    $query = "SELECT TOP {$limit} * FROM {$table}";
    $rows = $conn->select($query);

    echo 'ROWS: ' . count($rows) . PHP_EOL;
    if (count($rows) > 0) {
        $first = (array) $rows[0];
        echo 'FIRST_ROW_KEYS: ' . implode(',', array_keys($first)) . PHP_EOL;
    }
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . PHP_EOL;
}
