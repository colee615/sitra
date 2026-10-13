@extends('adminlte::page')
@section('title','Buscar marbetes y sacas IPS | SITRA')
@section('content')
<div class="postal-workspace">
    @if($detailMode)
    @if($error)<div class="alert alert-danger" role="alert">{{ $error }}</div>@endif
    @if($result)
        @if(($result['search_type'] ?? null) === 's8')
            <section class="postal-overview postal-s8-overview"><div><span class="postal-eyebrow">DESPACHO UPU · S8</span><h2>{{ $result['identifier'] }}</h2></div><div class="postal-bag-total"><strong>{{ $result['receptacle_total'] ?? count($result['receptacles']) }}</strong><span>sacas<br>vinculadas en IPS</span></div></section>
            @if(!empty($result['code_analysis']))
                <details class="postal-s8-code-details"><summary><i class="fas fa-barcode"></i> Ver desglose del código UPU S8</summary>@include('postal.partials.upu-code-analysis', ['analysis' => $result['code_analysis']])</details>
            @endif
            @if($result['truncated'])<div class="alert alert-warning">Se alcanzó el límite de resultados. Acota la búsqueda para revisar todos los registros.</div>@endif
            @forelse($result['dispatches'] as $dispatch)
                @php($dispatchBags = collect($result['receptacles'])->where('DESPTCH_PID', $dispatch->DESPTCH_PID))
                @php($linkedSacks = (int) ($dispatch->LINKED_RECPTCLS_NO ?? $dispatchBags->count()))
                @php($declaredSacks = is_numeric($dispatch->DESPTCH_RECPTCLS_NO ?? null) ? (int) $dispatch->DESPTCH_RECPTCLS_NO : null)
                <section class="postal-panel postal-s8-dispatch-panel">
                    <div class="postal-section-heading"><h2><i class="fas fa-plane-departure"></i> Datos del despacho</h2><span>Registro IPS</span></div>
                    <div class="postal-dispatch-route"><div><span>Origen</span><strong>{{ $dispatch->ORIG_OFFICE_FCD ?: 'No informado' }}</strong></div><i class="fas fa-arrow-right" aria-hidden="true"></i><div><span>Destino</span><strong>{{ $dispatch->DEST_OFFICE_FCD ?: 'No informado' }}</strong></div><div class="postal-dispatch-departure"><span>Salida</span><strong>{{ $dispatch->DESPTCH_DEPARTURE_DT ?: 'No registrada' }}</strong></div></div>
                    <div class="postal-dispatch-metrics"><div><span>Sacas vinculadas</span><strong>{{ $linkedSacks }}</strong></div><div><span>Sacas declaradas</span><strong>{{ $declaredSacks ?? 'No informadas' }}</strong></div><div><span>Peso del despacho</span><strong>{{ $dispatch->DESPTCH_WEIGHT !== null ? number_format((float)$dispatch->DESPTCH_WEIGHT,3,',','.').' kg' : 'No informado' }}</strong></div><div><span>Transporte</span><strong>{{ $dispatch->CONVEYANCE_TYPE_CD ?: 'No informado' }}</strong></div></div>
                    @if($declaredSacks === null)<div class="postal-check is-neutral"><i class="fas fa-info-circle"></i><span>IPS no informó la cantidad declarada. Hay {{ $linkedSacks }} sacas vinculadas al despacho.</span></div>
                    @elseif($declaredSacks === $linkedSacks)<div class="postal-check is-ok"><i class="fas fa-check-circle"></i><span>Coinciden las cantidades: {{ $declaredSacks }} en el despacho y {{ $linkedSacks }} vinculadas en IPS.</span></div>
                    @else<div class="postal-check is-warning"><i class="fas fa-exclamation-triangle"></i><span>Hay una diferencia: IPS declara {{ $declaredSacks }} sacas y se encontraron {{ $linkedSacks }} vinculadas al despacho.</span></div>@endif
                </section>
                <section class="postal-panel postal-s8-sacks-panel">
                    <div class="postal-section-heading"><h2><i class="fas fa-boxes"></i> Sacas del despacho</h2><span>{{ $dispatchBags->count() }} vinculadas</span></div>
                    <p class="postal-note">Eventos IPS muestra vínculos encontrados; declarados es el total anotado en cada saca.</p>
                    <div class="table-responsive"><table class="table"><thead><tr><th>Identificador S9</th><th>N.º de saca</th><th>Peso del código UPU</th><th>Peso registrado en IPS</th><th>Paquetes</th><th></th></tr></thead><tbody>
                    @forelse($dispatchBags as $bag)
                        @php($bagCode = \App\Services\Postal\UpuDispatchIdentifier::parse((string) ($bag->RECPTCL_FID ?? '')))
                        <tr><td><strong class="postal-s9-code">{{ $bag->RECPTCL_FID ?: 'Código no informado' }}</strong></td><td>{{ $bagCode['receptacle_sequence'] ?? '—' }}</td><td>{{ $bagCode['encoded_gross_weight_label'] ?? 'No decodificable' }}</td><td>{{ is_numeric($bag->RECPTCL_WEIGHT ?? null) ? number_format((float)$bag->RECPTCL_WEIGHT,3,',','.').' kg' : 'No informado' }}</td><td><strong>{{ $bag->LINKED_MAILITMS_NO ?? 0 }}</strong> eventos IPS<small class="d-block text-muted">{{ $bag->RECPTCL_MAILITMS_NO ?? 'Sin dato' }} declarados</small></td><td>@if($bag->RECPTCL_FID)<a class="btn btn-sm btn-outline-primary" href="{{ route('postal.receptacles',['marbete'=>$bag->RECPTCL_FID]) }}"><i class="fas fa-search"></i> Abrir saca</a>@endif</td></tr>
                    @empty<tr><td colspan="6">IPS no tiene sacas vinculadas a este despacho.</td></tr>@endforelse
                    </tbody></table></div>
                </section>
            @empty
                <div class="postal-empty"><i class="fas fa-search" aria-hidden="true"></i><h2>No encontramos ese despacho en IPS</h2><p>El código tiene estructura S8 válida, pero no coincide con DESPTCH_FID ni con el prefijo S8 de una saca S9 registrada.</p><code>{{ $result['identifier'] }}</code></div>
            @endforelse
        @elseif($result['receptacles'])
            @if(count($result['receptacles']) === 1)
                @php($bag = $result['receptacles'][0])
                <section class="postal-overview"><div><span class="postal-eyebrow">MARBETE / SACO ENCONTRADO EN IPS</span><h2>{{ $bag->RECPTCL_FID ?: $identifier }}</h2><p>Identificador del receptáculo asociado a tus paquetes en el registro IPS.</p></div><div class="postal-bag-total"><strong>{{ count($result['items']) }}</strong><span>paquetes<br>asociados</span></div></section>
                @if(($result['search_type'] ?? null) === 's9')<div class="postal-check is-neutral mb-3"><i class="fas fa-link"></i><span>Esta saca pertenece al despacho UPU <strong>{{ $result['code_analysis']['dispatch_code'] }}</strong>. <a class="btn btn-sm btn-outline-primary ml-2" href="{{ route('postal.receptacles',['marbete'=>$result['code_analysis']['dispatch_code']]) }}"><i class="fas fa-layer-group"></i> Ver despacho y sus sacas</a></span></div>@endif
                @if(!empty($result['code_analysis']))
                    @include('postal.partials.upu-code-analysis', ['analysis' => $result['code_analysis']])
                @endif
                <div class="postal-facts postal-panel"><div><span>Paquetes vinculados en eventos IPS</span><strong>{{ count($result['items']) }}</strong></div><div><span>Paquetes registrados en la saca</span><strong>{{ $bag->RECPTCL_MAILITMS_NO ?? 'Sin dato' }}</strong></div><div><span>Peso registrado en IPS</span><strong>{{ $bag->RECPTCL_WEIGHT !== null ? number_format((float)$bag->RECPTCL_WEIGHT,3,',','.').' kg' : 'No informado' }}</strong></div><div><span>Número de seguro</span><strong>{{ $bag->RECPTCL_SEAL_NUMBER ?: 'No registrado' }}</strong></div><div><span>Subclase postal</span><strong>{{ $bag->MAIL_SUBCLASS_FCD ?: 'No informada' }}</strong></div><div><span>Registro alternativo</span><strong>{{ $bag->RECPTCL_REG_NO ?: 'No registrado' }}</strong></div><div><span>Seguro alternativo</span><strong>{{ $bag->RECPTCL_INS_NO ?: 'No registrado' }}</strong></div></div>
                <section class="postal-panel postal-reconciliation"><div class="postal-section-heading"><h2><i class="fas fa-balance-scale"></i> Revisión rápida de la saca</h2><span>Comparación de registros IPS</span></div>
                    @if($reconciliation['count_matches'] === true)<div class="postal-check is-ok"><i class="fas fa-check-circle"></i><span>La cantidad coincide: IPS declara {{ $reconciliation['declared_count'] }} paquetes y se encontraron {{ $reconciliation['linked_count'] }} relacionados.</span></div>
                    @elseif($reconciliation['count_matches'] === false)<div class="postal-check is-warning"><i class="fas fa-exclamation-triangle"></i><span>La cantidad no coincide: IPS declara {{ $reconciliation['declared_count'] }} paquetes, pero los eventos consultados relacionan {{ $reconciliation['linked_count'] }}. Conviene revisar la saca en IPS.</span></div>
                    @else<div class="postal-check is-neutral"><i class="fas fa-info-circle"></i><span>No se puede confirmar la cantidad: falta el total declarado o IPS indicó que hay más registros de los mostrados.</span></div>@endif
                    <div class="postal-reconciliation-weights"><div><span>Peso registrado de la saca</span><strong>{{ $reconciliation['bag_weight'] !== null ? number_format($reconciliation['bag_weight'],3,',','.').' kg' : 'No informado' }}</strong></div><div><span>Suma de pesos individuales con dato</span><strong>{{ number_format($reconciliation['item_weight_total'],3,',','.') }} kg</strong><small>{{ $reconciliation['items_without_weight'] }} paquete(s) sin peso registrado</small></div></div>
                    <p class="postal-note mb-0">Los pesos se muestran como referencia: el peso de la saca puede incluir embalaje y los registros individuales pueden usar criterios distintos. La comparación de cantidad solo cuenta vínculos de paquetes registrados en los eventos IPS.</p>
                </section>
                @if($bag->COMMENTS)<section class="postal-panel"><h2><i class="fas fa-comment-alt"></i> Observación guardada</h2><p>{{ $bag->COMMENTS }}</p></section>@endif
                <div class="postal-actions postal-bag-actions"><a class="btn btn-outline-primary" target="_blank" rel="noopener" href="{{ route('postal.receptacles.document',['type'=>'etiqueta','marbete'=>$identifier]) }}"><i class="fas fa-print"></i> Imprimir etiqueta interna</a><a class="btn btn-outline-primary" href="{{ route('postal.receptacles.document',['type'=>'csv','marbete'=>$identifier]) }}"><i class="fas fa-file-csv"></i> Descargar paquetes CSV</a></div>
                <nav class="postal-tabs" aria-label="Secciones del resultado"><a href="#bag-events"><i class="fas fa-history"></i> Eventos ({{ count($result['events']) }})</a><a href="#bag-dispatch"><i class="fas fa-plane-departure"></i> Despacho</a><a href="#bag-items"><i class="fas fa-boxes"></i> Paquetes ({{ count($result['items']) }})</a><a href="#bag-forms"><i class="fas fa-file-alt"></i> Formularios</a></nav>
                @if($result['truncated'])<div class="alert alert-warning">La saca tiene más información que el límite visible. Se muestran los registros disponibles hasta ese límite.</div>@endif
                <section id="bag-events" class="postal-panel"><div class="postal-section-heading"><h2><i class="fas fa-history"></i> Historial de la saca</h2><span>Hora guardada en IPS</span></div>
                    <div class="postal-timeline">@forelse($result['events'] as $event)<article class="postal-event"><div class="postal-event-date">{{ $event->EVENT_GMT_DT ?? 'Fecha no registrada' }}<small>Hora registrada por IPS</small></div><div><span class="postal-source">Evento IPS · {{ $event->EVENT_TYPE_CD }}</span><h3>{{ $event->EVENT_NAME_ES ?: $event->EVENT_NAME ?: 'Evento sin descripción en catálogo' }}</h3><p><i class="fas fa-map-marker-alt"></i> {{ $event->OFFICE_NM ?: $event->OFFICE_FCD ?: 'Oficina no registrada' }}</p><p><i class="fas fa-user"></i> {{ $event->USER_NM ?: $event->USER_FID ?: 'IPS no registró al usuario' }}</p>@if($event->NEXT_OFFICE_CD)<p><i class="fas fa-arrow-right"></i> Siguiente oficina (código IPS): {{ $event->NEXT_OFFICE_CD }}</p>@endif</div></article>@empty<p class="postal-note">El marbete existe, pero IPS aún no tiene eventos de recorrido asociados.</p>@endforelse</div>
                </section>
                <section id="bag-dispatch" class="postal-panel"><div class="postal-section-heading"><h2><i class="fas fa-plane-departure"></i> Despacho que contiene la saca</h2><span>Datos de IPS</span></div>
                    @if($bag->DESPTCH_PID)<div class="postal-facts"><div><span>Número de despacho</span><strong>{{ $bag->DESPTCH_FID ?: 'No informado' }}</strong></div><div><span>Desde oficina</span><strong>{{ $bag->ORIG_OFFICE_FCD ?: 'No informada' }}</strong></div><div><span>Hacia oficina</span><strong>{{ $bag->DEST_OFFICE_FCD ?: 'No informada' }}</strong></div><div><span>Fecha de salida</span><strong>{{ $bag->DESPTCH_DEPARTURE_DT ?: 'No registrada' }}</strong></div><div><span>Sacas declaradas en el despacho</span><strong>{{ $bag->DESPTCH_RECPTCLS_NO ?? 'No informadas' }}</strong></div><div><span>Peso del despacho</span><strong>{{ $bag->DESPTCH_WEIGHT !== null ? number_format((float)$bag->DESPTCH_WEIGHT,3,',','.').' kg' : 'No informado' }}</strong></div></div>@else<div class="postal-empty postal-empty-compact"><i class="fas fa-inbox"></i><h3>Esta saca todavía no está vinculada a un despacho</h3><p>IPS no tiene un DESPTCH_PID asociado a este receptáculo.</p></div>@endif
                </section>
                <section id="bag-items" class="postal-panel"><div class="postal-section-heading"><h2><i class="fas fa-boxes"></i> Paquetes enlazados a esta saca</h2><span>{{ count($result['items']) }} en eventos IPS</span></div><p class="postal-note">La relación se obtiene de los eventos IPS que guardan el identificador de esta saca. Un vínculo histórico no confirma por sí solo que el paquete siga físicamente dentro de ella. Abre un paquete para revisar su recorrido.</p>
                    <div class="table-responsive"><table class="table"><thead><tr><th>Código del envío</th><th>Clase</th><th>Origen</th><th>Destino</th><th>Último movimiento registrado</th><th>Fecha</th><th></th></tr></thead><tbody>
                    @forelse($result['items'] as $item)<tr><td><strong>{{ $item->MAILITM_FID ?: 'Identificador local' }}</strong>@if($item->MAILITM_LOCAL_ID)<small class="d-block text-muted">Local: {{ $item->MAILITM_LOCAL_ID }}</small>@endif</td><td>{{ $item->MAIL_CLASS_NAME ?: $item->MAIL_CLASS_CD ?: '—' }}</td><td>{{ $item->ORIGIN_COUNTRY ?: '—' }}</td><td>{{ $item->DEST_COUNTRY ?: '—' }}</td><td>{{ $item->EVENT_NAME_ES ?: $item->EVENT_NAME ?: 'Movimiento no descrito' }} · {{ $item->POSTAL_STATUS_NAME ?: 'Estado no descrito' }}</td><td>{{ $item->EVT_GMT_DT ?: '—' }}</td><td>@if($item->MAILITM_FID)<a class="btn btn-sm btn-outline-primary" href="{{ route('postal.combined',['codigo'=>$item->MAILITM_FID]) }}"><i class="fas fa-search"></i> Abrir paquete</a>@endif</td></tr>@empty<tr><td colspan="7">No hay paquetes vinculados en los eventos IPS de esta saca.</td></tr>@endforelse
                    </tbody></table></div>
                </section>
                <section id="bag-forms" class="postal-panel"><div class="postal-section-heading"><h2><i class="fas fa-file-alt"></i> Manifiestos y formularios asociados</h2><span>Relacionados por los paquetes de la saca</span></div><div class="table-responsive"><table class="table"><thead><tr><th>Número de manifiesto</th><th>Formulario registrado</th><th>Fecha local</th><th>Persona que lo registró</th><th>Observación</th></tr></thead><tbody>@forelse($result['manifests'] as $manifest)<tr><td>{{ $manifest->MANIFEST_LIST_ID }}</td><td>{{ $manifest->FORM_NM ?: $manifest->MANIF_TYPE_ID ?: 'Tipo no informado' }}</td><td>{{ $manifest->CREATION_LCL_DT ?: '—' }}</td><td>{{ $manifest->USER_NM ?: $manifest->USER_FID ?: 'IPS no informó al usuario' }}</td><td>{{ $manifest->OBSERVATION ?: 'Sin observaciones' }}</td></tr>@empty<tr><td colspan="5">No se encontraron manifiestos vinculados a los paquetes de esta saca.</td></tr>@endforelse</tbody></table></div></section>
            @else
                <section class="postal-panel"><h2>Hay varios registros que coinciden</h2><p>El número buscado aparece en varios receptáculos. Comprueba el tipo de identificador y vuelve a buscar el código completo.</p><div class="table-responsive"><table class="table"><thead><tr><th>Marbete</th><th>Número de registro</th><th>Seguro</th><th>Peso</th><th>Fecha</th></tr></thead><tbody>@foreach($result['receptacles'] as $bag)<tr><td>{{ $bag->RECPTCL_FID ?: '—' }}</td><td>{{ $bag->RECPTCL_REG_NO ?: '—' }}</td><td>{{ $bag->RECPTCL_INS_NO ?: '—' }}</td><td>{{ $bag->RECPTCL_WEIGHT ?? '—' }}</td><td>{{ $bag->EVT_GMT_DT ?? '—' }}</td></tr>@endforeach</tbody></table></div></section>
            @endif
        @else
            <div class="postal-empty"><i class="fas fa-search" aria-hidden="true"></i><h2>No encontramos ese marbete en IPS</h2><p>Verifica si copiaste el número completo. La búsqueda prueba marbete, registro y seguro, con el identificador guardado en IPS.</p><code>{{ $identifier }}</code></div>
        @endif
    @endif
    @else
        @include('postal.partials.receptacle-register')
    @endif
