<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SqlServerSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class SqlServerSearchController extends Controller
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

            $eventosExternos = collect($result['trackingRows'])
                ->map(function ($row) {
                    $eventDate = '';

                    if (!empty($row->EVENT_GMT_DT)) {
                        try {
                            $eventDate = Carbon::parse($row->EVENT_GMT_DT)->format('Y-m-d H:i:s');
                        } catch (Throwable) {
                            $eventDate = (string) $row->EVENT_GMT_DT;
                        }
                    }

                    $eventType = isset($row->EVENT_TYPE_NM_ES) ? (string) $row->EVENT_TYPE_NM_ES : '';
                    $eventType = $this->mapEventType($this->fixMojibake($eventType));

                    $condition = isset($row->CONDITION_TXT) ? (string) $row->CONDITION_TXT : '';
                    $condition = $this->fixMojibake($condition);

                    $office = trim(implode(' - ', array_filter([
                        isset($row->OFFICE_FCD) ? trim((string) $row->OFFICE_FCD) : '',
                        isset($row->OFFICE_NM) ? trim((string) $row->OFFICE_NM) : '',
                    ])));

                    $nextOffice = isset($row->NEXT_OFFICE_FCD) ? trim((string) $row->NEXT_OFFICE_FCD) : '';

                    $mailitMFid = '';
                    if (($row->SOURCE_DB ?? '') !== 'IPS5Db-EDI') {
                        $mailitMFid = isset($row->MAILITM_FID) ? (string) $row->MAILITM_FID : '';
                    }

                    return [
                        'mailitM_PID' => isset($row->MAILITM_PID) ? strtolower(trim((string) $row->MAILITM_PID)) : '',
                        'mailitM_FID' => $mailitMFid,
                        'eventType' => $eventType,
                        'eventDate' => $eventDate,
                        'office' => $office,
                        'scanned' => isset($row->SCANNED_TXT) ? (string) $row->SCANNED_TXT : '',
                        'workstation' => isset($row->WORKSTATION_TXT) ? (string) $row->WORKSTATION_TXT : '',
                        'condition' => $condition,
                        'nextOffice' => $nextOffice,
                    ];
                })
                ->sortByDesc(function (array $evento) {
                    return strtotime($evento['eventDate'] ?? '1970-01-01') ?: 0;
                })
                ->values();

            return response()->json([
                'codigo' => $result['codigo'],
                'packages' => [],
                'eventos_locales' => [],
                'eventos_externos' => $eventosExternos,
            ]);
        } catch (Throwable $e) {
            Log::error('Error consultando SQL Server API', [
                'codigo' => strtoupper(trim($codigo)),
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'codigo' => strtoupper(trim($codigo)),
                'packages' => [],
                'eventos_locales' => [],
                'eventos_externos' => [],
            ], 500);
        }
    }

    private function mapEventType($eventType): string
    {
        $eventMappings = [
            "Recibir envío del cliente (salida)" => "Paquete recibido del cliente.",
            "Enviar envío a ubicación nacional (salida)" => "Paquete en camino a ubicación nacional.",
            "Recibir envío en oficina de cambio (salida)" => "Paquete recibido en oficina origen de tránsito.",
            "Enviar envío a aduana (salida)" => "Paquete enviado a aduana.",
            "Recibir envío en ubicación (salida)" => "Paquete recibido en centro de procesamiento.",
            "Registrar motivo de retención de envío por parte de aduana (Sal)" => "Paquete retenido en aduana.",
            "Devolver envío desde aduana (salida)" => "Paquete en devolución desde la aduana.",
            "Insertar envío en saca (salida)" => "Paquete incluido en la saca de envío.",
            "Eliminar envío de saca (salida)" => "Paquete eliminado del la saca de envío.",
            "Registrar detalles del envío (salida)" => "Detalles del paquete registrados.",
            "Registrar detalles del envío en oficina de cambio (salida)" => "Detalles del paquete registrados en oficina de tránsito.",
            "Enviar envío al extranjero (recibido por EDI)" => "Paquete enviado al extranjero.",
            "Insertar envío en saca nacional" => "Paquete incluido en la saca nacional.",
            "Eliminar envío de saca nacional" => "Paquete eliminado de la saca nacional.",
            "Cancelar exportación de envío" => "Exportación del paquete cancelada.",
            "Recibir envío en oficina de cambio (entrada)" => "Paquete recibido en oficina destino de tránsito.",
            "Enviar envío a aduana (entrada)" => "Paquete en camino a aduana.",
            "Recibir envío en oficina de entrega (entrada)" => "Paquete recibido en oficina de entrega(Listo para entregar).",
            "Recibir envío en ubicación (entrada)" => "Paquete recibido en ubicación específica.",
            "Registrar información de aduanas sobre el envío (entrada)" => "Información de aduana del paquete registrada.",
            "Enviar envío a ubicación nacional (entrada)" => "Paquete en camino a ubicación nacional.",
            "Intento fallido de entrega de envío (entrada)" => "Intento fallido de entrega del paquete.",
            "Entregar envío (entrada)" => "Paquete entregado exitosamente.",
            "Devolver envío desde aduana (entrada)" => "Paquete en devolución desde aduana.",
            "Transferir envío al agente de entrega (entrada)" => "Paquete transferido al agente de entrega.",
            "Recibir envío desde el extranjero (recibido por EDI)" => "Paquete recibido desde el extranjero.",
            "Registrar información del destinatario (entrada)" => "Información del destinatario del paquete registrada.",
            "Recibir envío en oficina de cambio (entrada-int.-recon.)" => "Paquete recibido en oficina de tránsito internacional.",
            "Recibir envío en oficina de cambio (entrada-nac.-recon.)" => "Paquete recibido en oficina de tránsito nacional.",
            "Recepción automatizada de envío en oficina de cambio (entrada)" => "Paquete recibido automáticamente en oficina de tránsito.",
            "Creación automática de envío faltante (entrada)" => "Paquete creado automáticamente.",
            "Actualizar envío (salida)" => "Paquete actualizado",
            "Actualizar envío (entrada)" => "Paquete actualizado",
            "Rectificar acontecimiento de PSD de envío (salida)" => "Corrección de datos del paquete",
            "Rectificar acontecimiento de PSD de envío (entrada)" => "Corrección de datos del paquete",
            "Envío de manifiesto enviado a ubicación (entrada)" => "Saca registrado en ubicación.",
            "Envío de manifiesto enviado a aduana (entrada)" => "Saca enviado a aduana para revisión.",
            "Envío de manifiesto recibido en ubicación (entrada)" => "Saca recibido en ubicación.",
            "Envío de manifiesto transferido a agente de entrega (entrada)" => "Saca transferido al agente de entrega.",
            "Recepción automática de envío: sin digitalización" => "Paquete recibido automáticamente: pendiente de digitalización.",
            "Retener envío en oficina de cambio (salida)" => "Paquete retenido en oficina de tránsitos.",
            "Retener envío en oficina de cambio (entrada)" => "Paquete retenido en oficina de tránsito.",
            "Recibir envío en centro de clasificación (entrada)" => "Paquete recibido en centro de clasificación.",
            "Enviar envío desde centro de clasificación (entrada)" => "Paquete procesado en centro de clasificación.",
            "Retener envío en punto de entrega (entrada)" => "Paquete retenido en punto de entrega.",
            "Enviar envío para entrega física (entrada)" => "Paquete en camino para entrega física.",
            "Recibir envío en punto de recogida (entrada)" => "Paquete recibido en punto de recogida.",
            "Detener importación de envío (entrada)" => "Importación del paquete detenida.",
            "Envío insertado en un envase externo (salida)" => "Paquete incluido en una saca externo.",
            "Recoger el artículo del cliente (Otb)" => "Paquete recogido por el cliente.",
            "Registrar detalles del envío (entrada)" => "Detalles del paquete registrados.",
            "Artículo eliminado de contenedor externo (Otb)" => "Paquete eliminado del contenedor externo.",
            "Detener envío al remitente" => "Retorno del paquete al remitente detenido.",
            "Crear envase (salida)" => "Contenedor del paquete creado.",
            "Cerrar envase (salida)" => "Contenedor del paquete cerrado.",
            "Reabrir envase (salida)" => "Contenedor del paquete reabierto.",
            "Enviar envase a ubicación nacional (salida)" => "Contenedor del paquete enviado a ubicación nacional.",
            "Recibir envase desde oficina (salida)" => "Contenedor del paquete recibido desde oficina postal.",
            "Cambiar embarque del envase (salida)" => "Transporte del contenedor del paquete modificado.",
            "Enviar envase al extranjero (salida)" => "Contenedor del paquete enviado al extranjero.",
            "Enviar envase al extranjero (recibido por PREDES EDI)" => "Contenedor del paquete enviado al extranjero (confirmado electrónicamente).",
            "Enviar envase al extranjero (recibido por PRECON EDI)" => "Contenedor del paquete enviado al extranjero (confirmado electrónicamente).",
            "Recibir envase desde el extranjero (entrada)" => "Contenedor del paquete recibido desde el extranjero.",
            "Abrir envase (entrada)" => "Contenedor del paquete abierto en destino.",
            "Enviar envase a ubicación nacional (entrada)" => "Contenedor del paquete enviado a ubicación nacional.",
            "Recibir envase en ubicación nacional (entrada)" => "Contenedor del paquete recibido en ubicación nacional.",
            "Recibir envase desde el extranjero (recibido por RESDES EDI)" => "Contenedor del paquete recibido desde el extranjero.",
            "Recibir envase desde el extranjero (recibido por RESCON EDI)" => "Contenedor del paquete recibido desde el extranjero.",
            "Digitalización de envase no finalizada" => "Digitalización del contenedor del paquete incompleta.",
            "Digitalización de envase finalizada" => "Digitalización del contenedor del paquete finalizada.",
            "Marcar envase como eliminado" => "Contenedor del paquete marcado como eliminado.",
            "Tratar envase en el transportista (recibido por EDI)" => "Contenedor del paquete gestionado por transportista.",
            "Actualizar envase (salida)" => "Contenedor del paquete actualizado (salida).",
            "Actualizar envase (entrada)" => "Contenedor del paquete actualizado (entrada).",
            "Rectificar acontecimiento de PSD del envase (salida)" => "Corrección de datos del contenedor del paquete (salida).",
            "Rectificar acontecimiento de PSD del envase (entrada)" => "Corrección de datos del contenedor (entrada).",
            "Envase de manifiesto recibido (entrada)" => "Manifiesto del contenedor recibido.",
            "Envase de manifiesto enviado (entrada)" => "Manifiesto del contenedor enviado.",
            "RESDIT aceptado (creación)" => "Solicitud RESDIT aceptada.",
            "Cambiar contenedor de envase (salida)" => "Contenedor cambiado.",
            "Cambiar embarque nacional" => "Cambio en embarque nacional.",
            "Envase evaluado para muestreo" => "Contenedor evaluado para muestreo.",
            "Recibir envase de trasbordo directo desde el extranjero (Ent)" => "Contenedor recibido desde trasbordo directo extranjero.",
            "Marcar como transportado fuera de un embarque" => "Marcado como transportado fuera de embarque.",
            "Envase pesado desde la báscula" => "Contenedor pesado en báscula.",
            "Envase seleccionado para muestreo" => "Contenedor seleccionado para muestreo.",
            "Comprobación por rayos X" => "Inspección por rayos X realizada.",
            "Crear despacho (salida)" => "Despacho creado (salida).",
            "Cerrar despacho (salida)" => "Despacho cerrado (salida).",
            "Reabrir despacho (salida)" => "Despacho reabierto (salida).",
            "Cambiar embarque del despacho (salida)" => "Cambio en embarque del despacho (salida).",
            "Enviar despacho al extranjero (recibido por EDI)" => "Despacho enviado al extranjero (confirmado electrónicamente).",
            "Recibir despacho desde el extranjero (recibido por EDI)" => "Despacho recibido desde el extranjero.",
            "Marcar despacho como eliminado" => "Despacho marcado como eliminado.",
            "Actualizar despacho (salida)" => "Despacho actualizado (salida).",
            "Actualizar despacho (entrada)" => "Despacho actualizado (entrada).",
            "Crear embarque (salida)" => "Embarque creado (salida).",
            "Cerrar embarque (salida)" => "Embarque cerrado (salida).",
            "Reabrir embarque (salida)" => "Embarque reabierto (salida).",
            "Enviar embarque al extranjero (recibido por EDI)" => "Embarque enviado al extranjero (confirmado electrónicamente).",
            "Recibir embarque desde el extranjero (recibido por EDI)" => "Embarque recibido desde el extranjero.",
            "Tratar embarque en el transportista (recibido por EDI)" => "Embarque gestionado por transportista.",
            "Actualizar embarque (salida)" => "Embarque actualizado (salida).",
            "Marcar emb como eliminado" => "Expedición nacional marcado como eliminado.",
            "Actualizar embarque (entrada)" => "Embarque actualizado (entrada).",
            "Crear saca interna (salida)" => "Saca interna creada (salida).",
            "Cerrar saca interna (salida)" => "Saca interna cerrada (salida).",
            "Agregar saca interna al envase (salida)" => "Saca interna añadida al contenedor (salida).",
            "Eliminar saca interna de envase (salida)" => "Saca interna eliminada del contenedor (salida).",
            "Reabrir saca interna (salida)" => "Saca interna reabierta (salida).",
            "Recibir saca interna (entrada)" => "Saca interna recibida (entrada).",
            "Abrir saca interna (entrada)" => "Saca interna abierta (entrada).",
            "Marcar saca interna como eliminada" => "Saca interna marcada como eliminada.",
            "Crear BV propio" => "BV propio creado.",
            "Enviar BV a corresponsal" => "BV enviado al corresponsal.",
            "Recibir BV aceptado por corresponsal" => "BV aceptado por corresponsal.",
            "Recibir BV anotado por corresponsal" => "BV anotado por corresponsal recibido.",
            "Actualizar BV propio" => "BV propio actualizado.",
            "Recibir BV de corresponsal" => "BV recibido del corresponsal.",
            "Aceptar BV" => "BV aceptado.",
            "Anotar BV" => "BV anotado.",
            "Actualizar BV recibido" => "BV recibido actualizado.",
            "Aceptar BV electrónico recibido" => "BV electrónico aceptado.",
            "Importado de XML" => "Datos importados desde XML.",
            "Marcar BV como eliminado" => "BV marcado como eliminado.",
            "Crear envase nacional" => "Saca nacional creado.",
            "Cerrar envase nacional" => "Saca nacional cerrado.",
            "Reabrir envase nacional" => "Saca nacional reabierto.",
            "Enviar envase nacional a ubicación" => "Saca nacional enviado a ubicación.",
            "Recibir envase nacional en ubicación" => "Saca nacional recibido en ubicación.",
            "Crear despacho nacional" => "Saca nacional creado.",
            "Cerrar despacho nacional" => "Saca nacional cerrado.",
            "Reabrir despacho nacional" => "Saca nacional reabierto.",
            "Marcar despacho nacional como eliminado" => "Saca nacional marcado como eliminado.",
            "Crear embarque nacional" => "Expedición nacional creado.",
            "Cerrar embarque nacional" => "Expedición nacional cerrado.",
            "Reabrir embarque nacional" => "Expedición nacional reabierto.",
            "Recibido por EDI" => "Paquete: datos recibidos por EDI.",
            "Crear/actualizar contenedor" => "Saca creada o actualizada.",
            "Recibir contenedor (entrada)" => "Saca recibida.",
            "Abrir contenedor (entrada)" => "Saca abierta.",
            "Digitalizar para transporte" => "Paquete: datos digitalizados para transporte.",
            "Creación/actualización automática a partir de proceso de salida" => "Paquete: creado o actualizado automáticamente desde proceso de salida.",
            "Creación/actualización desde especificación de documento" => "Paquete: creado o actualizado desde especificación de documento.",
            "Modificar por BV" => "Modificado según BV.",
            "Marcar como eliminado" => "Paquete: marcado como eliminado.",
            "Documento de cuenta con formulario oficial" => "Paquete: documento oficial registrado.",
            "Modificación administrativa por especificación de documento" => "Paquete: modificado administrativamente según documento.",
            "Creación/actualización automática desde PREDES" => "Paquete: creado o actualizado automáticamente desde PREDES.",
            "Creación automática a partir de estimación" => "Paquete: creado automáticamente desde estimación.",
            "Modificación administrativa por BV" => "Paquete: modificado administrativamente por BV.",
            "Explicable: no formado" => "Paquete: registro no formado.",
            "Validar documento" => "Paquete: documento validado.",
            "Invalidar documento" => "Paquete: documento invalidado.",
            "Modificar identificador" => "Paquete: identificador modificado.",
            "Creación automática de PREDES no validados" => "PREDES no validado creado automáticamente.",
            "Crear declaración" => "Declaración creada.",
            "Enviar declaración" => "Declaración enviada.",
            "Reconocer declaración" => "Declaración reconocida.",
            "Pago recibido" => "Paquete: pago recibido.",
            "Marcar declaración como eliminada" => "Declaración marcada como eliminada.",
            "Actualizar declaración" => "Declaración actualizada."
        ];

        return $eventMappings[$eventType] ?? $eventType;
    }

    private function fixMojibake(string $value): string
    {
        $value = str_replace(
            ['EnvÃ­o', 'envÃ­o', 'ubicaciÃ³n', 'trÃ¡nsito', 'devoluciÃ³n', 'informaciÃ³n', 'PaÃ­s'],
            ['Envío', 'envío', 'ubicación', 'tránsito', 'devolución', 'información', 'País'],
            $value
        );

        return str_replace(
            ['EnvÃƒÂ­o', 'envÃƒÂ­o', 'ubicaciÃƒÂ³n', 'trÃƒÂ¡nsito', 'devoluciÃƒÂ³n', 'informaciÃƒÂ³n', 'PaÃƒÂ­s'],
            ['Envío', 'envío', 'ubicación', 'tránsito', 'devolución', 'información', 'País'],
            $value
        );
    }
}