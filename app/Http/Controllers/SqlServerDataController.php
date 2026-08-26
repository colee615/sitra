<?php

namespace App\Http\Controllers;

use App\Services\SqlServerSearchService;
use Illuminate\Http\Request;
use Throwable;

class SqlServerDataController extends Controller
{
    public function index(Request $request, SqlServerSearchService $searchService)
    {
        if (!$request->user() || !$request->user()->hasRole('admin')) {
            abort(403, 'Solo los administradores pueden ver esta pagina.');
        }

        $codigo = trim((string) $request->query('codigo', ''));

        try {
            $result = $searchService->search($codigo);
            $result['transitSummary'] = $this->inferTransitSummary(
                collect($result['trackingRows'] ?? []),
                collect($result['packageRows'] ?? [])
            );
            $result['error'] = null;

            return view('sqlserver.index', $result);
        } catch (Throwable $e) {
            return view('sqlserver.index', [
                'codigo' => strtoupper($codigo),
                'packageRows' => collect(),
                'trackingRows' => collect(),
                'customerRows' => collect(),
                'deliveryRows' => collect(),
                'logisticRows' => collect(),
                'manifestRows' => collect(),
                'ediRows' => collect(),
                'customsRows' => collect(),
                'contentPieceRows' => collect(),
                'tableMap' => [],
                'similarCodes' => collect(),
                'transitSummary' => null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function inferTransitSummary($trackingRows, $packageRows): ?array
    {
        $package = $packageRows->first();
        $originCode = strtoupper(trim((string) ($package->ORIG_COUNTRY_CD ?? '')));
        $destinationCode = strtoupper(trim((string) ($package->DEST_COUNTRY_CD ?? '')));

        $transits = collect($trackingRows)
            ->filter(fn ($row) => ($row->SOURCE_DB ?? '') === 'IPS5Db-EDI')
            ->map(function ($row) {
                $locationCode = strtoupper(trim((string) ($row->LOCATION_ID ?? $row->OFFICE_FCD ?? '')));

                return [
                    'country_code' => substr($locationCode, 0, 2),
                    'country_name' => trim((string) ($row->OFFICE_NM ?? '')),
                    'location_code' => $locationCode,
                    'event_date' => (string) ($row->EVENT_GMT_DT ?? ''),
                ];
            })
            ->filter(fn (array $item) => strlen($item['country_code']) === 2 && $item['location_code'] !== '')
            ->reject(fn (array $item) => in_array($item['country_code'], [$originCode, $destinationCode], true))
            ->groupBy('country_code')
            ->map(function ($rows, $countryCode) {
                $rows = collect($rows)->sortByDesc('event_date')->values();
                $latest = $rows->first();

                return [
                    'codigo' => $countryCode,
                    'nombre' => $latest['country_name'] !== '' ? $latest['country_name'] : $countryCode,
                    'cantidad_eventos' => $rows->count(),
                    'ultimo_evento_fecha' => $latest['event_date'] !== '' ? $latest['event_date'] : null,
                    'ubicaciones' => $rows->pluck('location_code')->filter()->unique()->values()->all(),
                ];
            })
            ->sortByDesc('ultimo_evento_fecha')
            ->values();

        if ($transits->isEmpty()) {
            return null;
        }

        return [
            'principal' => $transits->first(),
            'paises' => $transits->all(),
        ];
    }
}
