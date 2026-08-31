<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TrackingEventRuleService;
use App\Services\TrackingSearchCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class SqlServerExternalSearchSafeController extends Controller
{
    public function __invoke(
        Request $request,
        TrackingSearchCacheService $searchService,
        TrackingEventRuleService $eventRuleService
    ): JsonResponse {
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
            'codigo' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9-]+$/'],
        ]);

        $codigo = $validated['codigo'];

        try {
            $lookup = $searchService->search($codigo);
            $result = $lookup['data'];
            $originCountry = $this->resolveOriginCountry($result['packageRows'] ?? [], $result['codigo'] ?? $codigo);
            $packageMeta = $this->resolvePackageMeta($result['packageRows'] ?? []);

            return response()->json([
                'codigo' => $result['codigo'] ?? strtoupper(trim($codigo)),
                'tipo_servicio' => $this->resolveServiceType($result['codigo'] ?? $codigo),
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
                    $originCountry,
                    $eventRuleService
                ),
            ])
                ->header('X-Tracking-Cache', (string) ($lookup['cache_status'] ?? 'unknown'))
                ->header('X-Tracking-Backend', 'sqlsrv')
                ->header('X-Tracking-Stale', !empty($lookup['stale_fallback']) ? 'true' : 'false');
        } catch (Throwable $e) {
            Log::error('Error consultando SQL Server API', [
                'codigo' => strtoupper(trim($codigo)),
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'codigo' => strtoupper(trim($codigo)),
                'tipo_servicio' => $this->resolveServiceType($codigo),
                'eventos_externos' => [],
            ], 500);
        }
    }

    private function transformExternalEvents(
        iterable $trackingRows,
        string $originCountry,
        TrackingEventRuleService $eventRuleService
    ) {
        return collect($trackingRows)
            ->map(function ($row) use ($originCountry, $eventRuleService) {
                $eventType = $eventRuleService->present(
                    isset($row->EVENT_TYPE_NM_ES) ? (string) $row->EVENT_TYPE_NM_ES : '',
                    isset($row->SOURCE_DB) ? (string) $row->SOURCE_DB : '',
                    $row->EVENT_TYPE_CD ?? null
                );
                $condition = $eventRuleService->normalizeText(isset($row->CONDITION_TXT) ? (string) $row->CONDITION_TXT : '');
                $detail = $eventRuleService->normalizeText(isset($row->DETAIL_TXT) ? (string) $row->DETAIL_TXT : '');
                $eventCode = isset($row->EVENT_TYPE_CD) ? (int) $row->EVENT_TYPE_CD : null;

                // A hidden label must not hide a milestone such as delivery from public tracking.
                if ($eventType === null && ! $this->isProgressEvent($eventCode)) {
                    return null;
                }

                if ($eventType === null) {
                    $eventType = $eventRuleService->normalizeText(
                        isset($row->EVENT_TYPE_NM_ES) ? (string) $row->EVENT_TYPE_NM_ES : ''
                    );
                }

                return [
                    'mailitM_PID' => isset($row->MAILITM_PID) ? strtolower(trim((string) $row->MAILITM_PID)) : '',
                    'mailitM_FID' => $this->resolveMailItemFid($row),
                    // Preserve the UPU code even when the visible event name is customized.
                    'codigo_evento' => $eventCode,
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
            ->filter()
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

    private function isProgressEvent(?int $eventCode): bool
    {
        return in_array($eventCode, [
            1, 2, 3, 5, 8, 12, 30, 32, 35, 36, 37, 39, 40, 42, 43, 44,
            67, 71, 72, 73, 74, 75, 77, 78, 1250,
        ], true);
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

        $s10Code = strtoupper(trim($codigo));
        $countryCode = strlen($s10Code) >= 2 ? substr($s10Code, -2) : '';

        if ($countryCode === '') {
            return '';
        }

        $countryName = $this->countryNameFromS10($countryCode);

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
        $value = str_replace(
            ['EnvÃƒÂ­o', 'envÃƒÂ­o', 'ubicaciÃƒÂ³n', 'trÃƒÂ¡nsito', 'devoluciÃƒÂ³n', 'informaciÃƒÂ³n', 'PaÃƒÂ­s'],
            ['Envío', 'envío', 'ubicación', 'tránsito', 'devolución', 'información', 'País'],
            $value
        );

        return str_replace(
            ['EnvÃƒÆ’Ã‚Â­o', 'envÃƒÆ’Ã‚Â­o', 'ubicaciÃƒÆ’Ã‚Â³n', 'trÃƒÆ’Ã‚Â¡nsito', 'devoluciÃƒÆ’Ã‚Â³n', 'informaciÃƒÆ’Ã‚Â³n', 'PaÃƒÆ’Ã‚Â­s'],
            ['Envío', 'envío', 'ubicación', 'tránsito', 'devolución', 'información', 'País'],
            $value
        );
    }

    private function cleanLabel(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $this->normalizeText($value)) ?? '');
    }
}
