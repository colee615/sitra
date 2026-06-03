<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

try {
    $conn = Illuminate\Support\Facades\DB::connection('sqlsrv');
    $db = $conn->select('SELECT DB_NAME() AS dbname');
    echo 'DB: '.($db[0]->dbname ?? 'N/A').PHP_EOL;

    $tables = $conn->select("SELECT TABLE_SCHEMA, TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE='BASE TABLE' ORDER BY TABLE_SCHEMA, TABLE_NAME");
    echo 'TABLES_COUNT: '.count($tables).PHP_EOL;

    foreach (array_slice($tables, 0, 20) as $t) {
        echo $t->TABLE_SCHEMA.'.'.$t->TABLE_NAME.PHP_EOL;
    }
} catch (Throwable $e) {
    echo 'ERROR: '.$e->getMessage().PHP_EOL;
}
