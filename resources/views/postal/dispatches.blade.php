@extends('adminlte::page')
@section('title','Despachos IPS | SITRA')
@section('content')
<div class="postal-workspace">
    <form class="postal-search postal-dispatch-filters" action="{{ route('postal.dispatches') }}" method="get">
        <div class="postal-dispatch-filter-grid">
            <div class="postal-dispatch-filter-wide">
                <label for="dispatch-search">Buscar despacho, saca, oficina o registro</label>
                <div class="postal-dispatch-input"><i class="fas fa-search" aria-hidden="true"></i><input id="dispatch-search" name="buscar" value="{{ $filters['buscar'] }}" maxlength="80" placeholder="Código S8 o S9, oficina, marbete, registro o seguro"></div>
            </div>
            <div><label for="dispatch-from">Salida desde</label><input id="dispatch-from" type="date" name="desde" value="{{ $filters['desde'] }}"></div>
            <div><label for="dispatch-to">Salida hasta</label><input id="dispatch-to" type="date" name="hasta" value="{{ $filters['hasta'] }}"></div>
            <div><label for="dispatch-page-size">Filas por página</label><select id="dispatch-page-size" name="por_pagina"><option value="25" @selected($filters['por_pagina'] === 25)>25</option><option value="50" @selected($filters['por_pagina'] === 50)>50</option><option value="100" @selected($filters['por_pagina'] === 100)>100</option></select></div>
        </div>
        <div class="postal-dispatch-filter-actions">
            <div><a class="btn btn-light" href="{{ route('postal.dispatches') }}"><i class="fas fa-undo"></i> Limpiar</a><button class="btn btn-primary"><i class="fas fa-filter"></i> Buscar despachos</button></div>
        </div>
    </form>

    @if($error)
        <div class="alert alert-danger" role="alert"><i class="fas fa-exclamation-circle"></i> {{ $error }}</div>
    @elseif($dispatches)
        <section class="postal-dispatch-summary postal-panel">
            <div><span class="postal-task-icon"><i class="fas fa-plane-departure"></i></span><strong>{{ number_format($dispatches->total()) }}</strong><span>despachos encontrados</span></div>
            <div><i class="fas fa-sort-amount-down"></i><span>Más recientes primero</span></div>
        </section>

        <section class="postal-panel postal-dispatch-results">
            <div class="postal-section-heading">
                <h2><i class="fas fa-list"></i> Despachos IPS</h2>
                <span>Mostrando {{ $dispatches->firstItem() ?? 0 }}–{{ $dispatches->lastItem() ?? 0 }} de {{ number_format($dispatches->total()) }}</span>
            </div>
            @if($dispatches->isEmpty())
                <div class="postal-empty postal-empty-compact"><i class="fas fa-search"></i><h2>No encontramos despachos</h2><p>Prueba con otro código UPU, oficina o rango de fechas.</p></div>
            @else
                <div class="table-responsive">
                    <table class="table postal-dispatch-table">
                        <thead><tr><th>Salida</th><th>Despacho S8</th><th>Oficina origen → destino</th><th>Sacas vinculadas en IPS / indicadas en despacho</th><th>Paquetes con evento IPS / indicados en sacas</th><th>Peso</th><th>Transporte</th><th></th></tr></thead>
                        <tbody>
                        @foreach($dispatches as $dispatch)
                            @php($linked = (int) ($dispatch->LINKED_RECPTCLS_NO ?? 0))
                            @php($recorded = is_numeric($dispatch->DESPTCH_RECPTCLS_NO ?? null) ? (int) $dispatch->DESPTCH_RECPTCLS_NO : null)
                            @php($linkedPackages = (int) ($dispatch->LINKED_MAILITMS_NO ?? 0))
                            @php($declaredPackages = is_numeric($dispatch->DECLARED_MAILITMS_NO ?? null) ? (int) $dispatch->DECLARED_MAILITMS_NO : null)
                            @php($analysis = \App\Services\Postal\UpuDispatchIdentifier::parse((string) ($dispatch->DESPTCH_FID ?? '')))
                            <tr>
                                <td>{{ $dispatch->DESPTCH_DEPARTURE_DT ?: 'Sin fecha' }}</td>
                                <td><strong class="postal-dispatch-code">{{ $dispatch->DESPTCH_FID ?: 'Sin código' }}</strong>@if($analysis)<small class="d-block text-muted">{{ $analysis['origin_ctci'] }} → {{ $analysis['destination_ctci'] }} · {{ $analysis['mail_class'] }}{{ $analysis['mail_subclass'] }}</small>@endif</td>
                                <td><strong>{{ $dispatch->ORIG_OFFICE_FCD ?: 'No informada' }}</strong><span class="postal-route-arrow">→</span><strong>{{ $dispatch->DEST_OFFICE_FCD ?: 'No informada' }}</strong></td>
                <td class="postal-dispatch-count"><strong>{{ $linked }}</strong> encontradas<small>{{ $recorded === null ? 'El despacho no indica cantidad' : 'El despacho indica: '.$recorded.' sacas' }}</small>
                                    @if($recorded === null)<span class="postal-dispatch-status is-neutral">Sin dato de comparación</span>@elseif($recorded === $linked)<span class="postal-dispatch-status is-ok">Coincide</span>@else<span class="postal-dispatch-status is-warning">Revisar diferencia</span>@endif
                                </td>
                <td class="postal-dispatch-count"><strong>{{ $linkedPackages }}</strong> paquetes en eventos IPS<small>{{ $declaredPackages === null ? 'Las sacas no indican cantidad' : 'Las sacas indican: '.$declaredPackages.' paquetes' }}</small>
                                    @if($declaredPackages === null)<span class="postal-dispatch-status is-neutral">Sin dato de comparación</span>@elseif($declaredPackages === $linkedPackages)<span class="postal-dispatch-status is-ok">Coincide</span>@else<span class="postal-dispatch-status is-warning">Revisar diferencia</span>@endif
                                </td>
                                <td>{{ is_numeric($dispatch->DESPTCH_WEIGHT ?? null) ? number_format((float)$dispatch->DESPTCH_WEIGHT,3,',','.').' kg' : '—' }}</td>
                                <td>{{ $dispatch->CONVEYANCE_TYPE_CD ?: 'No informado' }}</td>
                                <td><div class="postal-dispatch-row-actions"><a class="btn btn-sm btn-outline-secondary" href="{{ route('postal.receptacles', ['marbete' => $dispatch->DESPTCH_FID]) }}"><i class="fas fa-eye"></i> Ver despacho</a><button type="button" class="btn btn-sm btn-outline-primary" data-toggle="modal" data-target="#dispatch-sacks-modal" data-url="{{ route('postal.dispatches.sacks', ['codigo' => $dispatch->DESPTCH_FID]) }}" data-dispatch="{{ $dispatch->DESPTCH_FID }}"><i class="fas fa-folder-open"></i> Ver sacas</button></div></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="postal-dispatch-pagination">{{ $dispatches->links() }}</div>
            @endif
        </section>
    @endif
