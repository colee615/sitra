<?php

namespace App\Console\Commands;

use App\Services\IpsRepository;
use Illuminate\Console\Command;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

class IpsBenchmark extends Command
{
    protected $signature = 'ips:benchmark {--office=1} {--page=1} {--repeat=2} {--q=} {--fresh-count : Ignore cached totals}';

    protected $description = 'Mide consultas IPS de solo lectura; no registra movimientos ni expone datos de destinatarios';

    public function handle(IpsRepository $ips): int
    {
        if ($this->option('fresh-count')) {
            config(['ips.totals_cache_seconds' => 0]);
        }
        $timings = [];
        DB::listen(function (QueryExecuted $query) use (&$timings) {
            if ($query->connectionName === config('ips.connection')) {
                $timings[] = ['count' => str_contains(strtolower($query->sql), 'select count('), 'ms' => $query->time];
            }
        });
        foreach (range(1, min(5, max(1, (int) $this->option('repeat')))) as $run) {
            foreach (['all', 'reception', 'pending', 'delivered'] as $stage) {
                $timings = [];
                $start = microtime(true);
                $result = $ips->packages([
                    'office_cd' => max(1, (int) $this->option('office')), 'status' => $stage,
                    'page' => max(1, (int) $this->option('page')), 'per_page' => 25, 'q' => $this->option('q'),
                ]);
                $this->line(json_encode([
                    'run' => $run, 'stage' => $stage, 'page' => $result['meta']['page'],
                    'total' => $result['meta']['total'], 'rows' => count($result['data']),
                    'elapsed_ms' => round((microtime(true) - $start) * 1000, 1),
                    'count_ms' => round(array_sum(array_column(array_filter($timings, fn ($q) => $q['count']), 'ms')), 1),
                    'sql_ms' => round(array_sum(array_column($timings, 'ms')), 1), 'queries' => count($timings),
                    'data_hash' => hash('sha256', json_encode($result['data'])),
                ], JSON_THROW_ON_ERROR));
            }
        }

        return self::SUCCESS;
    }
}
