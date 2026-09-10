<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SqlServerSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class SqlServerExternalSearchBatchController extends Controller
{
    public function __invoke(Request $request, SqlServerSearchService $searchService): JsonResponse
    {
        if (!$request->user() || !$request->user()->hasRole('admin')) {
            return response()->json([
                'message' => 'No autorizado para consultar este recurso.',
            ], 403);
        }

        if (!$request->user()->tokenCan('sqlserver.read')) {
            return response()->json([
                'message' => 'El token no tiene permiso para leer este recurso.',
            ], 403);
        }

        $validated = $request->validate([
            'codigos' => ['required', 'array', 'min:1', 'max:60'],
            'codigos.*' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9-]+$/'],
        ]);

        $codes = collect($validated['codigos'])
            ->map(fn ($codigo) => strtoupper(trim((string) $codigo)))
            ->filter()
            ->unique()
            ->values();

        try {
            $results = $searchService->searchManyEvents($codes->all());

            return response()->json([
                'tipo' => 'tracking_eventos_batch',
                'total_codigos' => $codes->count(),
                'resultado' => $codes
                    ->map(function (string $code) use ($results): array {
                        $result = $results[$code] ?? [
                            'codigo' => $code,
                            'packageRows' => collect(),
                            'trackingRows' => collect(),
                        ];
                        $packageRows = $result['packageRows'] ?? [];
                        $originCountry = $this->resolveOriginCountry($packageRows, $code);
                        $packageMeta = $this->resolvePackageMeta($packageRows);

                        return [
                            'codigo' => $result['codigo'] ?? $code,
                            'tipo_servicio' => $this->resolveServiceType($result['codigo'] ?? $code),
                            'origen' => $packageMeta['origin_country_name'],
                            'destino' => $packageMeta['destination_country_name'],
                            'pais_destino' => $packageMeta['destination_country_name'],
                            'country_destino' => $packageMeta['destination_country_name'],
                            'meta' => [
                                'origin_country_code' => $packageMeta['origin_country_code'],
                                'origin_country_name' => $packageMeta['origin_country_name'],
                                'destination_country_code' => $packageMeta['destination_country_code'],
                                'destination_country_name' => $packageMeta['destination_country_name'],
                                'destino' => $packageMeta['destination_country_name'],
                                'pais_destino' => $packageMeta['destination_country_name'],
                            ],
                            'eventos_externos' => $this->transformExternalEvents(
                                $result['trackingRows'] ?? [],
                                $originCountry
                            ),
                        ];
                    })
                    ->values(),
            ])
                ->header('X-Tracking-Backend', 'sqlsrv')
                ->header('X-Tracking-Batch', 'true');
        } catch (Throwable $e) {
            Log::error('Error consultando lote SQL Server API', [
                'codigos' => $codes->all(),
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'tipo' => 'tracking_eventos_batch',
                'total_codigos' => $codes->count(),
                'resultado' => $codes
                    ->map(fn (string $code) => [
                        'codigo' => $code,
                        'tipo_servicio' => $this->resolveServiceType($code),
                        'eventos_externos' => [],
                    ])
                    ->values(),
            ], 500);
        }
    }

    private function transformExternalEvents(iterable $trackingRows, string $originCountry)
    {
        return collect($trackingRows)
            ->map(function ($row) use ($originCountry) {
                $eventType = $this->normalizeText(isset($row->EVENT_TYPE_NM_ES) ? (string) $row->EVENT_TYPE_NM_ES : '');
                $condition = $this->normalizeText(isset($row->CONDITION_TXT) ? (string) $row->CONDITION_TXT : '');
                $detail = $this->normalizeText(isset($row->DETAIL_TXT) ? (string) $row->DETAIL_TXT : '');

                return [
                    'mailitM_PID' => isset($row->MAILITM_PID) ? strtolower(trim((string) $row->MAILITM_PID)) : '',
                    'mailitM_FID' => $this->resolveMailItemFid($row),
                    'codigo_evento' => isset($row->EVENT_TYPE_CD) ? (int) $row->EVENT_TYPE_CD : null,
                    'origen_evento' => trim((string) ($row->SOURCE_DB ?? 'IPS5Db')),
                    'eventType' => $eventType,
                    'eventDate' => $this->formatEventDate($row->EVENT_GMT_DT ?? null),
                    'office' => $this->buildOffice($row, $originCountry, $detail),
                    'scanned' => $this->cleanLabel(isset($row->SCANNED_TXT) ? (string) $row->SCANNED_TXT : ''),
                    'workstation' => $this->cleanLabel(isset($row->WORKSTATION_TXT) ? (string) $row->WORKSTATION_TXT : ''),
                    'condition' => $condition,
                    'nextOffice' => $this->cleanLabel(isset($row->NEXT_OFFICE_FCD) ? (string) $row->NEXT_OFFICE_FCD : ''),
                    'detail' => $detail,
                ];
            })
            ->filter(fn (array $evento) => $evento['eventType'] !== '' || $evento['eventDate'] !== '')
            ->unique(fn (array $evento) => implode('|', [
                $evento['mailitM_PID'],
                $evento['eventType'],
                $evento['eventDate'],
                $evento['office'],
            ]))
            ->sortByDesc(fn (array $evento) => strtotime($evento['eventDate'] ?: '1970-01-01') ?: 0)
            ->values();
    }

    private function formatEventDate(mixed $eventDate): string
    {
        if (empty($eventDate)) {
            return '';
        }

        try {
            return Carbon::parse($eventDate)->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return trim((string) $eventDate);
        }
    }

    private function buildOffice(object $row, string $originCountry, string $detail): string
    {
        $office = trim(implode(' - ', array_filter([
            isset($row->OFFICE_FCD) ? trim((string) $row->OFFICE_FCD) : '',
            isset($row->OFFICE_NM) ? trim((string) $row->OFFICE_NM) : '',
        ])));

        if ($office !== '') {
            return $office;
        }

        if ($detail !== '') {
            return $detail;
        }

        if (($row->SOURCE_DB ?? '') === 'IPS5Db-EDI') {
            return $originCountry;
        }

        return '';
    }

    private function resolveMailItemFid(object $row): string
    {
        if (($row->SOURCE_DB ?? '') === 'IPS5Db-EDI') {
            return '';
        }

        return isset($row->MAILITM_FID) ? trim((string) $row->MAILITM_FID) : '';
    }

    private function resolveOriginCountry(iterable $packageRows, string $codigo): string
    {
        $package = collect($packageRows)->first();
        $originCountryCode = isset($package->ORIG_COUNTRY_CD) ? trim((string) $package->ORIG_COUNTRY_CD) : '';
        $originCountryName = $this->normalizeText(isset($package->ORIG_COUNTRY_NM) ? (string) $package->ORIG_COUNTRY_NM : '');

        if ($originCountryName !== '') {
            return $originCountryCode !== '' ? $originCountryCode . ' - ' . $originCountryName : $originCountryName;
        }

        $countryCode = strlen($codigo) >= 2 ? substr(strtoupper(trim($codigo)), -2) : '';
        $countryName = $countryCode !== '' ? $this->countryNameFromS10($countryCode) : '';

        return $countryName !== '' ? $countryCode . ' - ' . $countryName : $countryCode;
    }

    private function resolvePackageMeta(iterable $packageRows): array
    {
        $package = collect($packageRows)->first();
        $originCountryCode = isset($package->ORIG_COUNTRY_CD) ? trim((string) $package->ORIG_COUNTRY_CD) : '';
        $originCountryName = $this->normalizeText(isset($package->ORIG_COUNTRY_NM) ? (string) $package->ORIG_COUNTRY_NM : '');
        $destinationCountryCode = isset($package->DEST_COUNTRY_CD) ? trim((string) $package->DEST_COUNTRY_CD) : '';
        $destinationCountryName = $this->normalizeText(isset($package->DEST_COUNTRY_NM) ? (string) $package->DEST_COUNTRY_NM : '');

        if ($originCountryName === '' && $originCountryCode !== '') {
            $originCountryName = $this->countryNameFromS10($originCountryCode);
        }

        if ($destinationCountryName === '' && $destinationCountryCode !== '') {
            $destinationCountryName = $this->countryNameFromS10($destinationCountryCode);
        }

        return [
            'origin_country_code' => $originCountryCode !== '' ? strtoupper($originCountryCode) : null,
            'origin_country_name' => $originCountryName !== '' ? $originCountryName : null,
            'destination_country_code' => $destinationCountryCode !== '' ? strtoupper($destinationCountryCode) : null,
            'destination_country_name' => $destinationCountryName !== '' ? $destinationCountryName : null,
        ];
    }

    private function countryNameFromS10(string $countryCode): string
    {
        $countries = [
            'AR' => 'Argentina',
            'BE' => 'Belgica',
            'BO' => 'Bolivia',
            'BR' => 'Brasil',
            'CA' => 'Canada',
            'CH' => 'Suiza',
            'CL' => 'Chile',
            'CN' => 'China',
            'CO' => 'Colombia',
            'DE' => 'Alemania',
            'DO' => 'Republica Dominicana',
            'EC' => 'Ecuador',
            'ES' => 'Espana',
            'FR' => 'Francia',
            'GB' => 'Reino Unido',
            'IT' => 'Italia',
            'JP' => 'Japon',
            'KR' => 'Corea del Sur',
            'MX' => 'Mexico',
            'NL' => 'Paises Bajos',
            'PE' => 'Peru',
            'PT' => 'Portugal',
            'PY' => 'Paraguay',
            'SE' => 'Suecia',
            'US' => 'Estados Unidos',
            'UY' => 'Uruguay',
            'VE' => 'Venezuela',
        ];

        return $countries[strtoupper(trim($countryCode))] ?? '';
    }

    private function resolveServiceType(string $codigo): string
    {
        $codigo = strtoupper(trim($codigo));

        if (str_starts_with($codigo, 'R')) {
            return 'certificadas';
        }

        if (str_starts_with($codigo, 'O') || str_starts_with($codigo, 'L')) {
            return 'ordinarias';
        }

        if (str_starts_with($codigo, 'C')) {
            return 'encomiendas';
        }

        if (str_starts_with($codigo, 'E')) {
            return 'ems';
        }

        return '';
    }

    private function normalizeText(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    private function cleanLabel(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $this->normalizeText($value)) ?? '');
    }
}
