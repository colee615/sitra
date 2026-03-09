<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SqlServerSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class SqlServerExternalSearchSafeController extends Controller
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
            'codigo' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9-]+$/'],
        ]);

        $codigo = $validated['codigo'];

        try {
            $result = $searchService->search($codigo);
            $originCountry = $this->resolveOriginCountry($result['packageRows'] ?? [], $result['codigo'] ?? $codigo);

            return response()->json([
                'codigo' => $result['codigo'] ?? strtoupper(trim($codigo)),
                'tipo_servicio' => $this->resolveServiceType($result['codigo'] ?? $codigo),
                'eventos_externos' => $this->transformExternalEvents($result['trackingRows'] ?? [], $originCountry),
            ]);
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
                    'eventType' => $this->mapEventType($eventType),
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

    private function mapEventType(string $eventType): string
    {
        $eventMappings = [
            'Recibir envío del cliente (salida)' => 'Paquete recibido del cliente.',
            'Enviar envío a ubicación nacional (salida)' => 'Paquete en camino a ubicación nacional.',
            'Recibir envío en oficina de cambio (salida)' => 'Paquete recibido en oficina origen de tránsito.',
            'Enviar envío a aduana (salida)' => 'Paquete enviado a aduana.',
            'Recibir envío en ubicación (salida)' => 'Paquete recibido en centro de procesamiento.',
            'Registrar motivo de retención de envío por parte de aduana (Sal)' => 'Paquete retenido en aduana.',
            'Devolver envío desde aduana (salida)' => 'Paquete en devolución desde la aduana.',
            'Insertar envío en saca (salida)' => 'Paquete incluido en la saca de envío.',
            'Eliminar envío de saca (salida)' => 'Paquete eliminado del la saca de envío.',
            'Registrar detalles del envío (salida)' => 'Detalles del paquete registrados.',
            'Registrar detalles del envío en oficina de cambio (salida)' => 'Detalles del paquete registrados en oficina de tránsito.',
            'Enviar envío al extranjero (recibido por EDI)' => 'Paquete enviado al extranjero.',
            'Insertar envío en saca nacional' => 'Paquete incluido en la saca nacional.',
            'Eliminar envío de saca nacional' => 'Paquete eliminado de la saca nacional.',
            'Cancelar exportación de envío' => 'Exportación del paquete cancelada.',
            'Recibir envío en oficina de cambio (entrada)' => 'Paquete recibido en oficina destino de tránsito.',
            'Enviar envío a aduana (entrada)' => 'Paquete en camino a aduana.',
            'Recibir envío en oficina de entrega (entrada)' => 'Paquete recibido en oficina de entrega(Listo para entregar).',
            'Recibir envío en ubicación (entrada)' => 'Paquete recibido en ubicación específica.',
            'Registrar información de aduanas sobre el envío (entrada)' => 'Información de aduana del paquete registrada.',
            'Enviar envío a ubicación nacional (entrada)' => 'Paquete en camino a ubicación nacional.',
            'Intento fallido de entrega de envío (entrada)' => 'Intento fallido de entrega del paquete.',
            'Entregar envío (entrada)' => 'Paquete entregado exitosamente.',
            'Devolver envío desde aduana (entrada)' => 'Paquete en devolución desde aduana.',
            'Transferir envío al agente de entrega (entrada)' => 'Paquete transferido al agente de entrega.',
            'Recibir envío desde el extranjero (recibido por EDI)' => 'Paquete recibido desde el extranjero.',
            'Registrar información del destinatario (entrada)' => 'Información del destinatario del paquete registrada.',
            'Recibir envío en oficina de cambio (entrada-int.-recon.)' => 'Paquete recibido en oficina de tránsito internacional.',
            'Recibir envío en oficina de cambio (entrada-nac.-recon.)' => 'Paquete recibido en oficina de tránsito nacional.',
            'Recepción automatizada de envío en oficina de cambio (entrada)' => 'Paquete recibido automáticamente en oficina de tránsito.',
            'Creación automática de envío faltante (entrada)' => 'Paquete creado automáticamente.',
            'Actualizar envío (salida)' => 'Paquete actualizado',
            'Actualizar envío (entrada)' => 'Paquete actualizado',
            'Rectificar acontecimiento de PSD de envío (salida)' => 'Corrección de datos del paquete',
            'Rectificar acontecimiento de PSD de envío (entrada)' => 'Corrección de datos del paquete',
            'Envío de manifiesto enviado a ubicación (entrada)' => 'Saca registrado en ubicación.',
            'Envío de manifiesto enviado a aduana (entrada)' => 'Saca enviado a aduana para revisión.',
            'Envío de manifiesto recibido en ubicación (entrada)' => 'Saca recibido en ubicación.',
            'Envío de manifiesto transferido a agente de entrega (entrada)' => 'Saca transferido al agente de entrega.',
            'Recepción automática de envío: sin digitalización' => 'Paquete recibido automáticamente: pendiente de digitalización.',
            'Retener envío en oficina de cambio (salida)' => 'Paquete retenido en oficina de tránsitos.',
            'Retener envío en oficina de cambio (entrada)' => 'Paquete retenido en oficina de tránsito.',
            'Recibir envío en centro de clasificación (entrada)' => 'Paquete recibido en centro de clasificación.',
            'Enviar envío desde centro de clasificación (entrada)' => 'Paquete procesado en centro de clasificación.',
            'Retener envío en punto de entrega (entrada)' => 'Paquete retenido en punto de entrega.',
            'Enviar envío para entrega física (entrada)' => 'Paquete en camino para entrega física.',
            'Recibir envío en punto de recogida (entrada)' => 'Paquete recibido en punto de recogida.',
            'Detener importación de envío (entrada)' => 'Importación del paquete detenida.',
            'Recibido por EDI' => 'Paquete: datos recibidos por EDI.',
        ];

        return $eventMappings[$eventType] ?? $eventType;
    }

    private function resolveOriginCountry(iterable $packageRows, string $codigo): string
    {
        $package = collect($packageRows)->first();

        $countryCode = isset($package->ORIG_COUNTRY_CD) ? trim((string) $package->ORIG_COUNTRY_CD) : '';
        $countryName = $this->normalizeText(isset($package->ORIG_COUNTRY_NM) ? (string) $package->ORIG_COUNTRY_NM : '');

        if ($countryName !== '') {
            return $countryCode !== '' ? $countryCode . ' - ' . $countryName : $countryName;
        }

        $s10Code = strtoupper(trim($codigo));
        $countryCode = strlen($s10Code) >= 2 ? substr($s10Code, -2) : '';

        if ($countryCode === '') {
            return '';
        }

        $countryName = $this->countryNameFromS10($countryCode);

        return $countryName !== '' ? $countryCode . ' - ' . $countryName : $countryCode;
    }

    private function countryNameFromS10(string $countryCode): string
    {
        $countries = [
            'AR' => 'Argentina',
            'BE' => 'Belgica',
            'BO' => 'Bolivia',
            'BR' => 'Brasil',
            'CA' => 'Canada',
            'CL' => 'Chile',
            'CN' => 'China',
            'CO' => 'Colombia',
            'DE' => 'Alemania',
            'ES' => 'Espana',
            'FR' => 'Francia',
            'GB' => 'Reino Unido',
            'IT' => 'Italia',
            'JP' => 'Japon',
            'MX' => 'Mexico',
            'NL' => 'Paises Bajos',
            'PE' => 'Peru',
            'PT' => 'Portugal',
            'PY' => 'Paraguay',
            'US' => 'Estados Unidos',
            'UY' => 'Uruguay',
            'VE' => 'Venezuela',
        ];

        return $countries[$countryCode] ?? '';
    }

    private function resolveServiceType(string $codigo): string
    {
        $prefix = strtoupper(substr(trim($codigo), 0, 1));

        return match ($prefix) {
            'E' => 'EMS',
            'C' => 'Encomiendas',
            'R' => 'Certificadas',
            'U', 'L' => 'Ordinarias',
            default => '',
        };
    }

    private function cleanLabel(string $value): string
    {
        $value = $this->normalizeText($value);
        $value = preg_replace('/\s+/', ' ', trim($value));

        return $value ?? '';
    }

    private function normalizeText(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        return str_replace(
            [
                'EnvÃ­o', 'envÃ­o', 'ubicaciÃ³n', 'trÃ¡nsito', 'devoluciÃ³n', 'informaciÃ³n', 'PaÃ­s',
                'RecepciÃ³n', 'CreaciÃ³n', 'electrÃ³nicamente', 'clasificaciÃ³n', 'fÃ­sica', 'artÃ­culo',
                'bÃ¡scula', 'ComprobaciÃ³n', 'declaraciÃ³n', 'especificaciÃ³n', 'automÃ¡ticamente',
                'aÃ±adida', 'ExpediciÃ³n',
            ],
            [
                'Envío', 'envío', 'ubicación', 'tránsito', 'devolución', 'información', 'País',
                'Recepción', 'Creación', 'electrónicamente', 'clasificación', 'física', 'artículo',
                'báscula', 'Comprobación', 'declaración', 'especificación', 'automáticamente',
                'añadida', 'Expedición',
            ],
            $value
        );
    }
}
