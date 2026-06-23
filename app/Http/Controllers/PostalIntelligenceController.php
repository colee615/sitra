<?php

namespace App\Http\Controllers;

use App\Services\CdsDbSearchService;
use App\Services\TrackingSearchCacheService;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Throwable;

class PostalIntelligenceController extends Controller
{
    public function index(
        Request $request,
        TrackingSearchCacheService $ipsSearchService,
        CdsDbSearchService $cdsSearchService
    ) {
        if (!$request->user() || !$request->user()->hasRole('admin')) {
            abort(403, 'Solo los administradores pueden ver esta pagina.');
        }

        $codigo = trim((string) $request->query('codigo', ''));

        $ips = [
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
            'error' => null,
            'cache_status' => null,
            'stale_fallback' => false,
        ];

        $cds = [
            'codigo' => strtoupper($codigo),
            'objectRows' => collect(),
            'stateRows' => collect(),
            'declarationRows' => collect(),
            'declarationEventRows' => collect(),
            'responseRows' => collect(),
            'responseEventRows' => collect(),
            'ediExportRows' => collect(),
            'auditRows' => collect(),
            'anDeclarationRows' => collect(),
            'error' => null,
        ];

        if ($codigo !== '') {
            try {
                $lookup = $ipsSearchService->search($codigo);
                $ips = array_merge($ips, $lookup['data'], [
                    'error' => null,
                    'cache_status' => $lookup['cache_status'] ?? null,
                    'stale_fallback' => (bool) ($lookup['stale_fallback'] ?? false),
                ]);
            } catch (Throwable $e) {
                $ips['error'] = $e->getMessage();
            }

            try {
                $cds = array_merge($cds, $cdsSearchService->search($codigo), [
                    'error' => null,
                ]);
            } catch (Throwable $e) {
                $cds['error'] = $e->getMessage();
            }
        }

        return view('consultas.index', [
            'codigo' => strtoupper($codigo),
            'ips' => $ips,
            'cds' => $cds,
            'story' => $this->buildStory($ips, $cds),
        ]);
    }

