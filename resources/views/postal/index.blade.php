@extends('adminlte::page')
@section('title', $sectionTitle.' | SITRA')
@section('content_header')
    <div class="postal-heading"><div><span class="postal-eyebrow">SITRA · CONSULTA POSTAL</span><h1>{{ $sectionTitle }}</h1><p>{{ $sectionDescription }}</p></div><span class="postal-source"><i class="fas fa-shield-alt" aria-hidden="true"></i> Consulta interna</span></div>
@stop
@section('content')
<div class="postal-workspace">
    @if($section === 'cds' && !$code)
        <div class="postal-actions mb-3"><a class="btn btn-primary" href="{{ route('postal.customs.remittance') }}"><i class="fas fa-file-export"></i> Preparar remisión con varios paquetes</a><span class="postal-note align-self-center">Reúne las declaraciones CDS para revisión y entrega a Aduana.</span></div>
    @endif
    <form class="postal-search" action="{{ $searchRoute }}" method="get">
        <label for="postal-code">Código S10 o identificador local</label>
        <div class="postal-search-row"><i class="fas fa-barcode" aria-hidden="true"></i><input id="postal-code" name="codigo" value="{{ $code }}" maxlength="35" required autocomplete="off" placeholder="Escanea o escribe el código del paquete"><button class="btn btn-primary"><i class="fas fa-search" aria-hidden="true"></i> Buscar en {{ $searchTarget }}</button></div>
        @error('codigo')<p class="text-danger">{{ $message }}</p>@enderror
    </form>
    <div class="postal-sources" role="status">
        @foreach($sources as $source => $state)
            <div class="postal-source {{ $state === 'unavailable' ? 'postal-warning' : '' }}"><span class="postal-dot {{ $state === 'ok' ? 'is-ready' : '' }}"></span><strong>{{ $source }}</strong><span>{{ $sourceLabels[$state] }}</span></div>
        @endforeach
    </div>
    @if(!$code)
        <div class="postal-empty"><i class="fas fa-box-open" aria-hidden="true"></i><h2>Comienza con el código del envío</h2><p>Podrás revisar su recorrido, artículos declarados, documentos y responsables registrados.</p></div>
    @else
        @if(($identifier['valid'] ?? null) === false)<div class="alert alert-warning">{{ $identifier['message'] }} La consulta conserva el código que ingresaste.</div>@endif
        @if(!$found)
            <div class="postal-empty"><i class="fas fa-search" aria-hidden="true"></i><h2>No hay datos disponibles en esta consulta</h2><p>Revisa el código y el estado de cada fuente. Una conexión no disponible no significa que el paquete no exista.</p></div>
        @else
            <section class="postal-overview">
                <div><span class="postal-eyebrow">EXPEDIENTE DEL ENVÍO</span><h2>{{ $ipsPackage->MAILITM_FID ?? $cdsPackage->MAIL_OBJECT_ID ?? $code }}</h2><p>{{ $identifier['type'] }} · {{ $operationsMetrics['content_count'] }} artículos declarados encontrados entre las fuentes consultadas</p></div>
                <div class="postal-actions"><a class="btn btn-light" href="{{ route('postal.package.csv', ['codigo'=>$code]) }}"><i class="fas fa-file-csv"></i> Descargar expediente</a><a target="_blank" rel="noopener" class="btn btn-light" href="{{ route('postal.document', ['kind'=>'marbete','codigo'=>$code]) }}"><i class="fas fa-barcode"></i> Marbete interno</a>@if($declarations)<a target="_blank" rel="noopener" class="btn btn-light" href="{{ route('postal.document', ['kind'=>'aduana','codigo'=>$code]) }}"><i class="fas fa-print"></i> Imprimir CN23</a>@endif</div>
            </section>
            @if($section !== 'conjunto' && $showIps !== $showCds)
                @can($section === 'ips' ? 'postal.cds' : 'postal.ips')
                    <div class="postal-actions mb-3"><a class="btn btn-outline-primary" href="{{ route('postal.combined', ['codigo'=>$code]) }}"><i class="fas fa-project-diagram"></i> Ver expediente IPS + CDS de este envío</a></div>
                @endcan
            @endif
            <nav class="postal-expedient-nav" aria-label="Secciones del expediente">
                @if($showCds)
                    <a href="#customs"><strong><i class="fas fa-clipboard-list"></i> Declaraciones y respuestas CDS</strong><small>{{ count($declarations) }} declaraciones · {{ count($responses) }} respuestas · {{ count($cds['events'] ?? []) }} eventos</small></a>
                @endif
                @if($showIps)
                    <a href="#movements"><strong><i class="fas fa-route"></i> Recorrido y entrega</strong><small>{{ $operationsMetrics['movement_count'] }} movimientos · {{ $operationsMetrics['delivery_count'] }} registros de entrega · {{ $operationsMetrics['international_count'] }} mensajes EDI</small></a>
                    <a href="#shipment"><strong><i class="fas fa-box"></i> Datos del paquete</strong><small>Situación postal y contactos IPS</small></a>
                    <a href="#documents"><strong><i class="fas fa-boxes"></i> Despachos y sacas</strong><small>{{ $operationsMetrics['bag_count'] }} sacas vinculadas · {{ $operationsMetrics['manifest_count'] }} manifiestos y formularios</small></a>
                    <a href="#declared-content"><strong><i class="fas fa-box-open"></i> Contenido en IPS</strong><small>{{ $contentPieceRows->count() }} artículos · {{ $customsRows->count() }} fichas aduaneras</small></a>
                @endif
            </nav>
            @if($showCds)
                @include('postal.partials.cds-records')
            @endif
            @if($showIps)
            <section id="shipment" class="postal-panel"><div class="postal-section-heading"><h2><i class="fas fa-box"></i> Datos del paquete</h2><span>IPS</span></div>
                @if($operationalStatus['latest_event'])
                    <div class="postal-current"><span class="postal-eyebrow">ÚLTIMO MOVIMIENTO INFORMADO POR IPS</span><strong>{{ $operationalStatus['latest_event']->EVENT_TYPE_NM_ES ?? 'Movimiento sin descripción' }}</strong><small>{{ $operationalStatus['latest_event']->EVENT_LOCAL_DISPLAY ?? 'Hora local no informada' }} · {{ $operationalStatus['latest_event']->EVENT_TIME_LABEL ?? 'Fecha registrada' }} · {{ $operationalStatus['latest_event']->OFFICE_NM ?? $operationalStatus['latest_event']->OFFICE_FCD ?? 'Oficina no informada' }}</small></div>
                @elseif($ipsPackage && !empty($ipsPackage->EVT_TYPE_NM_ES))
                    <div class="postal-current"><span class="postal-eyebrow">ÚLTIMO MOVIMIENTO INFORMADO POR IPS</span><strong>{{ $ipsPackage->EVT_TYPE_NM_ES }}</strong><small>{{ $ipsPackage->EVT_LOCAL_DISPLAY ?? 'Hora local no informada' }} · {{ $ipsPackage->EVT_OFFICE_NM ?? 'Oficina no informada' }}</small></div>
                @else
                    <p class="postal-note">IPS no devolvió movimientos para este código.</p>
                @endif
                <p class="postal-note">El último registro consultado no confirma por sí solo la ubicación física actual.</p>
                @if($operationalStatus['stale'])<div class="alert alert-warning mb-2"><i class="fas fa-clock"></i> El último movimiento tiene {{ $operationalStatus['age_days'] }} días. Supera el umbral orientativo de {{ $operationalStatus['stale_after_days'] }} días; confirma el caso con la oficina. No es un plazo oficial de entrega.</div>@endif
                @if($operationalStatus['has_non_delivery_reason'])<div class="alert alert-warning mb-2"><i class="fas fa-truck"></i> IPS registra un motivo relacionado con una entrega no realizada. Revisa el detalle del intento antes de decidir el siguiente paso.</div>@endif
                <div class="postal-facts">
                    <div><span>Origen registrado en IPS</span><strong>{{ $ipsPackage->ORIG_COUNTRY_NM ?? 'No registrado' }}</strong></div>
                    <div><span>Destino registrado en IPS</span><strong>{{ $ipsPackage->DEST_COUNTRY_NM ?? 'No registrado' }}</strong></div>
                    <div><span>Peso en IPS</span><strong>{{ isset($ipsPackage->MAILITM_WEIGHT) ? $ipsPackage->MAILITM_WEIGHT.' kg' : 'No registrado' }}</strong></div>
                    <div><span>Clase postal</span><strong>{{ $ipsPackage->MAIL_CLASS_NM ?? $ipsPackage->MAIL_CLASS_CD ?? 'No registrada' }}</strong></div>
                </div>
                <div class="postal-contact-grid">@foreach(($ips['customerRows'] ?? []) as $person)<article><span class="postal-eyebrow">{{ ($person->SENDER_PAYEE_IND ?? '') === 'S' ? 'REMITENTE' : 'CONTACTO REGISTRADO' }}</span><h3>{{ trim(($person->CUSTOMER_FORENAME ?? '').' '.($person->CUSTOMER_NAME ?? '')) ?: 'Nombre no registrado' }}</h3><p>{{ $person->CUSTOMER_ADDRESS ?? '' }} {{ $person->CUSTOMER_CITY ?? '' }}</p><p>{{ $person->CUSTOMER_PHONE_NO ?? 'Teléfono no registrado' }}</p></article>@endforeach</div>
            </section>
            @endif
            @if($showIps)<section id="movements" class="postal-panel"><div class="postal-section-heading"><h2><i class="fas fa-route"></i> Recorrido y entrega</h2><span>IPS · más reciente primero</span></div><h3>Historial IPS</h3><p class="postal-note">Cada movimiento conserva la descripción del sistema de origen. El responsable corresponde al registro del evento.</p>
                @if($events->isNotEmpty())<div class="mb-3"><label for="event-filter">Buscar en el historial</label><input class="form-control" id="event-filter" type="search" placeholder="Evento, fecha, oficina o responsable" aria-controls="postal-timeline"><small id="event-filter-count" role="status" aria-live="polite"></small></div>@endif
                <div class="postal-timeline" id="postal-timeline">@forelse($events as $event)<article class="postal-event"><div class="postal-event-date"><time>{{ $event->EVENT_LOCAL_DISPLAY ?? 'Hora local no informada' }}</time><small>{{ $event->EVENT_TIME_LABEL ?? 'Fecha registrada' }} · IPS</small></div><div><span class="postal-source">{{ $event->SOURCE_DB ?? 'IPS' }} · {{ $event->EVENT_TYPE_CD ?? '—' }}</span><h3>{{ $event->EVENT_TYPE_NM_ES ?? 'Movimiento sin descripción' }}</h3><p><i class="fas fa-map-marker-alt"></i> {{ $event->OFFICE_NM ?? $event->OFFICE_FCD ?? 'Lugar no registrado' }}</p><p class="postal-note"><i class="fas fa-user"></i> {{ $event->USER_NM ?? $event->USER_FID ?? 'Responsable no informado por la fuente' }}</p>@if($event->NEXT_OFFICE_FCD ?? $event->NEXT_OFFICE_NM ?? false)<p><i class="fas fa-arrow-right"></i> Siguiente oficina: {{ trim(($event->NEXT_OFFICE_FCD ?? '').' '.($event->NEXT_OFFICE_NM ?? '')) }}</p>@endif @if(!empty($event->RETENTION_REASON_CD) || !empty($event->CONDITION_TXT) || !empty($event->ATTEMPTED_DELIVERY_LOCATION))<details><summary>Otros datos guardados por IPS</summary>@if(!empty($event->RETENTION_REASON_CD))<p>Motivo de retención (código IPS): {{ $event->RETENTION_REASON_CD }}</p>@endif @if(!empty($event->CONDITION_TXT))<p>Condición registrada: {{ $event->CONDITION_TXT }}</p>@endif @if(!empty($event->ATTEMPTED_DELIVERY_LOCATION))<p>Lugar del intento de entrega: {{ $event->ATTEMPTED_DELIVERY_LOCATION }}</p>@endif</details>@endif @if(!empty($event->DETAIL_TXT))<details><summary>Detalle del registro</summary><p>{{ $event->DETAIL_TXT }}</p></details>@endif</div></article>@empty<p class="postal-note">No hay movimientos IPS disponibles para mostrar.</p>@endforelse</div>
                <details id="delivery-records" class="postal-subsection" @if($deliveryRows->isNotEmpty()) open @endif>
                    <summary><i class="fas fa-truck"></i> Intentos y constancias de entrega <small>· {{ $deliveryRows->count() }} registros de IPS</small></summary>
                    <p class="postal-note">Estos son datos que IPS guardó para la entrega. Si un motivo aparece solo como código, se muestra como tal para no inventar su significado.</p>
                    <div class="table-responsive"><table class="table"><thead><tr><th>Fecha</th><th>Movimiento</th><th>Resultado / motivo (código)</th><th>Persona que recibió</th><th>Lugar registrado</th></tr></thead><tbody>
                    @forelse($deliveryRows as $delivery)<tr><td>{{ $delivery->EVENT_LOCAL_DISPLAY ?? 'Hora local no informada' }}</td><td>{{ $delivery->EVENT_TYPE_NM_ES ?? 'Movimiento de entrega' }}</td><td>@if($delivery->NON_DELIVERY_REASON_CD)No se completó la entrega · código {{ $delivery->NON_DELIVERY_REASON_CD }}@elseif($delivery->NON_DELIVERY_MEASURE_CD)Acción registrada · código {{ $delivery->NON_DELIVERY_MEASURE_CD }}@else IPS no informó un motivo de no entrega @endif</td><td>{{ $delivery->SIGNATORY_NM ?: 'No informado por IPS' }}</td><td>{{ trim(($delivery->DELIV_LOCATION ?? '').' '.($delivery->DELIV_POSTCODE ?? '')) ?: 'No informado por IPS' }}</td></tr>
                    @empty<tr><td colspan="5">IPS no devolvió constancias o intentos de entrega para este paquete.</td></tr>@endforelse
                    </tbody></table></div>
                </details>
                <details id="international" class="postal-subsection">
                    <summary><i class="fas fa-globe"></i> Mensajes internacionales (EDI) <small>· {{ $ediRows->count() }} registros recibidos</small></summary>
                    <p class="postal-note">EDI son mensajes que se intercambian entre operadores postales. Un mensaje puede llegar antes que un escaneo local; por eso se conserva su fecha y ubicación como los informó IPS.</p>
                    <div class="table-responsive"><table class="table"><thead><tr><th>Fecha del evento o captura</th><th>Mensaje postal</th><th>Ubicación informada</th><th>Operador remitente</th><th>Número de despacho</th></tr></thead><tbody>
                    @forelse($ediRows as $edi)<tr><td>{{ $edi->EVENT_LOCAL_DISPLAY ?? 'Fecha no informada' }}<small class="d-block text-muted">{{ $edi->EVENT_TIME_LABEL ?? 'Fecha local' }}</small></td><td>{{ $edi->EVENT_TYPE_NM_ES ?: 'Mensaje sin descripción de catálogo' }}<small class="d-block text-muted">Código: {{ $edi->EVENT_TYPE_CD ?? 'No informado' }}</small></td><td>{{ $edi->LOCATION_ID ?? 'No informado' }}</td><td>{{ $edi->SENDER_ID ?: 'No informado' }}</td><td>{{ $edi->DESPATCH_NUMBER ?: 'No informado' }}</td></tr>
                    @empty<tr><td colspan="5">No hay mensajes internacionales asociados a este paquete.</td></tr>@endforelse
                    </tbody></table></div>
                </details>
            </section>
            @endif
            @if($showIps)<section id="documents" class="postal-panel"><div class="postal-section-heading"><h2><i class="fas fa-file-alt"></i> Despachos, sacas y formularios</h2><span>IPS</span></div>
                @if($operationsMetrics['bag_count'] === 0)<p class="postal-note">No se encontró un vínculo a saca o despacho en los registros consultados. Esto no confirma por sí solo una incidencia.</p>@endif
                <div class="table-responsive"><table class="table"><thead><tr><th>Saca / receptáculo</th><th>Despacho</th><th>Desde</th><th>Hacia</th><th>Salida registrada</th></tr></thead><tbody>@forelse(($ips['logisticRows'] ?? []) as $row)<tr><td>@if(!empty($row->RECPTCL_FID))<a href="{{ route('postal.receptacles', ['marbete'=>$row->RECPTCL_FID]) }}">{{ $row->RECPTCL_FID }}</a>@else — @endif</td><td>@if(!empty($row->DESPTCH_FID))<a href="{{ route('postal.receptacles', ['marbete'=>$row->DESPTCH_FID]) }}">{{ $row->DESPTCH_FID }}</a>@else — @endif</td><td>{{ $row->ORIG_OFFICE_FCD ?? '—' }}</td><td>{{ $row->DEST_OFFICE_FCD ?? '—' }}</td><td>{{ $row->DEPARTURE_LOCAL_DISPLAY ?? '—' }}</td></tr>@empty<tr><td colspan="5">Sin despachos asociados disponibles.</td></tr>@endforelse</tbody></table></div>
                <h3>Formularios y manifiestos registrados</h3><p class="postal-note">Se muestra el nombre registrado en IPS, incluidos los formularios CN cuando la fuente los identifica.</p>
                <div class="table-responsive"><table class="table"><thead><tr><th>Identificador</th><th>Formulario</th><th>Fecha local</th><th>Oficina</th><th>Responsable</th></tr></thead><tbody>@forelse(($ips['manifestRows'] ?? []) as $row)<tr><td>{{ $row->MANIFEST_LIST_ID }}</td><td>{{ $row->FORM_NM ?? $row->MANIF_TYPE_ID ?? 'No informado' }}</td><td>{{ $row->CREATION_LOCAL_DISPLAY ?? '—' }}</td><td>{{ $row->OFFICE_NM ?? '—' }}</td><td>{{ $row->USER_NM ?? $row->USER_FID ?? 'No informado' }}</td></tr>@empty<tr><td colspan="5">Sin formularios asociados disponibles.</td></tr>@endforelse</tbody></table></div>
                @can('ips.read')<a href="{{ route('sqlserver.datos', ['codigo'=>$code]) }}" class="btn btn-outline-primary"><i class="fas fa-database"></i> Ver detalle técnico de IPS</a>@endcan
            </section>@endif
            @if($showIps)
                <section id="declared-content" class="postal-panel"><div class="postal-section-heading"><h2><i class="fas fa-box-open"></i> Contenido y datos de Aduana registrados en IPS</h2><span>{{ $contentPieceRows->count() }} artículos · {{ $customsRows->count() }} fichas</span></div>
                    <p class="postal-note">Estos datos son declaraciones guardadas en IPS; no equivalen a una inspección física ni a una decisión de Aduana.</p>
                    @forelse($customsRows as $customs)<div class="postal-facts"><div><span>Peso bruto declarado</span><strong>{{ $customs->DECLARED_GROSS_WEIGHT ?? 'No informado' }}</strong></div><div><span>Referencia aduanera del remitente</span><strong>{{ $customs->SENDER_CUSTOMS_REFERENCE_NO ?: 'No informada' }}</strong></div><div><span>Referencia aduanera del destinatario</span><strong>{{ $customs->RECIPIENT_CUSTOMS_REFERENCE_NO ?: 'No informada' }}</strong></div></div>@empty<p class="postal-note">IPS no devolvió una ficha de datos aduaneros para este paquete.</p>@endforelse
                    <div class="table-responsive"><table class="table"><thead><tr><th>Artículo declarado</th><th>Unidades</th><th>Valor</th><th>Peso neto</th><th>Origen / partida</th></tr></thead><tbody>@forelse($contentPieceRows as $piece)<tr><td>{{ $piece->DESCRIPTION ?: $piece->IDENTIFIER ?: 'Sin descripción' }}</td><td>{{ $piece->NUMBER_OF_UNITS ?? 'No informado' }}</td><td>{{ $piece->DECLARED_VALUE ?? 'No informado' }} {{ $piece->DECLARED_VALUE_CURRENCY_CD ?? '' }}</td><td>{{ $piece->NET_WEIGHT ?? 'No informado' }}</td><td>{{ $piece->ORIGIN_LOCATION ?: 'No informado' }}@if($piece->TARIFF_HEADING)<small class="d-block text-muted">Partida arancelaria: {{ $piece->TARIFF_HEADING }}</small>@endif</td></tr>@empty<tr><td colspan="5">No hay artículos desglosados en los datos aduaneros de IPS.</td></tr>@endforelse</tbody></table></div>
                </section>
            @endif
        @endif
    @endif
</div>
@include('footer')
@stop
@section('css')<link rel="stylesheet" href="{{ asset('css/postal-operations.css') }}"><link rel="stylesheet" href="{{ asset('css/postal-navigation.css') }}">@stop
@section('js')<script src="{{ asset('js/postal-workspace.js') }}" defer></script>@stop
