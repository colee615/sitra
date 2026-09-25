<?php

namespace App\Services\Postal;

use App\Services\SqlServerSearchService;
use Illuminate\Support\Facades\Log;
use Throwable;

class PostalWorkspace
{
    public function __construct(private SqlServerSearchService $ips, private CdsRepository $cds) {}

    public function search(string $code, bool $allowIps, bool $allowCds): array
    {
        $code = ShipmentCode::normalize($code);
        $result = ['code' => $code, 'identifier' => ShipmentCode::describe($code), 'ips' => [], 'cds' => [],
            'sources' => ['IPS' => $allowIps ? 'idle' : 'forbidden', 'CDS' => !$allowCds ? 'forbidden' : (config('postal.cds_enabled') ? 'idle' : 'disabled')]];
        if ($code === '') return $result;
        $ipsSearch = $allowIps
            ? $this->ips->onConnection(config('postal.ips_read_connection', config('tracking.sqlserver.connection', 'sqlsrv')))
            : $this->ips;
        $aliases = [$code];
        if ($allowIps) {
            try {
                $result['ips'] = $ipsSearch->search($code);
                $packages = collect($result['ips']['packageRows'] ?? []);
                $result['sources']['IPS'] = $packages->isEmpty() && collect($result['ips']['trackingRows'] ?? [])->isEmpty() ? 'empty' : 'ok';
                foreach ($packages as $package) {
                    $aliases[] = $package->MAILITM_FID ?? '';
                    $aliases[] = $package->MAILITM_LOCAL_ID ?? '';
                }
            } catch (Throwable $e) {
                $result['sources']['IPS'] = 'unavailable';
                Log::warning('Postal lookup unavailable', ['source' => 'IPS', 'exception' => get_class($e)]);
            }
        }
        if ($allowCds && config('postal.cds_enabled')) {
            try {
                $result['cds'] = $this->cds->search($aliases);
                $result['sources']['CDS'] = empty($result['cds']['packages']) ? 'empty' : 'ok';
                // Resolve a local CDS identifier only when it identifies a single postal code.
                $postalCodes = collect($result['cds']['packages'])->pluck('MAIL_OBJECT_ID')->filter()->unique()->values();
                if ($allowIps && $result['sources']['IPS'] === 'empty' && $postalCodes->count() === 1 && $postalCodes[0] !== $code) {
                    try {
                        $result['ips'] = $ipsSearch->search($postalCodes[0]);
                        $result['sources']['IPS'] = collect($result['ips']['packageRows'] ?? [])->isEmpty() ? 'empty' : 'ok';
                    } catch (Throwable $e) {
                        $result['sources']['IPS'] = 'unavailable';
                    }
                }
            } catch (Throwable $e) {
                $result['sources']['CDS'] = 'unavailable';
                Log::warning('Postal lookup unavailable', ['source' => 'CDS', 'exception' => get_class($e)]);
            }
        }
        return $result;
    }
}