    private function buildStory(array $ips, array $cds): array
    {
        $timeline = collect();

        $mainPackage = collect($ips['packageRows'] ?? [])->first();
        $mainObject = collect($cds['objectRows'] ?? [])->first();
        $currentState = collect($cds['stateRows'] ?? [])->first();
        $latestIpsEvent = collect($ips['trackingRows'] ?? [])->first();

        foreach (collect($cds['objectRows'] ?? [])->take(1) as $object) {
            $timeline->push([
                'source' => 'CDS',
                'type' => 'object',
                'title' => 'Objeto registrado en CDS',
                'summary' => trim(implode(' -> ', array_filter([
                    $object->ORIG_POST_ORGANIZATION_CD ?? '',
                    $object->DEST_POST_ORGANIZATION_CD ?? '',
                ]))),
                'occurred_at' => $this->formatDate($object->POSTING_DATE ?? null),
                'sort_at' => $this->sortDate($object->POSTING_DATE ?? null),
                'tone' => 'success',
            ]);
        }

        foreach (collect($cds['declarationEventRows'] ?? [])->take(6) as $event) {
            $timeline->push([
                'source' => 'CDS',
                'type' => 'declaration_event',
                'title' => 'Evento documental CDS ' . trim((string) ($event->CDS_EVENT_TYPE_CD ?? '-')),
                'summary' => 'Oficina: ' . trim((string) ($event->OFFICE_CD ?? '-')),
                'occurred_at' => $this->formatDate($event->D_EVENT_GMT_DT ?? null),
                'sort_at' => $this->sortDate($event->D_EVENT_GMT_DT ?? null),
                'tone' => 'success',
            ]);
        }

        foreach (collect($cds['responseEventRows'] ?? [])->take(4) as $event) {
            $timeline->push([
                'source' => 'CDS',
                'type' => 'response_event',
                'title' => 'Respuesta CDS ' . trim((string) ($event->CDS_EVENT_TYPE_CD ?? '-')),
                'summary' => 'Oficina: ' . trim((string) ($event->OFFICE_CD ?? '-')),
                'occurred_at' => $this->formatDate($event->R_EVENT_GMT_DT ?? null),
                'sort_at' => $this->sortDate($event->R_EVENT_GMT_DT ?? null),
                'tone' => 'success',
            ]);
        }

        foreach (collect($cds['ediExportRows'] ?? [])->take(4) as $event) {
            $message = trim((string) ($event->EDI_MESSAGE_CD ?? $event->EDI_MESSAGE_TYPE ?? '-'));
            $timeline->push([
                'source' => 'CDS',
                'type' => 'edi_export',
                'title' => 'Exportacion EDI ' . $message,
                'summary' => trim(implode(' -> ', array_filter([
                    $event->SENDER_ORGANIZATION ?? '',
                    $event->RECIPIENT_ORGANIZATION ?? '',
                ]))),
                'occurred_at' => $this->formatDate($event->EVENT_DATE ?? null),
                'sort_at' => $this->sortDate($event->EVENT_DATE ?? null),
                'tone' => 'success',
            ]);
        }

        foreach (collect($ips['trackingRows'] ?? [])->take(8) as $event) {
            $timeline->push([
                'source' => 'IPS',
                'type' => 'tracking_event',
                'title' => trim((string) ($event->EVENT_TYPE_NM_ES ?? ('Evento #' . ($event->EVENT_TYPE_CD ?? '-')))),
                'summary' => trim(implode(' | ', array_filter([
                    trim(implode(' - ', array_filter([$event->OFFICE_FCD ?? '', $event->OFFICE_NM ?? '']))),
                    trim((string) ($event->DETAIL_TXT ?? '')),
                    trim((string) ($event->SOURCE_DB ?? 'IPS5Db')),
                ]))),
                'occurred_at' => $this->formatDate($event->EVENT_GMT_DT ?? null),
                'sort_at' => $this->sortDate($event->EVENT_GMT_DT ?? null),
                'tone' => 'info',
            ]);
        }

        $timeline = $timeline
            ->filter(fn (array $item) => $item['occurred_at'] !== '')
            ->sortBy('sort_at')
            ->values()
            ->all();

        return [
            'headline' => $this->headline($latestIpsEvent, $currentState),
            'status' => [
                'ips_now' => trim((string) ($latestIpsEvent->EVENT_TYPE_NM_ES ?? $mainPackage->POSTAL_STATUS_NM ?? 'Sin rastro operativo')),
                'cds_now' => trim((string) ($currentState->MAIL_STATE_NM ?? $mainObject->MAIL_STATE_CD ?? 'Sin rastro documental')),
            ],
            'timeline' => $timeline,
            'understanding' => $this->understandingNotes($ips, $cds, $timeline),
        ];
    }

    private function headline(?object $latestIpsEvent, ?object $currentState): string
    {
        $ipsText = trim((string) ($latestIpsEvent->EVENT_TYPE_NM_ES ?? ''));
        $cdsText = trim((string) ($currentState->MAIL_STATE_NM ?? ''));

        if ($ipsText !== '' && $cdsText !== '') {
            return 'CDS anticipa el objeto y IPS muestra su operacion real en Bolivia.';
        }

        if ($cdsText !== '') {
            return 'Hay rastro documental en CDS, pero no necesariamente movimiento operativo visible en IPS.';
        }

        if ($ipsText !== '') {
            return 'Hay movimiento operativo en IPS aun sin capa documental visible en CDS.';
        }

        return 'Sin suficiente informacion para construir la secuencia.';
    }

    private function understandingNotes(array $ips, array $cds, array $timeline): array
    {
        $notes = [];

        if (count($cds['objectRows'] ?? []) > 0) {
            $notes[] = 'CDS muestra el aviso documental o electronico recibido para este objeto.';
        }

        if (count($ips['trackingRows'] ?? []) > 0) {
            $notes[] = 'IPS muestra lo que Bolivia ya proceso operativamente del envio.';
        }

        if (count($cds['objectRows'] ?? []) > 0 && count($ips['trackingRows'] ?? []) > 0) {
            $notes[] = 'La lectura correcta suele ser: primero CDS, despues IPS.';
        }

        if (count($timeline) === 0) {
            $notes[] = 'No se pudo armar una linea de tiempo con fechas comparables.';
        }

        return $notes;
    }

    private function formatDate(mixed $value): string
    {
        if (empty($value)) {
            return '';
        }

        try {
            return Carbon::parse($value)->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return trim((string) $value);
        }
    }

    private function sortDate(mixed $value): int
    {
        if (empty($value)) {
            return 0;
        }

        try {
            return Carbon::parse($value)->timestamp;
        } catch (Throwable) {
            return 0;
        }
    }
}
