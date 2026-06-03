<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

function testDb(string $dbName): void {
    try {
        config(['database.connections.sqlsrv.database' => $dbName]);
        DB::purge('sqlsrv');
        $conn = DB::connection('sqlsrv');
        $db = $conn->select('SELECT DB_NAME() AS dbname');
        echo "OK {$dbName}: connected to " . ($db[0]->dbname ?? 'N/A') . PHP_EOL;

        $rows = $conn->select('SELECT TOP 1 * FROM dbo.A_USERS');
        echo "OK {$dbName}: A_USERS rows fetched=" . count($rows) . PHP_EOL;
    } catch (Throwable $e) {
        echo "ERR {$dbName}: " . $e->getMessage() . PHP_EOL;
    }
}

testDb('IPS5Db');
testDb('CDSDb');
