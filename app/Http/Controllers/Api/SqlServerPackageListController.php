<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SqlServerSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class SqlServerPackageListController extends Controller
{
    public function __invoke(
        Request $request,
        SqlServerSearchService $searchService
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
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'q' => ['nullable', 'string', 'max:40'],
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 50);
        $search = isset($validated['q']) ? trim((string) $validated['q']) : null;

        try {
            $result = $searchService->listPackages($page, $perPage, $search);
            $trackingRows = $searchService->trackingRowsForPackageRows($result['rows'] ?? []);
            $eventsByPackage = $this->transformPackageEvents($trackingRows)->groupBy('mailitm_pid');

            $items = collect($result['rows'] ?? [])
                ->map(fn ($row) => $this->transformRow($row, $eventsByPackage->get(trim((string) ($row->MAILITM_PID ?? '')), collect())))
                ->values();

            return response()->json([
                'count' => $items->count(),
                'data' => $items,
                'meta' => [
                    'all' => false,
                    'page' => (int) ($result['page'] ?? $page),
                    'per_page' => (int) ($result['per_page'] ?? $perPage),
                    'count' => $items->count(),
                    'q' => $search,
                ],
            ])->header('X-Tracking-Backend', 'sqlsrv');
        } catch (Throwable $e) {
            Log::error('Error listando paquetes SQL Server', [
                'page' => $page,
                'per_page' => $perPage,
                'q' => $search,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'No se pudo obtener la lista de paquetes.',
            ], 500);
        }
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

    private function cleanText(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $value = preg_replace('/\s+\[Indirecto: [^\]]+\]$/u', '', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim(str_replace(
            ['EnvÃƒÆ’Ã‚Â­o', 'envÃƒÆ’Ã‚Â­o', 'ubicaciÃƒÆ’Ã‚Â³n', 'trÃƒÆ’Ã‚Â¡nsito', 'devoluciÃƒÆ’Ã‚Â³n', 'informaciÃƒÆ’Ã‚Â³n', 'PaÃƒÆ’Ã‚Â­s', 'ÃƒÂ¡', 'ÃƒÂ©', 'ÃƒÂ­', 'ÃƒÂ³', 'ÃƒÂº', 'ÃƒÂ±', 'PaÃƒÂ­s'],
            ['EnvÃ­o', 'envÃ­o', 'ubicaciÃ³n', 'trÃ¡nsito', 'devoluciÃ³n', 'informaciÃ³n', 'PaÃ­s', 'Ã¡', 'Ã©', 'Ã­', 'Ã³', 'Ãº', 'Ã±', 'PaÃ­s'],
            $value
        ));
    }

    private function formatDate(mixed $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d H:i:s');
        } catch (Throwable) {
            $stringValue = trim((string) $value);

            return $stringValue !== '' ? $stringValue : null;
        }
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function joinParts(array $parts): ?string
    {
        $parts = array_values(array_filter(array_map(function ($part) {
            $part = $this->cleanText($part);

            return $part !== null ? $part : null;
        }, $parts)));

        return !empty($parts) ? implode(' - ', $parts) : null;
    }

    private function transformRow(object $row, mixed $events): array
    {
        $codigo = trim((string) ($row->MAILITM_FID ?: $row->MAILITM_LOCAL_ID));

        return [
            'mailitm_pid' => $this->nullableString($row->MAILITM_PID ?? null),
            'codigo' => $codigo,
            'codigo_s10' => $this->nullableString($row->MAILITM_FID ?? null),
            'fecha_registro' => $this->formatDate($row->FIRST_EVENT_GMT_DT ?? null),
            'numero_despacho' => $this->nullableString($row->DESPTCH_FID ?? null),
            'tipo_servicio' => $this->resolveServiceType($codigo),
            'peso' => $this->toFloat($row->MAILITM_WEIGHT ?? null),
            'clase_correo' => $this->cleanText($row->MAIL_CLASS_NM ?? ''),
            'contenido' => $this->cleanText($row->MAILITM_CONTENT_NM ?? ''),
            'estado_postal' => $this->cleanText($row->POSTAL_STATUS_NM ?? ''),
            'telefono' => $this->nullableString($row->ADDRESSEE_PHONE_NO ?? $row->SENDER_PHONE_NO ?? null),
            'telefonos' => [
                'remitente' => $this->nullableString($row->SENDER_PHONE_NO ?? null),
                'destinatario' => $this->nullableString($row->ADDRESSEE_PHONE_NO ?? null),
            ],
            'origen' => [
                'codigo' => $this->nullableString($row->ORIG_COUNTRY_CD ?? null),
                'nombre' => $this->cleanText($row->ORIG_COUNTRY_NM ?? ''),
            ],
            'destino' => [
                'codigo' => $this->nullableString($row->DEST_COUNTRY_CD ?? null),
                'nombre' => $this->cleanText($row->DEST_COUNTRY_NM ?? ''),
            ],
            'eventos' => collect($events)->values()->all(),
        ];
    }

    private function transformPackageEvents(iterable $trackingRows)
    {
        return collect($trackingRows)
            ->map(function ($row) {
                $rawEvent = $this->cleanText($row->EVENT_TYPE_NM_ES ?? '');

                return [
                    'mailitm_pid' => trim((string) ($row->MAILITM_PID ?? '')),
                    'fecha' => $this->formatDate($row->EVENT_GMT_DT ?? null),
                    'codigo_evento' => isset($row->EVENT_TYPE_CD) ? (int) $row->EVENT_TYPE_CD : null,
                    'evento_original' => $rawEvent,
                    'evento_api' => $rawEvent,
                    'visible_api' => true,
                    'fuente' => $this->nullableString($row->SOURCE_DB ?? null),
                    'oficina' => $this->joinParts([
                        $row->OFFICE_FCD ?? null,
                        $row->OFFICE_NM ?? null,
                    ]),
                    'siguiente_oficina' => $this->joinParts([
                        $row->NEXT_OFFICE_FCD ?? null,
                        $row->NEXT_OFFICE_NM ?? null,
                    ]),
                    'detalle' => $this->cleanText($row->DETAIL_TXT ?? ''),
                    'condicion' => $this->cleanText($row->CONDITION_TXT ?? ''),
                ];
            })
            ->sortByDesc(fn (array $event) => strtotime($event['fecha'] ?: '1970-01-01 00:00:00') ?: 0)
            ->values();
    }
}
