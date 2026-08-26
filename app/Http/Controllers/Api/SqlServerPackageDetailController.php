<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TrackingEventRuleService;
use App\Services\TrackingSearchCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class SqlServerPackageDetailController extends Controller
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

            $packageRows = collect($result['packageRows'] ?? []);
            $trackingRows = collect($result['trackingRows'] ?? []);
            $customerRows = collect($result['customerRows'] ?? []);
            $deliveryRows = collect($result['deliveryRows'] ?? []);
            $logisticRows = collect($result['logisticRows'] ?? []);
            $manifestRows = collect($result['manifestRows'] ?? []);
            $ediRows = collect($result['ediRows'] ?? []);
            $customsRows = collect($result['customsRows'] ?? []);
            $contentPieceRows = collect($result['contentPieceRows'] ?? []);

            $eventos = $this->transformEvents($trackingRows, $eventRuleService);
            $paquete = $this->buildPackageSummary($packageRows, $customsRows, $trackingRows);

            return response()->json([
                'codigo' => $result['codigo'] ?? strtoupper(trim($codigo)),
                'tipo_servicio' => $this->resolveServiceType($result['codigo'] ?? $codigo),
                'resumen' => [
                    'cantidad_eventos' => $eventos->count(),
                    'cantidad_eventos_visibles_api' => $eventos->where('visible_api', true)->count(),
                    'cantidad_eventos_entrada' => $eventos->where('direccion', 'entrada')->count(),
                    'cantidad_eventos_salida' => $eventos->where('direccion', 'salida')->count(),
                    'cantidad_sacas' => $logisticRows->pluck('RECPTCL_FID')->filter()->unique()->count(),
                    'cantidad_despachos' => $logisticRows->pluck('DESPTCH_FID')->filter()->unique()->count(),
                    'cantidad_manifiestos' => $manifestRows->pluck('MANIFEST_LIST_ID')->filter()->unique()->count(),
                    'cantidad_piezas_contenido' => $contentPieceRows->count(),
                    'peso_paquete' => $paquete['peso_paquete'],
                    'peso_bruto_aduana' => $paquete['peso_bruto_aduana'],
                ],
                'paquete' => $paquete,
                'clientes' => $this->transformCustomers($customerRows, $eventRuleService),
                'entregas' => $this->transformDeliveryRows($deliveryRows, $eventRuleService),
                'logistica' => [
                    'sacas_despachos' => $this->transformLogisticRows($logisticRows),
                    'manifiestos' => $this->transformManifestRows($manifestRows),
                    'edi' => $this->transformEdiRows($ediRows, $eventRuleService),
                ],
                'aduana' => $this->transformCustomsRows($customsRows),
                'contenido' => $this->transformContentPieces($contentPieceRows),
                'eventos' => $eventos->values(),
            ])
                ->header('X-Tracking-Cache', (string) ($lookup['cache_status'] ?? 'unknown'))
                ->header('X-Tracking-Backend', 'sqlsrv')
                ->header('X-Tracking-Stale', !empty($lookup['stale_fallback']) ? 'true' : 'false');
        } catch (Throwable $e) {
            Log::error('Error consultando detalle de paquete SQL Server', [
                'codigo' => strtoupper(trim($codigo)),
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'codigo' => strtoupper(trim($codigo)),
                'tipo_servicio' => $this->resolveServiceType($codigo),
                'message' => 'No se pudo obtener el detalle del paquete.',
            ], 500);
        }
    }

    private function buildPackageSummary(Collection $packageRows, Collection $customsRows, Collection $trackingRows): array
    {
        $package = $packageRows->first();
        $customs = $customsRows->first();
        $transito = $this->inferTransitSummary($trackingRows, $packageRows);

        return [
            'mailitm_pid' => $package ? (int) ($package->MAILITM_PID ?? 0) : null,
            'codigo_s10' => $this->nullableString($package?->MAILITM_FID ?? null),
            'codigo_local' => $this->nullableString($package?->MAILITM_LOCAL_ID ?? null),
            'clase_correo' => $this->cleanText($package?->MAIL_CLASS_NM ?? ''),
            'codigo_clase_correo' => $this->nullableString($package?->MAIL_CLASS_CD ?? null),
            'contenido' => $this->cleanText($package?->MAILITM_CONTENT_NM ?? ''),
            'codigo_contenido' => $this->nullableString($package?->MAILITM_CONTENT_CD ?? null),
            'tipo_producto' => $this->cleanText($package?->PRODUCT_TYPE_NM ?? ''),
            'codigo_tipo_producto' => $this->nullableString($package?->PRODUCT_TYPE_CD ?? null),
            'estado_postal' => $this->cleanText($package?->POSTAL_STATUS_NM ?? ''),
            'codigo_estado_postal' => $this->nullableString($package?->POSTAL_STATUS_CD ?? null),
            'origen' => [
                'codigo' => $this->nullableString($package?->ORIG_COUNTRY_CD ?? null),
                'nombre' => $this->cleanText($package?->ORIG_COUNTRY_NM ?? ''),
            ],
            'destino' => [
                'codigo' => $this->nullableString($package?->DEST_COUNTRY_CD ?? null),
                'nombre' => $this->cleanText($package?->DEST_COUNTRY_NM ?? ''),
            ],
            'peso_paquete' => $this->toFloat($package?->MAILITM_WEIGHT ?? null),
            'valor_declarado' => $this->toFloat($package?->MAILITM_VALUE ?? null),
            'monto_duties' => $this->toFloat($package?->DUTIES_AMOUNT ?? null),
            'moneda' => $this->cleanText($package?->CURRENCY_NM ?? ''),
            'codigo_moneda' => $this->nullableString($package?->CURRENCY_CD ?? null),
            'numero_aduana' => $this->nullableString($package?->CUSTOMS_NO ?? null),
            'peso_bruto_aduana' => $this->toFloat($customs?->DECLARED_GROSS_WEIGHT ?? null),
            'referencia_aduana_remitente' => $this->nullableString($customs?->SENDER_CUSTOMS_REFERENCE_NO ?? null),
            'referencia_aduana_destinatario' => $this->nullableString($customs?->RECIPIENT_CUSTOMS_REFERENCE_NO ?? null),
            'ultimo_evento_fecha' => $this->formatDate($package?->EVT_GMT_DT ?? null),
            'ultimo_evento_nombre' => $this->cleanText($package?->EVT_TYPE_NM_ES ?? ''),
            'ultimo_evento_oficina' => $this->joinParts([
                $package?->EVT_OFFICE_FCD ?? null,
                $package?->EVT_OFFICE_NM ?? null,
            ]),
            'transito' => $transito,
        ];
    }

    private function transformEvents(Collection $trackingRows, TrackingEventRuleService $eventRuleService): Collection
    {
        return $trackingRows
            ->map(function ($row) use ($eventRuleService) {
                $rawEventName = $this->cleanText($row->EVENT_TYPE_NM_ES ?? '');
                $eventApiName = $eventRuleService->present(
                    isset($row->EVENT_TYPE_NM_ES) ? (string) $row->EVENT_TYPE_NM_ES : '',
                    isset($row->SOURCE_DB) ? (string) $row->SOURCE_DB : '',
                    $row->EVENT_TYPE_CD ?? null
                );

                return [
                    'fecha' => $this->formatDate($row->EVENT_GMT_DT ?? null),
                    'codigo_evento' => isset($row->EVENT_TYPE_CD) ? (int) $row->EVENT_TYPE_CD : null,
                    'direccion' => $this->inferDirection($rawEventName),
                    'alcance' => $this->inferScope((string) ($row->SOURCE_DB ?? '')),
                    'source_db' => $this->nullableString($row->SOURCE_DB ?? null),
                    'codigo_ubicacion' => $this->nullableString($row->LOCATION_ID ?? $row->OFFICE_FCD ?? null),
                    'nombre_original_bd' => $rawEventName !== '' ? $rawEventName : null,
                    'nombre_api' => $eventApiName !== null && trim($eventApiName) !== '' ? trim($eventApiName) : null,
                    'visible_api' => $eventApiName !== null,
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
                    'usuario' => $this->joinParts([
                        $row->USER_FID ?? null,
                        $row->USER_NM ?? null,
                    ]),
                    'scanned' => $this->cleanText($row->SCANNED_TXT ?? ''),
                    'workstation' => $this->cleanText($row->WORKSTATION_TXT ?? ''),
                ];
            })
            ->unique(fn (array $item) => implode('|', [
                $item['fecha'] ?? '',
                $item['codigo_evento'] ?? '',
                $item['nombre_original_bd'] ?? '',
                $item['source_db'] ?? '',
                $item['oficina'] ?? '',
            ]))
            ->sortByDesc(fn (array $item) => strtotime($item['fecha'] ?: '1970-01-01 00:00:00') ?: 0)
            ->values();
    }

    private function inferTransitSummary(Collection $trackingRows, Collection $packageRows): ?array
    {
        $package = $packageRows->first();
        $originCode = strtoupper(trim((string) ($package->ORIG_COUNTRY_CD ?? '')));
        $destinationCode = strtoupper(trim((string) ($package->DEST_COUNTRY_CD ?? '')));

        $transits = $trackingRows
            ->filter(fn ($row) => ($row->SOURCE_DB ?? '') === 'IPS5Db-EDI')
            ->map(function ($row) {
                $locationCode = strtoupper(trim((string) ($row->LOCATION_ID ?? $row->OFFICE_FCD ?? '')));

                return [
                    'country_code' => substr($locationCode, 0, 2),
                    'country_name' => $this->cleanText($row->OFFICE_NM ?? ''),
                    'location_code' => $locationCode,
                    'event_date' => $this->formatDate($row->EVENT_GMT_DT ?? null),
                ];
            })
            ->filter(fn (array $item) => strlen($item['country_code']) === 2 && $item['location_code'] !== '')
            ->reject(fn (array $item) => in_array($item['country_code'], [$originCode, $destinationCode], true))
            ->groupBy('country_code')
            ->map(function (Collection $rows, string $countryCode) {
                $rows = $rows->sortByDesc('event_date')->values();
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

    private function transformCustomers(Collection $customerRows, TrackingEventRuleService $eventRuleService): array
    {
        return $customerRows
            ->map(function ($row) use ($eventRuleService) {
                return [
                    'tipo' => ((string) ($row->SENDER_PAYEE_IND ?? '')) === 'S' ? 'remitente' : 'destinatario',
                    'nombre' => $this->joinParts([
                        $row->CUSTOMER_NAME ?? null,
                        $row->CUSTOMER_FORENAME ?? null,
                    ]),
                    'direccion' => $this->cleanText($row->CUSTOMER_ADDRESS ?? ''),
                    'ciudad' => $this->cleanText($row->CUSTOMER_CITY ?? ''),
                    'codigo_postal' => $this->nullableString($row->CUSTOMER_POST_CODE ?? null),
                    'pais_codigo' => $this->nullableString($row->COUNTRY_CD ?? null),
                    'pais_nombre' => $eventRuleService->normalizeText((string) ($row->COUNTRY_NM ?? '')) ?: null,
                    'telefono' => $this->nullableString($row->CUSTOMER_PHONE_NO ?? null),
                    'email' => $this->nullableString($row->CUSTOMER_EMAIL_ADDRESS ?? null),
                ];
            })
            ->values()
            ->all();
    }

    private function transformDeliveryRows(Collection $deliveryRows, TrackingEventRuleService $eventRuleService): array
    {
        return $deliveryRows
            ->map(function ($row) use ($eventRuleService) {
                return [
                    'fecha' => $this->formatDate($row->EVENT_GMT_DT ?? null),
                    'codigo_evento' => isset($row->EVENT_TYPE_CD) ? (int) $row->EVENT_TYPE_CD : null,
                    'evento' => $this->cleanText($row->EVENT_TYPE_NM_ES ?? ''),
                    'motivo_no_entrega' => $this->nullableString($row->NON_DELIVERY_REASON_CD ?? null),
                    'medida_no_entrega' => $this->nullableString($row->NON_DELIVERY_MEASURE_CD ?? null),
                    'firmante' => $this->cleanText($row->SIGNATORY_NM ?? ''),
                    'ubicacion_entrega' => $this->cleanText($row->DELIV_LOCATION ?? ''),
                    'codigo_postal_entrega' => $this->nullableString($row->DELIV_POSTCODE ?? null),
                ];
            })
            ->values()
            ->all();
    }

    private function transformLogisticRows(Collection $logisticRows): array
    {
        return $logisticRows
            ->map(function ($row) {
                return [
                    'saca' => $this->nullableString($row->RECPTCL_FID ?? null),
                    'peso_saca' => $this->toFloat($row->RECPTCL_WEIGHT ?? null),
                    'cantidad_paquetes_saca' => isset($row->RECPTCL_MAILITMS_NO) ? (int) $row->RECPTCL_MAILITMS_NO : null,
                    'despacho' => $this->nullableString($row->DESPTCH_FID ?? null),
                    'oficina_origen' => $this->nullableString($row->ORIG_OFFICE_FCD ?? null),
                    'oficina_destino' => $this->nullableString($row->DEST_OFFICE_FCD ?? null),
                    'fecha_salida' => $this->formatDate($row->DESPTCH_DEPARTURE_DT ?? null),
                ];
            })
            ->unique(fn (array $item) => implode('|', [
                $item['saca'] ?? '',
                $item['despacho'] ?? '',
                $item['fecha_salida'] ?? '',
            ]))
            ->values()
            ->all();
    }

    private function transformManifestRows(Collection $manifestRows): array
    {
        return $manifestRows
            ->map(function ($row) {
                return [
                    'manifest_id' => isset($row->MANIFEST_LIST_ID) ? (int) $row->MANIFEST_LIST_ID : null,
                    'fecha_creacion' => $this->formatDate($row->CREATION_LCL_DT ?? null),
                    'oficina' => $this->joinParts([
                        $row->OFFICE_FCD ?? null,
                        $row->OFFICE_NM ?? null,
                    ]),
                    'tipo_manifiesto' => $this->nullableString($row->MANIF_TYPE_ID ?? null),
                ];
            })
            ->values()
            ->all();
    }

    private function transformEdiRows(Collection $ediRows, TrackingEventRuleService $eventRuleService): array
    {
        return $ediRows
            ->map(function ($row) use ($eventRuleService) {
                return [
                    'codigo_evento' => isset($row->EVENT_TYPE_CD) ? (int) $row->EVENT_TYPE_CD : null,
                    'evento' => $this->cleanText($row->EVENT_TYPE_NM_ES ?? ''),
                    'evento_api' => $eventRuleService->present(
                        isset($row->EVENT_TYPE_NM_ES) ? (string) $row->EVENT_TYPE_NM_ES : '',
                        'IPS5Db-EDI',
                        $row->EVENT_TYPE_CD ?? null
                    ),
                    'fecha_evento_local' => $this->formatDate($row->EVENT_LOCAL_DT ?? null),
                    'fecha_captura' => $this->formatDate($row->CAPTURE_GMT_DT ?? null),
                    'ubicacion' => $this->nullableString($row->LOCATION_ID ?? null),
                    'sender_id' => $this->nullableString($row->SENDER_ID ?? null),
                    'despatch_number' => $this->nullableString($row->DESPATCH_NUMBER ?? null),
                ];
            })
            ->values()
            ->all();
    }

    private function transformCustomsRows(Collection $customsRows): array
    {
        return $customsRows
            ->map(function ($row) {
                return [
                    'customs_tax_pid' => isset($row->CUSTOMS_TAX_PID) ? (int) $row->CUSTOMS_TAX_PID : null,
                    'peso_bruto_declarado' => $this->toFloat($row->DECLARED_GROSS_WEIGHT ?? null),
                    'referencia_remitente' => $this->nullableString($row->SENDER_CUSTOMS_REFERENCE_NO ?? null),
                    'referencia_destinatario' => $this->nullableString($row->RECIPIENT_CUSTOMS_REFERENCE_NO ?? null),
                ];
            })
            ->values()
            ->all();
    }

    private function transformContentPieces(Collection $contentPieceRows): array
    {
        return $contentPieceRows
            ->map(function ($row) {
                return [
                    'identificador' => $this->nullableString($row->IDENTIFIER ?? null),
                    'cantidad_unidades' => isset($row->NUMBER_OF_UNITS) ? (int) $row->NUMBER_OF_UNITS : null,
                    'descripcion' => $this->cleanText($row->DESCRIPTION ?? ''),
                    'valor_declarado' => $this->toFloat($row->DECLARED_VALUE ?? null),
                    'moneda' => $this->nullableString($row->DECLARED_VALUE_CURRENCY_CD ?? null),
                    'peso_neto' => $this->toFloat($row->NET_WEIGHT ?? null),
                    'origen' => $this->nullableString($row->ORIGIN_LOCATION ?? null),
                    'partida_arancelaria' => $this->nullableString($row->TARIFF_HEADING ?? null),
                ];
            })
            ->values()
            ->all();
    }

    private function inferDirection(string $eventName): ?string
    {
        $normalized = mb_strtolower($eventName);

        if (str_contains($normalized, '(entrada')) {
            return 'entrada';
        }

        if (str_contains($normalized, '(salida')) {
            return 'salida';
        }

        return null;
    }

    private function inferScope(string $sourceDb): string
    {
        return match ($sourceDb) {
            'IPS5Db-DOM-RECPTCL' => 'envase_nacional',
            'IPS5Db-DOM-DESPTCH' => 'despacho_nacional',
            'IPS5Db-RECPTCL' => 'receptaculo',
            'IPS5Db-EDI' => 'edi',
            default => 'paquete',
        };
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
}