</div>
@include('footer')
@stop
@section('css')<link rel="stylesheet" href="{{ asset('css/postal-operations.css') }}"><link rel="stylesheet" href="{{ asset('css/postal-dispatches.css') }}">@stop
@section('js')
@if(!$detailMode)
<script>
    (function ($) {
        var $modal = $('#receptacle-packages-modal');
        var pendingRequest = null;
        var requestVersion = 0;
        $modal.on('show.bs.modal', function (event) {
            var trigger = event.relatedTarget;
            if (!trigger) return;

            var url = trigger.getAttribute('data-url');
            var code = trigger.getAttribute('data-code');
            var currentVersion = ++requestVersion;
            if (pendingRequest) pendingRequest.abort();
            $('#receptacle-packages-modal-title').text('Paquetes de la saca ' + code);
            $('#receptacle-packages-modal-body').html('<div class="text-center py-4"><i class="fas fa-spinner fa-spin"></i> Consultando paquetes...</div>');

            pendingRequest = $.get(url).done(function (html) {
                if (currentVersion === requestVersion) $('#receptacle-packages-modal-body').html(html);
            }).fail(function (_, status) {
                if (status === 'abort' || currentVersion !== requestVersion) return;
                $('#receptacle-packages-modal-body').html('<div class="alert alert-danger mb-0" role="alert">No se pudieron consultar los paquetes. Cierra el modal e inténtalo de nuevo.</div>');
            });
        }).on('hidden.bs.modal', function () {
            requestVersion++;
            if (pendingRequest) pendingRequest.abort();
            pendingRequest = null;
            $('#receptacle-packages-modal-body').html('<p class="postal-note mb-0">Selecciona una saca para consultar sus paquetes.</p>');
        });
    })(jQuery);
</script>
@endif
@stop
