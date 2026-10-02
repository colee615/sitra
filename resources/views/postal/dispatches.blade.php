@extends('adminlte::page')
@section('title','Despachos IPS | SITRA')
@section('content_header')
<div class="postal-heading">
    <div>
        <span class="postal-eyebrow">IPS · LOGÍSTICA POSTAL</span>
        <h1>Registro de despachos</h1>
        <p>Consulta los despachos IPS, revisa cuántas sacas están vinculadas y abre el detalle S8/S9.</p>
    </div>
</div>
@stop
@section('content')
<div class="postal-workspace">
    @include('postal.partials.logistics-flow', ['active'=>'dispatch','dispatchCode'=>null])
    <p class="postal-logistics-explainer"><strong>Cómo leer esta lista:</strong> un despacho S8 puede agrupar varias sacas S9. Los conteos distinguen las cantidades anotadas en IPS de los vínculos encontrados en sus registros de paquetes.</p>

    <form class="postal-search postal-dispatch-filters" action="{{ route('postal.dispatches') }}" method="get">
        <div class="postal-dispatch-filter-grid">
            <div class="postal-dispatch-filter-wide">
                <label for="dispatch-search">Buscar despacho, saca, oficina o registro</label>
                <div class="postal-dispatch-input"><i class="fas fa-search" aria-hidden="true"></i><input id="dispatch-search" name="buscar" value="{{ $filters['buscar'] }}" maxlength="80" placeholder="Código S8 o S9, oficina, marbete, registro o seguro"></div>
                <small>Los códigos UPU S8 y S9 se reconocen automáticamente.</small>
            </div>
            <div><label for="dispatch-from">Salida desde</label><input id="dispatch-from" type="date" name="desde" value="{{ $filters['desde'] }}"></div>
            <div><label for="dispatch-to">Salida hasta</label><input id="dispatch-to" type="date" name="hasta" value="{{ $filters['hasta'] }}"></div>
            <div><label for="dispatch-page-size">Filas por página</label><select id="dispatch-page-size" name="por_pagina"><option value="25" @selected($filters['por_pagina'] === 25)>25</option><option value="50" @selected($filters['por_pagina'] === 50)>50</option><option value="100" @selected($filters['por_pagina'] === 100)>100</option></select></div>
        </div>
        <div class="postal-dispatch-filter-actions">
            <span>Ordenados por fecha de salida, del más reciente al más antiguo.</span>
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
                        <thead><tr><th>Salida</th><th>Despacho S8</th><th>Oficina origen → destino</th><th>Sacas vinculadas / registradas</th><th>Paquetes enlazados / declarados</th><th>Peso</th><th>Transporte</th><th></th></tr></thead>
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
                <td class="postal-dispatch-count"><strong>{{ $linked }}</strong> vinculadas en IPS<small>{{ $recorded === null ? 'Cantidad anotada en despacho: —' : 'Cantidad anotada en despacho: '.$recorded }}</small>
                                    @if($recorded === null)<span class="postal-dispatch-status is-neutral">Sin dato de comparación</span>@elseif($recorded === $linked)<span class="postal-dispatch-status is-ok">Coincide</span>@else<span class="postal-dispatch-status is-warning">Revisar diferencia</span>@endif
                                </td>
                <td class="postal-dispatch-count"><strong>{{ $linkedPackages }}</strong> asociados en eventos<small>{{ $declaredPackages === null ? 'Cantidad anotada en sacas: —' : 'Cantidad anotada en sacas: '.$declaredPackages }}</small>
                                    @if($declaredPackages === null)<span class="postal-dispatch-status is-neutral">Sin dato de comparación</span>@elseif($declaredPackages === $linkedPackages)<span class="postal-dispatch-status is-ok">Coincide</span>@else<span class="postal-dispatch-status is-warning">Revisar diferencia</span>@endif
                                </td>
                                <td>{{ is_numeric($dispatch->DESPTCH_WEIGHT ?? null) ? number_format((float)$dispatch->DESPTCH_WEIGHT,3,',','.').' kg' : '—' }}</td>
                                <td>{{ $dispatch->CONVEYANCE_TYPE_CD ?: 'No informado' }}</td>
                                <td><a class="btn btn-sm btn-outline-primary" href="{{ route('postal.receptacles',['marbete'=>$dispatch->DESPTCH_FID]) }}"><i class="fas fa-folder-open"></i> Ver sacas</a></td>
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
@include('footer')
@stop
@section('css')
<link rel="stylesheet" href="{{ asset('css/postal-operations.css') }}">
<link rel="stylesheet" href="{{ asset('css/postal-dispatches.css') }}">
@stop