</div>
<div class="modal fade" id="dispatch-sacks-modal" tabindex="-1" role="dialog" aria-labelledby="dispatch-sacks-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="dispatch-sacks-modal-title">Sacas del despacho</h2>
                <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body" id="dispatch-sacks-modal-body"><p class="postal-note mb-0">Selecciona un despacho para consultar sus sacas.</p></div>
        </div>
    </div>
</div>
@include('footer')
@stop
@section('css')
<link rel="stylesheet" href="{{ asset('css/postal-operations.css') }}">
<link rel="stylesheet" href="{{ asset('css/postal-dispatches.css') }}">
@stop
@section('js')
<script>
    (function ($) {
        var $modal = $('#dispatch-sacks-modal');
        $modal.on('show.bs.modal', function (event) {
            var trigger = event.relatedTarget;
            var url = trigger.getAttribute('data-url');
            var code = trigger.getAttribute('data-dispatch');
            $('#dispatch-sacks-modal-title').text('Sacas del despacho ' + code);
            $('#dispatch-sacks-modal-body').html('<div class="text-center py-4"><i class="fas fa-spinner fa-spin"></i> Consultando sacas...</div>');

            $.get(url).done(function (html) {
                $('#dispatch-sacks-modal-body').html(html);
            }).fail(function () {
                $('#dispatch-sacks-modal-body').html('<div class="alert alert-danger mb-0" role="alert">No se pudieron consultar las sacas. Cierra el modal e inténtalo de nuevo.</div>');
            });
        });
    })(jQuery);
</script>
@stop
