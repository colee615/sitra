<div class="sitra-dashboard" id="operational-dashboard" data-url="{{ route('dashboard.data') }}" data-package-url="{{ route('postal.ips') }}" data-today="{{ now('America/La_Paz')->toDateString() }}">
    <div class="dashboard-toolbar">
        @can('postal.ips')<div class="dashboard-tabs" role="tablist" aria-label="Vista del dashboard">
            <button class="is-active" id="tab-executive" type="button" role="tab" aria-selected="true" aria-controls="executive-view" data-view="executive"><i class="fas fa-chart-pie"></i> Resumen ejecutivo</button>
            <button id="tab-operational" type="button" role="tab" aria-selected="false" aria-controls="operational-view" data-view="operational" tabindex="-1"><i class="fas fa-stream"></i> Detalle operativo</button>
        </div>@endcan
        <button class="btn btn-outline-secondary btn-sm" id="dashboard-export" type="button" disabled><i class="fas fa-download mr-1"></i> Exportar resumen</button>
    </div>
    <form id="dashboard-filters" class="dashboard-filter-panel">
        <div class="dashboard-filter-top"><span><i class="fas fa-sliders-h"></i> Periodo de análisis</span><div class="dashboard-presets" aria-label="Periodos rápidos">@foreach(['today'=>'Hoy','week'=>'Semana','month'=>'Mes','year'=>'Año'] as $key=>$label)<button type="button" data-period="{{ $key }}" class="{{ $key==='month' ? 'is-active' : '' }}" aria-pressed="{{ $key==='month' ? 'true' : 'false' }}">{{ $label }}</button>@endforeach</div></div>
        <div class="dashboard-filter-grid">
            <label>Desde<input type="date" name="from" value="{{ now('America/La_Paz')->startOfMonth()->toDateString() }}" required></label>
            <label>Hasta<input type="date" name="to" value="{{ now('America/La_Paz')->toDateString() }}" required></label>
            @can('postal.ips')
            <label>Oficina<select name="office" data-catalog="offices"><option value="">Todas las oficinas</option></select></label>
            <label>Clase / servicio<select name="service" data-catalog="services"><option value="">Todos los servicios</option></select></label>
            @endcan
            <button type="submit" class="btn btn-primary"><i class="fas fa-filter mr-1"></i> Aplicar filtros</button>
        </div>
        <div class="dashboard-filter-bottom">
            @can('postal.ips')<button class="dashboard-text-button" id="advanced-toggle" type="button" aria-expanded="false" aria-controls="advanced-filters"><i class="fas fa-plus mr-1"></i> Más filtros</button>@endcan
            <span id="filter-summary">Hora de Bolivia · UTC−4</span><button type="button" class="dashboard-text-button" id="filters-reset">Restablecer</button>
        </div>
        @can('postal.ips')<div id="advanced-filters" class="dashboard-filter-grid dashboard-advanced" hidden>
            <label>Último estado del periodo<select name="state"><option value="">Todos los estados</option>@foreach(\App\Services\Postal\OperationalDashboard::STATES as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
            <label>País de origen<select name="origin" data-catalog="countries"><option value="">Todos los orígenes</option></select></label>
            <label>País de destino<select name="destination" data-catalog="countries"><option value="">Todos los destinos</option></select></label>
            <label>Tipo de envío<select name="type"><option value="">Todos los tipos</option><option value="national">Nacional (BO → BO)</option><option value="international">Internacional</option><option value="unknown">Origen/destino incompleto</option></select></label>
        </div>@endcan
    </form>
    <div class="dashboard-update"><span id="dashboard-status" role="status" aria-live="polite"><span class="dashboard-live-dot"></span> Consultando las fuentes conectadas…</span><button id="dashboard-refresh" class="dashboard-text-button" type="button"><i class="fas fa-sync-alt mr-1"></i> Actualizar</button></div>
    <div id="dashboard-error" class="alert alert-warning" role="alert" hidden></div>
    <div id="dashboard-results" aria-busy="true">
        @can('postal.ips')
        <div id="ips-unavailable" class="dashboard-empty" hidden></div>
        <div id="ips-content">
            <div class="dashboard-kpis">
                @foreach([
                    ['packages','Envíos con actividad','box','Envíos únicos en el periodo','featured'],
                    ['received','Envíos recibidos','arrow-down','Con al menos una recepción',''],
                    ['dispatched','Envíos despachados','arrow-up','Con al menos una salida',''],
                    ['delivered','Envíos entregados','check','Con evento de entrega IPS',''],
                ] as [$key,$label,$icon,$help,$style])
                <article class="dashboard-kpi {{ $style }}"><div class="dashboard-kpi-label"><span>{{ $label }}</span><i class="fas fa-{{ $icon }}"></i></div><strong data-metric="{{ $key }}">—</strong><div class="dashboard-kpi-change" data-change="{{ $key }}">Esperando datos</div><small>{{ $help }}</small></article>
                @endforeach
            </div>
            <p class="dashboard-caption">Un envío puede tener recepción, despacho y entrega en el mismo periodo. Estas tarjetas no se suman.</p>
            <section id="executive-view" role="tabpanel" aria-labelledby="tab-executive">
                <div class="dashboard-chart-grid">
                    <article class="dashboard-panel dashboard-trend"><header><div><span class="postal-eyebrow">PULSO DE LA OPERACIÓN</span><h2>Entradas y salidas</h2><p>Movimientos registrados · hora de Bolivia</p></div><select id="trend-group" aria-label="Agrupación temporal"><option value="day">Por día</option><option value="week">Por semana</option><option value="month">Por mes</option></select></header><div id="trend-chart" class="dashboard-line-chart"></div><div class="dashboard-chart-legend"><span><i class="legend-dot yellow"></i> Entradas</span><span><i class="legend-dot dark"></i> Salidas</span><span id="trend-total"></span></div></article>
                    <article class="dashboard-panel"><header><div><span class="postal-eyebrow">DISTRIBUCIÓN</span><h2>Situación de los envíos</h2><p>Último evento operativo del periodo y oficina</p></div></header><div id="state-chart" class="dashboard-rankings"></div></article>
                </div>
                <div class="dashboard-mini-kpis">
                    <article><span>Movimientos</span><strong data-metric="movements">—</strong><small>Todos los eventos registrados</small></article>
                    <article><span>Oficinas con actividad</span><strong data-metric="offices">—</strong><small>Oficinas registradas en los eventos</small></article>
                    <article><span>Operadores con actividad</span><strong data-metric="operators">—</strong><small>Identidades IPS distintas</small></article>
                    <article><span>Peso registrado</span><strong id="weight-total">—</strong><small id="weight-coverage">Una vez por envío del periodo</small></article>
                </div>
                <div class="dashboard-three-grid">
                    <article class="dashboard-panel"><header><div><span class="postal-eyebrow">SERVICIOS</span><h2>Volumen por clase</h2><p>Envíos únicos · selecciona para filtrar</p></div></header><div id="service-chart" class="dashboard-rankings"></div></article>
                    <article class="dashboard-panel"><header><div><span class="postal-eyebrow">RED POSTAL</span><h2>Actividad por oficina</h2><p>Movimientos · selecciona para filtrar</p></div></header><div id="office-chart" class="dashboard-rankings"></div></article>
                    <article class="dashboard-panel"><header><div><span class="postal-eyebrow">COBERTURA</span><h2>Origen y destino</h2><p>Envíos nacionales e internacionales</p></div></header><div id="type-chart" class="dashboard-rankings"></div><div class="dashboard-context-note"><i class="fas fa-info-circle"></i><span id="inventory-total">Cargando registro histórico…</span></div></article>
                </div>
            </section>
            <section id="operational-view" role="tabpanel" aria-labelledby="tab-operational" hidden>
                <div class="dashboard-mini-kpis">
                    <article><span>En oficina / pendientes</span><strong data-stage="pending">—</strong><small>Último evento en el periodo</small></article>
                    <article><span>En tránsito / reparto</span><strong data-stage="transit">—</strong><small>Último evento en el periodo</small></article>
                    <article><span>Retenidos / intento fallido</span><strong data-stage="observed">—</strong><small>Último evento en el periodo</small></article>
                    <article><span>Devolución en curso</span><strong id="returns-total">—</strong><small>Estado postal actual 6/7 de los envíos del periodo</small></article>
                </div>
                <article class="dashboard-panel"><header><div><span class="postal-eyebrow">COMPARATIVA DE SERVICIOS</span><h2>Flujo por clase postal</h2></div><a href="{{ route('postal.operations') }}" class="btn btn-outline-secondary btn-sm">Reporte por oficina <i class="fas fa-arrow-right ml-1"></i></a></header><div class="table-responsive"><table class="table"><thead><tr><th>Clase / servicio</th><th>Envíos únicos</th><th>Entradas</th><th>Salidas</th><th>Movimientos</th></tr></thead><tbody id="service-flow"></tbody></table></div></article>
                <div class="dashboard-three-grid">
                    <article class="dashboard-panel"><header><div><h2>Países de origen</h2><p>Envíos únicos</p></div></header><div id="origin-chart" class="dashboard-rankings"></div></article>
                    <article class="dashboard-panel"><header><div><h2>Países de destino</h2><p>Envíos únicos</p></div></header><div id="destination-chart" class="dashboard-rankings"></div></article>
                    <article class="dashboard-panel"><header><div><h2>Eventos más frecuentes</h2><p>Movimientos registrados</p></div></header><div id="event-chart" class="dashboard-rankings"></div></article>
                </div>
                <article class="dashboard-panel"><header><div><h2>Evolución del periodo</h2><p>Totales diarios completos, sin muestreo</p></div></header><div class="table-responsive dashboard-table-scroll"><table class="table"><thead><tr><th>Fecha · Bolivia</th><th>Envíos únicos del día</th><th>Entradas</th><th>Salidas</th><th>Eventos de entrega</th><th>Movimientos</th></tr></thead><tbody id="daily-table"></tbody></table></div></article>
                <article class="dashboard-panel"><header><div><h2>Últimos envíos del periodo</h2><p>Hasta 12 expedientes · abre un código para consultar su historial</p></div></header><div class="table-responsive"><table class="table"><thead><tr><th>Envío</th><th>Servicio</th><th>Situación en el periodo</th><th>Fecha · Bolivia</th></tr></thead><tbody id="recent-table"></tbody></table></div></article>
            </section>
        </div>
        @endcan
        @can('postal.cds')
        <section class="dashboard-panel dashboard-customs"><header><div><span class="postal-eyebrow">GESTIÓN ADUANERA · CDS</span><h2>Declaraciones y respuestas</h2><p>Objetos cuya fecha postal CDS está en el periodo seleccionado.</p></div><a href="{{ route('postal.cds') }}" class="btn btn-outline-secondary btn-sm">Consultar CDS <i class="fas fa-arrow-right ml-1"></i></a></header><div id="cds-status" role="status">Consultando CDS…</div><div id="cds-metrics" class="dashboard-mini-kpis" hidden>@foreach(['objects'=>'Objetos postales','declarations'=>'Declaraciones','responses'=>'Respuestas aduaneras','withoutResponse'=>'Objetos sin respuesta'] as $key=>$label)<article><span>{{ $label }}</span><strong data-cds="{{ $key }}">—</strong></article>@endforeach</div><div id="cds-states" class="dashboard-rankings"></div><p class="dashboard-caption">La fecha postal se conserva según CDS, sin zona horaria informada. Las respuestas corresponden a estos objetos, independientemente de su fecha de emisión; no equivalen a entregas.</p></section>
        @endcan
    </div>
    <details class="dashboard-method"><summary><i class="fas fa-info-circle mr-1"></i> Cómo se calculan los indicadores</summary><div><p>IPS: envíos únicos con eventos en el periodo y filtros seleccionados. Entradas y salidas cuentan movimientos; un envío puede aparecer en varios días y oficinas. Las series semanales y mensuales suman movimientos diarios.</p><p>La situación usa el último evento operativo del periodo y oficina; las actualizaciones técnicas no sustituyen un movimiento físico. No representa el inventario pendiente histórico. La comparación usa el periodo inmediatamente anterior de igual duración; un día actual todavía puede estar incompleto.</p><p>Recepciones: eventos 1, 3, 5, 30, 32, 33, 42, 43, 44, 68, 71, 75 y 78. Salidas: 2, 12, 35, 72 y 74. Entregas: 37 y 1250. “Devolución en curso” usa el estado postal actual 6/7; no hay un indicador verificado de devolución final al remitente.</p><p>Las fuentes no ofrecen una regional estructurada ni una equivalencia comprobada entre oficinas IPS y organizaciones CDS. Se informa por oficina y CDS indica cuándo un filtro no es compatible. Caché de indicadores: 90 segundos; registro histórico: 30 minutos.</p></div></details>
    <noscript><div class="alert alert-info">Activa JavaScript para cargar los gráficos. Puedes consultar los reportes y expedientes desde Gestión diaria.</div></noscript>
</div>
