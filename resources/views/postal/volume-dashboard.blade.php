@extends('adminlte::page')
@section('title', ($scope === 'all' ? 'Volumen postal IPS + CDS' : ($scope === 'ips' ? 'Volumen postal IPS' : 'Actividad aduanera CDS')).' | SITRA')
@section('css')
    <link rel="stylesheet" href="{{ asset('css/operational-dashboard.css') }}?v=3">
    <link rel="stylesheet" href="{{ asset('css/volume-dashboard.css') }}?v=10">
@stop
@section('content')
<main class="volume-dashboard {{ $scope }}-volume-dashboard" id="volume-dashboard" data-scope="{{ $scope }}" data-url="{{ route('dashboard.data') }}" data-today="{{ now('America/La_Paz')->toDateString() }}" data-detail-url="{{ $scope === 'ips' ? route('postal.operations') : ($scope === 'cds' ? route('postal.cds') : route('postal.combined')) }}">
    <form id="volume-filters">
        <header class="volume-report-header">
            <div class="volume-brand">
                <img src="{{ asset('images/correos-bolivia.png') }}" alt="Correos de Bolivia">
                <div>
                    <span>{{ $scope === 'all' ? 'INTELIGENCIA POSTAL · IPS + CDS' : ($scope === 'ips' ? 'INTELIGENCIA POSTAL · IPS' : 'GESTIÓN ADUANERA · CDS') }}</span>
                    <h1>{{ $scope === 'all' ? 'Volumen postal' : ($scope === 'ips' ? 'Volumen de envíos' : 'Actividad aduanera') }} <b>{{ $scope === 'all' ? 'IPS + CDS' : strtoupper($scope) }}</b></h1>
                    <p>{{ $scope === 'all' ? 'Operación postal y gestión aduanera, con resultados separados por fuente.' : ($scope === 'ips' ? 'Actividad postal, movimientos y servicios del operador seleccionado.' : 'Objetos postales, declaraciones y respuestas aduaneras.') }}</p>
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
        <div class="volume-status-indicator"><i aria-hidden="true"></i><span id="volume-status" role="status" aria-live="polite">{{ $scope === 'all' ? 'Consultando IPS y CDS…' : ($scope === 'ips' ? 'Consultando IPS…' : 'Consultando CDS…') }}</span></div>
        <div class="volume-source-badges">
            @if($scope!=='cds')<span class="volume-source-badge volume-source-badge-ips"><i class="fas fa-globe-americas"></i> IPS · Volumen postal</span>@endif
            @if($scope!=='ips')<span class="volume-source-badge volume-source-badge-cds"><i class="fas fa-file-invoice"></i> CDS · Gestión aduanera</span>@endif
        </div>
        <span id="volume-period-label"></span>
        <button type="button" id="volume-refresh" class="dashboard-text-button"><i class="fas fa-sync-alt mr-1"></i> Actualizar</button>
    </div>
    <details class="volume-methodology">
        <summary><i class="fas fa-info-circle"></i> Fuentes y definiciones</summary>
        <div>
            @if($scope==='ips')
                Los envíos únicos IPS se determinan por los países asociados a BOA. Recibidos y despachados pueden solaparse.
            @elseif($scope==='all')
                IPS y CDS se consultan por separado y sus totales no se suman. Las oficinas se filtran por su fuente.
            @else
                CDS cuenta objetos con fecha postal en el periodo y sus declaraciones y respuestas aduaneras. No equivale al volumen general de correo del reporte QCS/IPS.
            @endif
        </div>
    </details>
    <div id="volume-active-filters" class="volume-active-filters" hidden><span id="volume-active-filter-label"></span><button id="volume-clear-chart-filters" class="dashboard-text-button" type="button">Limpiar selecciones</button></div>
    <div id="volume-error" class="alert alert-warning" hidden></div>
    <div id="volume-loading" class="volume-loading" role="status" aria-live="polite">
        <div class="volume-loading-message"><span class="volume-loader-ring" aria-hidden="true"></span><div><strong>{{ $scope === 'all' ? 'Consultando IPS y CDS' : ($scope === 'ips' ? 'Consultando IPS' : 'Consultando CDS') }}</strong><span>Estamos preparando las tarjetas y gráficas para el periodo seleccionado.</span></div></div>
        <div class="volume-loading-sources" aria-hidden="true">
            @if($scope!=='cds')<div class="volume-loading-source volume-loading-source-ips"><span>IPS · OPERACIÓN POSTAL</span><div class="volume-loading-kpis">@for($i=0;$i<5;$i++)<div class="volume-loading-kpi"><i></i><b></b><span></span></div>@endfor</div></div>@endif
            @if($scope!=='ips')<div class="volume-loading-source"><span>CDS · GESTIÓN ADUANERA</span><div class="volume-loading-kpis">@for($i=0;$i<6;$i++)<div class="volume-loading-kpi"><i></i><b></b><span></span></div>@endfor</div></div>@endif
        </div>
        <div class="volume-loading-charts" aria-hidden="true"><div><i></i><i></i><i></i></div><div><i></i><i></i><i></i></div></div>
    </div>
    <section id="volume-results" aria-busy="true">
        <div id="volume-executive-summary" class="volume-executive-summary">
            @if($scope!=='cds')
            <section id="volume-primary-content" class="volume-summary-source volume-summary-ips">
                <header class="volume-summary-heading">
                    <div><span class="volume-section-eyebrow"><i class="fas fa-globe-americas"></i> IPS · OPERACIÓN POSTAL</span><h2>Operación postal</h2><p>Envíos únicos y movimientos asociados al operador BOA.</p></div>
                    <span class="volume-section-tag">Fuente postal</span>
                </header>
                <div class="volume-kpis volume-ips-kpis">
                    @foreach([
                        ['packages','Envíos únicos','fa-box','Identificadores postales únicos con actividad IPS en el periodo.'],
                        ['dispatched','Despachados','fa-sign-out-alt','Envíos con origen asociado a Bolivia (BO).'],
                        ['received','Recibidos','fa-sign-in-alt','Envíos con destino asociado a Bolivia (BO).'],
                        ['returns','En devolución','fa-undo','Objetos con estado postal 6 o 7 dentro del periodo.'],
                        ['transit','En tránsito','fa-shipping-fast','Último movimiento clasificado como tránsito.'],
                    ] as [$key,$label,$icon,$hint])
                        <article class="volume-kpi" title="{{ $hint }}"><div><span>{{ $label }}</span><i class="fas {{ $icon }}"></i></div><strong data-kpi="{{ $key }}">—</strong><small data-change="{{ $key }}">Comparando periodo anterior</small><em>{{ $hint }}</em></article>
                    @endforeach
                </div>
                <div class="volume-source-footnote"><i class="fas fa-info-circle"></i> Recibidos y despachados pueden solaparse; no se suman para calcular los envíos únicos.</div>
            </section>
            @endif

            @if($scope==='all')
            <section id="volume-cds-summary" class="volume-summary-source volume-summary-cds">
                <header class="volume-summary-heading">
                    <div><span class="volume-section-eyebrow"><i class="fas fa-file-invoice"></i> CDS · GESTIÓN ADUANERA</span><h2>Gestión aduanera</h2><p>Objetos, declaraciones y respuestas en el periodo.</p></div>
                    <span class="volume-section-tag volume-section-tag-cds">Fuente aduanera</span>
                </header>
                <div class="volume-kpis volume-cds-kpis">
                    @foreach([
                        ['objects','Objetos postales','box'],
                        ['declaredObjects','Con declaración','file-alt'],
                        ['declarations','Declaraciones','file-signature'],
                        ['respondedObjects','Con respuesta','reply'],
                        ['responses','Respuestas','clipboard-check'],
                        ['withoutResponse','Sin respuesta','hourglass-half'],
                    ] as [$key,$label,$icon])
                        <article class="volume-kpi"><div><span>{{ $label }}</span><i class="fas fa-{{ $icon }}"></i></div><strong data-cds-kpi="{{ $key }}">—</strong><small data-cds-change="{{ $key }}">Comparando periodo</small><em>Registros aduaneros incluidos en el periodo.</em></article>
                    @endforeach
                </div>
            </section>
            @endif

            @if($scope==='cds')
            <section id="volume-primary-content" class="volume-summary-source volume-summary-cds">
                <header class="volume-summary-heading">
                    <div><span class="volume-section-eyebrow"><i class="fas fa-file-invoice"></i> CDS · GESTIÓN ADUANERA</span><h2>Gestión aduanera</h2><p>Objetos, declaraciones y respuestas en el periodo.</p></div>
                    <span class="volume-section-tag volume-section-tag-cds">Fuente aduanera</span>
                </header>
                <div class="volume-kpis volume-cds-kpis">
                    @foreach([
                        ['objects','Objetos postales','fa-box','Objetos con fecha postal en el periodo.'],
                        ['declaredObjects','Con declaración','fa-file-alt','Objetos con una o más declaraciones.'],
                        ['declarations','Declaraciones','fa-file-signature','Registros de declaración en CDS.'],
                        ['respondedObjects','Con respuesta','fa-reply','Objetos con una o más respuestas.'],
                        ['responses','Respuestas','fa-clipboard-check','Registros de respuesta en CDS.'],
                        ['withoutResponse','Sin respuesta','fa-hourglass-half','Objetos sin respuesta asociada.'],
                    ] as [$key,$label,$icon,$hint])
                        <article class="volume-kpi" title="{{ $hint }}"><div><span>{{ $label }}</span><i class="fas {{ $icon }}"></i></div><strong data-kpi="{{ $key }}">—</strong><small data-change="{{ $key }}">Comparando periodo anterior</small><em>{{ $hint }}</em></article>
                    @endforeach
                </div>
            </section>
            @endif
        </div>

        <nav class="volume-section-nav" aria-label="Secciones del informe">
            <a href="#volume-executive-summary" class="is-current"><i class="fas fa-chart-pie"></i> Resumen</a>
            @if($scope!=='cds')<a href="#volume-analytics"><i class="fas fa-globe-americas"></i> Análisis postal</a>@endif
            @if($scope==='all')<a href="#cds-volume-analytics"><i class="fas fa-file-invoice"></i> Análisis aduanero</a>@elseif($scope==='cds')<a href="#volume-analytics"><i class="fas fa-file-invoice"></i> Análisis aduanero</a>@endif
            @if($scope==='all')
                <a href="#volume-detail-table"><i class="fas fa-table"></i> Detalle IPS</a>
                <a href="#cds-volume-detail-table"><i class="fas fa-table"></i> Detalle CDS</a>
            @else
                <a href="#volume-detail-table"><i class="fas fa-table"></i> Detalle</a>
            @endif
        </nav>

        <section id="volume-analytics" class="volume-analysis-source" aria-labelledby="volume-analysis-heading">
            <header class="volume-section-heading">
                <div>
                    <span class="volume-section-eyebrow">{{ $scope==='cds' ? 'ANÁLISIS · ADUANA' : 'ANÁLISIS · OPERACIÓN POSTAL' }}</span>
                    <h2 id="volume-analysis-heading">{{ $scope==='cds' ? 'Actividad aduanera en el tiempo' : 'Movimiento postal en el tiempo' }}</h2>
                    <p>{{ $scope==='cds' ? 'Objetos, declaraciones y respuestas por hora, día o mes.' : 'Compara flujos, clases postales, origen y destino.' }}</p>
                </div>
                <span class="volume-interaction-hint"><i class="fas fa-mouse-pointer"></i> Selecciona una gráfica para filtrar</span>
            </header>

            <div class="volume-chart-row">
                <article class="volume-panel volume-trend-panel"><header><div><h2 id="trend-title">Tendencia postal</h2><p id="trend-subtitle">Movimiento por hora · hora de Bolivia</p></div><span id="trend-caption">Por hora</span></header><div id="volume-trend" class="volume-chart"></div><div id="volume-trend-legend" class="volume-legend"></div></article>
                <article class="volume-panel"><header><div><h2 id="distribution-title">Envíos por clase postal</h2><p>Distribución del periodo seleccionado</p></div></header><div id="volume-distribution" class="volume-chart"></div><div id="distribution-legend" class="volume-legend"></div></article>
            </div>
            <div class="volume-breakdown-row">
                <article class="volume-panel"><header><div><h2 id="destination-title">Países de destino</h2><p>Principales destinos por volumen</p></div></header><div id="volume-destinations" class="volume-ranking"></div></article>
                <article class="volume-panel"><header><div><h2 id="origin-title">Países de origen</h2><p>Principales orígenes por volumen</p></div></header><div id="volume-origins" class="volume-ranking"></div></article>
                <article class="volume-panel volume-donut-panel"><header><div><h2 id="category-title">Envíos por categoría</h2><p>Composición del volumen postal</p></div></header><div id="volume-categories" class="volume-donut"></div></article>
                <article class="volume-panel volume-donut-panel"><header><div><h2 id="product-title">Envíos por producto S10</h2><p>Rangos de producto identificados</p></div></header><div id="volume-products" class="volume-donut"></div></article>
            </div>
            <article id="volume-detail-table" class="volume-panel volume-detail-table">
                <header><div><h2 id="volume-table-title">Actividad por fecha</h2><p id="volume-table-caption">Cada fila resume la actividad del periodo seleccionado.</p></div></header>
                @if($scope==='cds')
                <div class="volume-table-guide volume-table-guide-cds">
                    <div class="volume-table-guide-title"><i class="fas fa-lightbulb" aria-hidden="true"></i><strong>Cómo leer este detalle</strong><span id="volume-table-grain">Cada fila es una fecha postal.</span></div>
                    <div class="volume-table-guide-items">
                        <div><i class="fas fa-box" aria-hidden="true"></i><span><strong>Objetos postales</strong><small>Objetos con actividad postal en esa fecha.</small></span></div>
                        <div><i class="fas fa-file-signature" aria-hidden="true"></i><span><strong>Declaraciones</strong><small>Registros de declaración aduanera.</small></span></div>
                        <div><i class="fas fa-reply" aria-hidden="true"></i><span><strong>Respuestas</strong><small>Registros de respuesta asociados.</small></span></div>
                    </div>
                    <p class="volume-table-guide-note"><i class="fas fa-info-circle" aria-hidden="true"></i> Un objeto puede tener varias declaraciones o respuestas; estas columnas describen etapas distintas y no se suman.</p>
                </div>
                @else
                <div class="volume-table-guide volume-table-guide-ips">
                    <div class="volume-table-guide-title"><i class="fas fa-lightbulb" aria-hidden="true"></i><strong>Cómo leer este detalle</strong><span id="volume-table-grain">Cada fila es un día local de Bolivia.</span></div>
                    <div class="volume-table-guide-items">
                        <div><i class="fas fa-box" aria-hidden="true"></i><span><strong>Envíos únicos</strong><small>Identificadores postales distintos con actividad.</small></span></div>
                        <div><i class="fas fa-inbox" aria-hidden="true"></i><span><strong>Recibidos</strong><small>Envíos con destino asociado a Bolivia.</small></span></div>
                        <div><i class="fas fa-paper-plane" aria-hidden="true"></i><span><strong>Despachados</strong><small>Envíos con origen asociado a Bolivia.</small></span></div>
                        <div><i class="fas fa-route" aria-hidden="true"></i><span><strong>Movimientos</strong><small>Eventos de seguimiento, no envíos únicos.</small></span></div>
                    </div>
                    <p class="volume-table-guide-note"><i class="fas fa-info-circle" aria-hidden="true"></i> Un envío puede aparecer como recibido y despachado; esos conteos se traslapan y no se suman.</p>
                </div>
                @endif
                <div class="table-responsive"><table class="table"><thead id="volume-table-head"></thead><tbody id="volume-table-body"></tbody></table></div>
            </article>
        </section>

        @if($scope==='all')
        <section id="cds-volume-analytics" class="volume-analysis-source volume-cds-analysis" aria-labelledby="cds-analysis-heading">
            <header class="volume-section-heading">
                <div><span class="volume-section-eyebrow">ANÁLISIS · GESTIÓN ADUANERA</span><h2 id="cds-analysis-heading">Declaraciones y respuestas CDS</h2><p>Estados y operadores relacionados con los objetos postales del periodo.</p></div>
                <a href="{{ route('postal.cds') }}" class="volume-section-link">Abrir expediente <i class="fas fa-arrow-right"></i></a>
            </header>
            <div class="volume-cds-chart-row">
                <article class="volume-panel"><header><div><h2>Evolución CDS</h2><p>Objetos, declaraciones y respuestas por fecha postal</p></div></header><div id="cds-volume-trend" class="volume-chart"></div><div id="cds-volume-trend-legend" class="volume-legend"></div></article>
                <article class="volume-panel"><header><div><h2>Estados de declaración</h2><p>Distribución de registros CDS</p></div></header><div id="cds-volume-states" class="volume-ranking"></div></article>
                <article class="volume-panel"><header><div><h2>Operadores de origen</h2><p>Organización postal registrada en CDS</p></div></header><div id="cds-volume-origins" class="volume-ranking"></div></article>
                <article class="volume-panel"><header><div><h2>Operadores de destino</h2><p>Organización postal registrada en CDS</p></div></header><div id="cds-volume-destinations" class="volume-ranking"></div></article>
            </div>
            <article id="cds-volume-detail-table" class="volume-panel volume-detail-table">
                <header><div><h2 id="cds-volume-table-title">Detalle CDS del periodo</h2><p id="cds-volume-table-caption">Objetos, declaraciones y respuestas agrupados por fecha postal.</p></div></header>
                <div class="volume-table-guide volume-table-guide-cds">
                    <div class="volume-table-guide-title"><i class="fas fa-lightbulb" aria-hidden="true"></i><strong>Cómo leer este detalle</strong><span id="cds-volume-table-grain">Cada fila es una fecha postal.</span></div>
                    <div class="volume-table-guide-items">
                        <div><i class="fas fa-box" aria-hidden="true"></i><span><strong>Objetos postales</strong><small>Objetos con actividad postal en esa fecha.</small></span></div>
                        <div><i class="fas fa-file-signature" aria-hidden="true"></i><span><strong>Declaraciones</strong><small>Registros de declaración aduanera.</small></span></div>
                        <div><i class="fas fa-reply" aria-hidden="true"></i><span><strong>Respuestas</strong><small>Registros de respuesta asociados.</small></span></div>
                    </div>
                    <p class="volume-table-guide-note"><i class="fas fa-info-circle" aria-hidden="true"></i> Un objeto puede tener varias declaraciones o respuestas; estas columnas describen etapas distintas y no se suman.</p>
                </div>
                <div class="table-responsive"><table class="table"><thead id="cds-volume-table-head"></thead><tbody id="cds-volume-table-body"></tbody></table></div>
            </article>
        </section>
        @endif
    </section>    <div id="volume-generated" class="volume-generated"></div>
    <noscript><div class="alert alert-info">Activa JavaScript para consultar el reporte de volúmenes.</div></noscript>
</main>
@include('footer')
@stop
@section('js')<script src="{{ asset('js/volume-dashboard.js') }}?v=14" defer></script>@stop
