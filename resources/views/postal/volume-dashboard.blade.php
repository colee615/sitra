@extends('adminlte::page')
@section('title', 'Volúmenes postales · '.strtoupper($scope).' | SITRA')
@section('css')
    <link rel="stylesheet" href="{{ asset('css/operational-dashboard.css') }}?v=3">
    <link rel="stylesheet" href="{{ asset('css/volume-dashboard.css') }}?v=7">
@stop
@section('content')
<main class="volume-dashboard {{ $scope }}-volume-dashboard" id="volume-dashboard" data-scope="{{ $scope }}" data-url="{{ route('dashboard.data') }}" data-today="{{ now('America/La_Paz')->toDateString() }}" data-detail-url="{{ $scope === 'ips' ? route('postal.operations') : ($scope === 'cds' ? route('postal.cds') : route('postal.combined')) }}">
    <form id="volume-filters">
        <header class="volume-report-header">
            <div class="volume-brand">
                <img src="{{ asset('images/correos-bolivia.png') }}" alt="Correos de Bolivia">
                <div>
                    <span>INTELIGENCIA POSTAL · TABLERO EJECUTIVO</span>
                    <h1>{{ $scope === 'all' ? 'Volumen postal' : 'Volumen de envíos' }} <b>{{ $scope === 'all' ? 'IPS + CDS' : strtoupper($scope) }}</b></h1>
                    <p>Operación postal y gestión aduanera, en una sola vista.</p>
                </div>
            </div>
            <div class="volume-header-controls">
                <label><span id="report-from-label">Desde</span><input id="report-from" type="date" value="{{ now('America/La_Paz')->toDateString() }}" required></label>
                <label><span id="report-to-label">Hasta</span><input id="report-to" type="date" value="{{ now('America/La_Paz')->toDateString() }}" required></label>
                <label class="volume-operator"><span>Operador</span><select id="report-operator" name="operator" aria-label="Operador"><option value="BOA" selected>BOA - BOLIVIA POST</option></select></label>
                @if($scope==='cds')
                    <label class="volume-office"><span>Oficina</span><select name="office" data-catalog="offices" aria-label="Oficina"><option value="" selected>Todas las oficinas</option></select></label>
                @else
                    @can('postal.ips')<label class="volume-office"><span>{{ $scope === 'all' ? 'Oficina IPS' : 'Oficina' }}</span><select name="office" data-catalog="offices" aria-label="Oficina"><option value="" selected>Todas las oficinas</option></select></label>@endcan
                @endif
                @if($scope==='all')@can('postal.cds')<label class="volume-office"><span>Oficina CDS</span><select name="cds_office" data-catalog="cds-offices" aria-label="Oficina CDS"><option value="" selected>Todas las oficinas CDS</option></select></label>@endcan @endif
            </div>
        </header>
        <div class="volume-mode-row">
            <div class="volume-mode-tabs" role="tablist" aria-label="Periodo del reporte">
                <button type="button" id="mode-daily" data-mode="daily" role="tab" aria-selected="true">Diario</button>
                <button type="button" id="mode-monthly" data-mode="monthly" role="tab" aria-selected="false">Mensual</button>
            </div>
            <div class="volume-mode-context"><span>VISTA EJECUTIVA</span><small>Filtros dinámicos · métricas separadas por fuente</small></div>
            <div class="volume-toolbar-actions">
                <details class="volume-extra-filters"><summary><i class="fas fa-sliders-h"></i> Más filtros</summary><div class="volume-filter-options">
                    <label>Clase / servicio<select name="service" data-catalog="services"><option value="">Todos los servicios</option></select></label>
                    <button class="btn btn-primary" type="submit"><i class="fas fa-filter mr-1"></i> Aplicar</button>
                </div></details>
                <a href="{{ $scope === 'ips' ? route('postal.operations') : ($scope === 'cds' ? route('postal.cds') : route('postal.combined')) }}" class="volume-detail-link"><i class="fas fa-list-alt"></i> {{ $scope === 'all' ? 'Ver detalle' : 'Reporte detallado' }}</a>
                <button class="btn btn-outline-secondary btn-sm" id="volume-export" type="button" disabled><i class="fas fa-download mr-1"></i> Exportar</button>
            </div>
        </div>
    </form>
    <div class="volume-status-row">
        <div class="volume-status-indicator"><i aria-hidden="true"></i><span id="volume-status" role="status" aria-live="polite">Consultando las fuentes postales…</span></div>
        <div class="volume-source-badges">
            @if($scope!=='cds')<span class="volume-source-badge volume-source-badge-ips"><i class="fas fa-globe-americas"></i> IPS · IPS5Db</span>@endif
            @if($scope!=='ips')<span class="volume-source-badge volume-source-badge-cds"><i class="fas fa-file-invoice"></i> CDS · CDSDb</span>@endif
        </div>
        <span id="volume-period-label"></span>
        <button type="button" id="volume-refresh" class="dashboard-text-button"><i class="fas fa-sync-alt mr-1"></i> Actualizar</button>
    </div>
    <details class="volume-methodology">
        <summary><i class="fas fa-info-circle"></i> Fuentes y definiciones</summary>
        <div>
            @if($scope==='ips')
                Los envíos únicos IPS se determinan por los países asociados a BOA. Recibidos y despachados pueden solaparse. El tipo de producto vacío no equivale a “Items no IMTATT” del reporte QCS.
            @elseif($scope==='all')
                IPS y CDS se consultan por separado y sus totales no se suman. Las oficinas se filtran por su fuente. “Sin ITMATT” queda como N/D porque las bases conectadas no exponen un indicador verificable equivalente al de QCS.
            @else
                CDS cuenta objetos con fecha postal en el periodo y sus declaraciones y respuestas aduaneras. No equivale al volumen general de correo del reporte QCS/IPS.
            @endif
        </div>
    </details>
    <div id="volume-active-filters" class="volume-active-filters" hidden><span id="volume-active-filter-label"></span><button id="volume-clear-chart-filters" class="dashboard-text-button" type="button">Limpiar selecciones</button></div>
    <div id="volume-error" class="alert alert-warning" hidden></div>
    <div id="volume-loading" class="volume-loading" role="status" aria-live="polite">
        <div class="volume-loading-message"><span class="volume-loader-ring" aria-hidden="true"></span><div><strong>Consultando las fuentes postales</strong><span>Estamos preparando las tarjetas y gráficas para el periodo seleccionado.</span></div></div>
        <div class="volume-loading-kpis" aria-hidden="true">@for($i=0;$i<6;$i++)<div class="volume-loading-kpi"><i></i><b></b><span></span></div>@endfor</div>
        <div class="volume-loading-charts" aria-hidden="true"><div><i></i><i></i><i></i></div><div><i></i><i></i><i></i></div></div>
    </div>
    <section id="volume-results" aria-busy="true">
        <div id="volume-primary-content">
        @if($scope!=='cds')
            <div class="volume-section-heading">
                <div><span class="volume-section-eyebrow"><i class="fas fa-globe-americas"></i> FUENTE POSTAL · IPS5Db</span><h2>Movimiento postal</h2><p>Volumen, flujo y destinos del operador BOA.</p></div>
                <span class="volume-section-tag">IPS · Postal</span>
            </div>
        @endif
        <div class="volume-kpis">
            @if($scope!=='cds')
                @foreach([
                    ['packages','Total de envíos','fa-box','Envíos únicos con cualquier evento IPS, incluida aduana'],
                    ['dispatched','Envíos despachados','fa-sign-out-alt','Origen asociado a BOA en el periodo'],
                    ['received','Envíos recibidos','fa-sign-in-alt','Destino asociado a BOA en el periodo'],
                    ['returns','En devolución','fa-undo','Estado postal actual 6/7'],
                    ['transit','En tránsito','fa-shipping-fast','Último movimiento del periodo'],
                    $scope === 'all' ? ['no_imtatt','Items sin ITMATT','fa-ban','Indicador ITMATT no verificable en las fuentes conectadas'] : ['no_product','Producto sin registrar','fa-question-circle','Sin tipo de producto IPS'],
                ] as [$key,$label,$icon,$hint])
                    <article class="volume-kpi"><div><span>{{ $label }}</span><i class="fas {{ $icon }}"></i></div><strong data-kpi="{{ $key }}">—</strong><small data-change="{{ $key }}">Comparando periodo anterior</small><em>{{ $hint }}</em></article>
                @endforeach
            @else
                @foreach([
                    ['objects','Objetos postales','fa-box','Objetos con fecha postal en el periodo'],
                    ['declaredObjects','Con declaración','fa-file-alt','Objetos con una o más declaraciones'],
                    ['declarations','Declaraciones','fa-file-signature','Registros de declaración en CDS'],
                    ['respondedObjects','Con respuesta','fa-reply','Objetos con una o más respuestas'],
                    ['responses','Respuestas','fa-clipboard-check','Registros de respuesta en CDS'],
                    ['withoutResponse','Sin respuesta','fa-hourglass-half','Objetos sin respuesta asociada'],
                ] as [$key,$label,$icon,$hint])
                    <article class="volume-kpi"><div><span>{{ $label }}</span><i class="fas {{ $icon }}"></i></div><strong data-kpi="{{ $key }}">—</strong><small data-change="{{ $key }}">Comparando periodo anterior</small><em>{{ $hint }}</em></article>
                @endforeach
            @endif
        </div>
        @if($scope!=='cds')
            <div class="volume-analysis-note"><i class="fas fa-info-circle"></i><span>Recibidos y despachados pueden solaparse; no se suman para calcular el total de envíos únicos.</span></div>
        @endif
        <div class="volume-chart-row">
            <article class="volume-panel volume-trend-panel"><header><div><h2 id="trend-title">Volumen de envíos</h2><p id="trend-subtitle">Movimientos agrupados por hora · hora de Bolivia</p></div><span id="trend-caption">Volumen</span></header><div id="volume-trend" class="volume-chart"></div><div id="volume-trend-legend" class="volume-legend"></div></article>
            <article class="volume-panel"><header><div><h2 id="distribution-title">Volumen por clase postal</h2><p>Composición del movimiento en el periodo</p></div></header><div id="volume-distribution" class="volume-chart"></div><div id="distribution-legend" class="volume-legend"></div></article>
        </div>
        <div class="volume-breakdown-row">
            <article class="volume-panel"><header><h2 id="destination-title">Top 10 países de destino</h2></header><div id="volume-destinations" class="volume-ranking"></div></article>
            <article class="volume-panel"><header><h2 id="origin-title">Top 10 países de origen</h2></header><div id="volume-origins" class="volume-ranking"></div></article>
            <article class="volume-panel volume-donut-panel"><header><h2 id="category-title">Volumen por clase</h2></header><div id="volume-categories" class="volume-donut"></div></article>
            <article class="volume-panel volume-donut-panel"><header><h2 id="product-title">Volumen por producto</h2></header><div id="volume-products" class="volume-donut"></div></article>
        </div>
        <article class="volume-panel volume-detail-table"><header><div><h2 id="volume-table-title">Detalle del periodo</h2><p id="volume-table-caption">Registros exactos que alimentan las gráficas.</p></div></header><div class="table-responsive"><table class="table"><thead id="volume-table-head"></thead><tbody id="volume-table-body"></tbody></table></div></article>
        </div>
        @if($scope==='all')
        <section class="volume-source-section" aria-labelledby="cds-volume-heading">
            <header class="volume-source-heading">
                <div><span><i class="fas fa-file-invoice"></i> FUENTE ADUANERA · CDSDb</span><h2 id="cds-volume-heading">Declaraciones y respuestas</h2><p>Indicadores de objetos y gestión aduanera; se mantienen separados de IPS.</p></div>
                @can('postal.cds')<a href="{{ route('postal.cds') }}" class="btn btn-outline-secondary btn-sm">Ver detalle <i class="fas fa-arrow-right ml-1"></i></a>@endcan
            </header>
            <div class="volume-kpis volume-cds-kpis">
                @foreach([['objects','Objetos postales','box'],['declarations','Declaraciones','file-alt'],['responses','Respuestas','reply'],['withoutResponse','Sin respuesta','hourglass-half']] as [$key,$label,$icon])<article class="volume-kpi"><div><span>{{ $label }}</span><i class="fas fa-{{ $icon }}"></i></div><strong data-cds-kpi="{{ $key }}">—</strong><small data-cds-change="{{ $key }}">CDS</small><em>Fuente CDSDb</em></article>@endforeach
            </div>
            <div class="volume-cds-chart-row">
                <article class="volume-panel"><header><div><h2>Evolución CDS</h2><p>Objetos, declaraciones y respuestas por fecha postal</p></div></header><div id="cds-volume-trend" class="volume-chart"></div><div id="cds-volume-trend-legend" class="volume-legend"></div></article>
                <article class="volume-panel"><header><div><h2>Estados de declaración</h2><p>Registros CDS en el periodo</p></div></header><div id="cds-volume-states" class="volume-ranking"></div></article>
                <article class="volume-panel"><header><div><h2>Operadores de origen</h2><p>Organización registrada en CDS</p></div></header><div id="cds-volume-origins" class="volume-ranking"></div></article>
                <article class="volume-panel"><header><div><h2>Operadores de destino</h2><p>Organización registrada en CDS</p></div></header><div id="cds-volume-destinations" class="volume-ranking"></div></article>
            </div>
        </section>
        @endif
    </section>
    <div id="volume-generated" class="volume-generated"></div>
    <noscript><div class="alert alert-info">Activa JavaScript para consultar el reporte de volúmenes.</div></noscript>
</main>
@include('footer')
@stop
@section('js')<script src="{{ asset('js/volume-dashboard.js') }}?v=11" defer></script>@stop
