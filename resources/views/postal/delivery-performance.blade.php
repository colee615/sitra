@extends('adminlte::page')

@section('title', 'Rendimiento de entregas IPS | SITRA')

@section('css')
    <link rel="stylesheet" href="{{ asset('css/postal-delivery-performance.css') }}?v=1">
@stop

@section('content')
@php
    $formatNumber = static fn ($value, $decimals = 0) => number_format((float) $value, $decimals, ',', '.');
@endphp
<main class="delivery-report" id="delivery-report">
    <header class="delivery-hero">
        <div class="delivery-brand-mark"><i class="fas fa-box-open" aria-hidden="true"></i></div>
        <div class="delivery-hero-copy">
            <span class="delivery-eyebrow">INTELIGENCIA POSTAL · IPS · BOA</span>
            <h1>Rendimiento de entregas</h1>
            <p>Volumen entregado, actividad por oficina y usuarios que registraron la entrega.</p>
        </div>
        <div class="delivery-hero-source"><i class="fas fa-database"></i><span>Fuente oficial<br><strong>Eventos IPS</strong></span></div>
    </header>

    <section class="delivery-toolbar" aria-label="Acciones del reporte">
        <div class="delivery-toolbar-title"><i class="fas fa-chart-line"></i><span>Reporte ejecutivo <small>· consulta operativa</small></span></div>
        <div class="delivery-toolbar-actions">
            @if($result)
                <a class="delivery-button delivery-button-light" href="{{ $csvUrl }}"><i class="fas fa-file-csv"></i> Descargar CSV</a>
                <a class="delivery-button delivery-button-gold" href="{{ $pdfUrl }}"><i class="fas fa-file-pdf"></i> Generar PDF</a>
            @endif
            <a class="delivery-button delivery-button-quiet" href="{{ route('postal.deliveries.performance') }}"><i class="fas fa-undo"></i> Limpiar</a>
        </div>
    </section>

    <form class="delivery-filter-panel" method="get" action="{{ route('postal.deliveries.performance') }}" id="delivery-filter-form" data-today="{{ now($filters['timezone'])->toDateString() }}">
        <div class="delivery-filter-heading"><div><span class="delivery-section-icon"><i class="fas fa-sliders-h"></i></span><div><h2>Parámetros del análisis</h2><p>El rango usa la hora local de Bolivia y admite hasta {{ config('postal.delivery_report_max_days', 366) }} días.</p></div></div><span class="delivery-operator-chip"><i class="fas fa-globe-americas"></i> BOA · Bolivia Post</span></div>
        <div class="delivery-filter-grid">
            <label><span>Fecha inicial</span><input type="date" name="desde" value="{{ $filters['from']->format('Y-m-d') }}" required></label>
            <label><span>Fecha final</span><input type="date" name="hasta" value="{{ $filters['to']->format('Y-m-d') }}" required></label>
            <label class="delivery-filter-wide"><span>Oficina de entrega</span><select name="oficina"><option value="">Todas las oficinas</option>@foreach($catalog['offices'] as $office)<option value="{{ $office->code }}" @selected($filters['office'] === (string) $office->code)>{{ $office->short_name ?: $office->code }} · {{ $office->name ?: 'Oficina sin nombre' }}</option>@endforeach</select></label>
            <label><span>Clase postal</span><select name="servicio"><option value="">Todos los servicios</option>@foreach($catalog['services'] as $service)<option value="{{ $service->code }}" @selected($filters['service'] === (string) $service->code)>{{ $service->name ?: $service->code }}</option>@endforeach</select></label>
            @if($filters['user'] !== '')
                <input type="hidden" name="usuario" value="{{ $filters['user'] }}">
            @endif
        </div>
        <div class="delivery-filter-footer">
            <div class="delivery-presets" aria-label="Periodos rápidos">
                <button type="button" data-delivery-range="today">Hoy</button><button type="button" data-delivery-range="7">7 días</button><button type="button" data-delivery-range="month">Este mes</button>
                @if($filters['user'] !== '')<a href="{{ route('postal.deliveries.performance', ['desde'=>$filters['from']->format('Y-m-d'),'hasta'=>$filters['to']->format('Y-m-d'),'oficina'=>$filters['office'],'servicio'=>$filters['service']]) }}"><i class="fas fa-user-times"></i> Quitar filtro de usuario</a>@endif
            </div>
            <button type="submit" class="delivery-button delivery-button-navy"><i class="fas fa-filter"></i> Aplicar filtros</button>
        </div>
        @if($errors->any())<div class="delivery-validation"><i class="fas fa-exclamation-circle"></i> {{ $errors->first() }}</div>@endif
    </form>

    @if($error)
        <section class="delivery-error"><i class="fas fa-exclamation-triangle"></i><div><strong>IPS no respondió la consulta</strong><p>{{ $error }}</p></div></section>
    @elseif($result)
        <div class="delivery-period-line"><span><i class="far fa-calendar-alt"></i> {{ $filters['from']->format('d/m/Y') }} — {{ $filters['to']->format('d/m/Y') }} <b>· hora de Bolivia</b></span><span>Actualizado {{ $result['generated_at']->format('d/m/Y H:i') }}</span></div>
        @php
            $activeOffice = $filters['office'] !== '' ? $catalog['offices']->first(fn ($item) => (string) $item->code === $filters['office']) : null;
            $activeService = $filters['service'] !== '' ? $catalog['services']->first(fn ($item) => (string) $item->code === $filters['service']) : null;
        @endphp
        <div class="delivery-active-filters"><span><i class="fas fa-layer-group"></i> Alcance del análisis</span><b>BOA · Bolivia Post</b>@if($activeOffice)<b><i class="fas fa-building"></i> {{ $activeOffice->short_name ?: $activeOffice->code }} · {{ $activeOffice->name }}</b>@endif @if($activeService)<b><i class="fas fa-box"></i> {{ $activeService->name ?: $activeService->code }}</b>@endif @if($filters['user'] !== '')<b><i class="fas fa-user"></i> Usuario IPS {{ $filters['user'] }}</b>@endif</div>

        <section class="delivery-kpis" aria-label="Indicadores ejecutivos">
            <article class="delivery-kpi delivery-kpi-primary"><span class="delivery-kpi-icon"><i class="fas fa-box"></i></span><div><span class="delivery-kpi-label">Envíos entregados únicos</span><strong>{{ $formatNumber($result['totals']['delivered']) }}</strong><small>Una vez por envío dentro del periodo</small></div><span class="delivery-kpi-watermark">01</span></article>
            <article class="delivery-kpi"><span class="delivery-kpi-icon teal"><i class="fas fa-clipboard-check"></i></span><div><span class="delivery-kpi-label">Eventos de entrega</span><strong>{{ $formatNumber($result['totals']['delivery_events']) }}</strong><small>Registros IPS · códigos 37 y 1250</small></div></article>
            <article class="delivery-kpi"><span class="delivery-kpi-icon blue"><i class="fas fa-user-check"></i></span><div><span class="delivery-kpi-label">Usuarios registradores</span><strong>{{ $formatNumber($result['totals']['registrars']) }}</strong><small>Identificados en el historial IPS</small></div></article>
            <article class="delivery-kpi"><span class="delivery-kpi-icon gold"><i class="fas fa-building"></i></span><div><span class="delivery-kpi-label">Oficinas con entregas</span><strong>{{ $formatNumber($result['totals']['offices']) }}</strong><small>Oficinas con al menos un envío</small></div></article>
            <article class="delivery-kpi delivery-kpi-highlight"><span class="delivery-kpi-icon navy"><i class="fas fa-trophy"></i></span><div><span class="delivery-kpi-label">Mayor actividad registrada</span><strong class="delivery-kpi-name">{{ $result['totals']['top_registrar'] }}</strong><small>{{ $formatNumber($result['totals']['top_registrar_total']) }} envíos únicos</small></div></article>
        </section>

        <section class="delivery-insight-strip"><i class="fas fa-info-circle"></i><span>“Registrado por” identifica la cuenta que guardó el evento en IPS. No confirma por sí solo que esa persona sea el cartero que entregó físicamente el envío.</span><span class="delivery-insight-average"><strong>{{ $formatNumber($result['totals']['daily_average'], 1) }}</strong> promedio por día calendario</span></section>

        <section class="delivery-analysis-grid">
            <article class="delivery-panel delivery-trend-panel">
                <header class="delivery-panel-heading"><div><span class="delivery-panel-icon teal"><i class="fas fa-chart-bar"></i></span><div><h2>Tendencia {{ $result['trend_grain'] === 'mes' ? 'mensual' : 'diaria' }} de entregas</h2><p>Envíos únicos agrupados por {{ $result['trend_grain'] }} · fecha local Bolivia</p></div></div><span class="delivery-panel-total">{{ $formatNumber($result['totals']['delivered']) }} <small>envíos</small></span></header>
                <div class="delivery-trend-chart" role="img" aria-label="Entregas por día durante el periodo">
                    @foreach($result['trend'] as $point)
                        <div class="delivery-trend-column" title="{{ $point['date'] }}: {{ $formatNumber($point['delivered']) }} entregas"><span class="delivery-trend-value">{{ $point['delivered'] ?: '' }}</span><div class="delivery-trend-track"><i style="height: {{ $point['height'] }}%"></i></div><small>{{ $point['label'] }}</small></div>
                    @endforeach
                </div>
                <footer class="delivery-chart-foot"><span><i class="fas fa-square-full"></i> Entregas únicas por {{ $result['trend_grain'] }}</span><span>Máximo del periodo: {{ $formatNumber(max(array_column($result['trend'], 'delivered') ?: [0])) }}</span></footer>
            </article>

            <article class="delivery-panel delivery-ranking-panel">
                <header class="delivery-panel-heading"><div><span class="delivery-panel-icon gold"><i class="fas fa-trophy"></i></span><div><h2>Usuarios que más registraron</h2><p>Ranking por envíos únicos · seleccionar para profundizar</p></div></div></header>
                @php($userMax = max(1, (int) collect($result['users'])->max('delivered')))
                <div class="delivery-rank-list">
                    @forelse($result['users'] as $index => $actor)
                        @php($actorLabel = $actor->USER_NM ?: $actor->USER_FID ?: 'Usuario no informado por IPS')
                        <a class="delivery-rank-row {{ $index === 0 ? 'is-leader' : '' }}" href="{{ route('postal.deliveries.performance', array_merge($query, ['usuario'=>$actor->USER_PID])) }}" title="Filtrar por {{ $actorLabel }}">
                            <span class="delivery-rank-position">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span><span class="delivery-rank-label"><strong>{{ $actorLabel }}</strong><small>{{ $formatNumber((int) $actor->offices) }} oficinas con registros</small><i><b style="width: {{ min(100, round(((int) $actor->delivered / $userMax) * 100)) }}%"></b></i></span><span class="delivery-rank-value">{{ $formatNumber((int) $actor->delivered) }}</span>
                        </a>
                    @empty
                        <div class="delivery-chart-empty"><i class="fas fa-user-slash"></i><strong>Sin registros de entrega</strong><span>Prueba con otro periodo o quita los filtros.</span></div>
                    @endforelse
                </div>
            </article>

            <article class="delivery-panel delivery-office-panel">
                <header class="delivery-panel-heading"><div><span class="delivery-panel-icon blue"><i class="fas fa-map-marker-alt"></i></span><div><h2>Entregas por oficina</h2><p>Selecciona una oficina para analizar su operación</p></div></div></header>
                @php($officeMax = max(1, (int) collect($result['offices_ranking'])->max('delivered')))
                <div class="delivery-office-list">
                    @forelse($result['offices_ranking'] as $office)
                        @php($officeLabel = trim(($office->OFFICE_FCD ?? '').' '.($office->OFFICE_NM ?? '')) ?: 'Oficina no informada')
                        <a class="delivery-office-row" href="{{ route('postal.deliveries.performance', array_merge($query, ['oficina'=>$office->EVENT_OFFICE_CD])) }}"><span class="delivery-office-label"><strong>{{ $officeLabel }}</strong><small>{{ $formatNumber((int) $office->registrars) }} usuarios</small></span><span class="delivery-office-meter"><i style="width: {{ min(100, round(((int) $office->delivered / $officeMax) * 100)) }}%"></i></span><b>{{ $formatNumber((int) $office->delivered) }}</b></a>
                    @empty
                        <div class="delivery-chart-empty"><i class="fas fa-building"></i><strong>Sin actividad para mostrar</strong><span>No hay entregas en las oficinas del periodo.</span></div>
                    @endforelse
                </div>
            </article>

            <article class="delivery-panel delivery-service-panel">
                <header class="delivery-panel-heading"><div><span class="delivery-panel-icon navy"><i class="fas fa-layer-group"></i></span><div><h2>Composición por servicio</h2><p>Distribución de los envíos entregados</p></div></div></header>
                @php($serviceMax = max(1, (int) collect($result['services'])->max('delivered')))
                <div class="delivery-service-list">
                    @forelse($result['services'] as $service)
                        <div class="delivery-service-row"><span>{{ $service->MAIL_CLASS_NM ?: $service->MAIL_CLASS_CD ?: 'Clase no informada' }}</span><i><b style="width: {{ min(100, round(((int) $service->delivered / $serviceMax) * 100)) }}%"></b></i><strong>{{ $formatNumber((int) $service->delivered) }}</strong></div>
                    @empty
                        <div class="delivery-chart-empty"><i class="fas fa-box-open"></i><strong>Sin clases postales</strong><span>IPS no devolvió entregas para estos filtros.</span></div>
                    @endforelse
                </div>
            </article>
        </section>

        <section class="delivery-panel delivery-detail-panel">
            <header class="delivery-panel-heading"><div><span class="delivery-panel-icon navy"><i class="fas fa-clipboard-list"></i></span><div><h2>Detalle de envíos entregados</h2><p>{{ $formatNumber($result['detail_total']) }} envíos únicos · una fila por envío, orden descendente por fecha del evento</p></div></div><span class="delivery-detail-badge"><i class="fas fa-shield-alt"></i> Datos IPS</span></header>
            @if($result['detail_truncated'])<div class="delivery-truncation"><i class="fas fa-info-circle"></i> La vista muestra {{ $formatNumber($result['detail_limit']) }} filas de {{ $formatNumber($result['detail_total']) }}. El CSV incluye el detalle completo del periodo.</div>@endif
            <div class="table-responsive">
                <table class="delivery-detail-table">
                    <thead><tr><th>#</th><th>Fecha y hora local</th><th>Envío</th><th>Evento IPS</th><th>Oficina</th><th>Registrado por</th><th>Clase postal</th></tr></thead>
                    <tbody>
                    @forelse($result['rows'] as $row)
                        @php($localTime = \Carbon\CarbonImmutable::parse($row->EVENT_GMT_DT, 'UTC')->setTimezone($filters['timezone']))
                        <tr><td><span class="delivery-table-index">{{ str_pad((string) ($loop->iteration), 3, '0', STR_PAD_LEFT) }}</span></td><td><strong>{{ $localTime->format('d/m/Y') }}</strong><small>{{ $localTime->format('H:i') }} · Bolivia</small></td><td><a href="{{ route('postal.ips', ['codigo'=>$row->MAILITM_FID ?: $row->MAILITM_LOCAL_ID]) }}">{{ $row->MAILITM_FID ?: 'Código no informado' }}</a>@if($row->MAILITM_LOCAL_ID)<small>ID local: {{ $row->MAILITM_LOCAL_ID }}</small>@endif</td><td><strong>{{ $row->EVENT_NAME ?: 'Evento de entrega IPS' }}</strong><small>Código {{ $row->EVENT_TYPE_CD }}</small></td><td>{{ trim(($row->OFFICE_FCD ?? '').' '.($row->OFFICE_NM ?? '')) ?: 'Oficina no informada' }}</td><td>{{ $row->USER_NM ?: $row->USER_FID ?: 'Usuario no informado por IPS' }}@if($row->USER_FID)<small>ID IPS: {{ $row->USER_FID }}</small>@endif</td><td><span class="delivery-service-pill">{{ $row->MAIL_CLASS_NM ?: $row->MAIL_CLASS_CD ?: 'No informada' }}</span></td></tr>
                    @empty
                        <tr><td colspan="7"><div class="delivery-empty-state"><span><i class="fas fa-inbox"></i></span><strong>No hay entregas para estos filtros</strong><small>Prueba ampliando el periodo o selecciona todas las oficinas.</small></div></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <footer class="delivery-detail-footer"><span><i class="fas fa-check-circle"></i> {{ $formatNumber($result['totals']['delivered']) }} envíos únicos contabilizados</span><span>Fuente: L_MAILITM_EVENTS · tipos de entrega IPS 37 y 1250</span></footer>
        </section>

        <footer class="delivery-method-note"><i class="fas fa-info-circle"></i><span><strong>Cómo se calcula:</strong> cada envío cuenta una sola vez. Si IPS registró más de un evento de entrega dentro del periodo, se atribuye al usuario y la oficina del evento más reciente. La cantidad de eventos sí se muestra por separado. Las fechas se agrupan con zona horaria America/La_Paz.</span></footer>
    @endif
</main>
@stop

@section('js')
<script>
document.querySelectorAll('[data-delivery-range]').forEach(function (button) {
    button.addEventListener('click', function () {
        const form = document.getElementById('delivery-filter-form');
        const parts = form.dataset.today.split('-').map(Number);
        const end = new Date(Date.UTC(parts[0], parts[1] - 1, parts[2], 12));
        const start = new Date(end);
        if (button.dataset.deliveryRange === '7') start.setUTCDate(end.getUTCDate() - 6);
        if (button.dataset.deliveryRange === 'month') start.setUTCDate(1);
        form.elements.desde.value = start.toISOString().slice(0, 10);
        form.elements.hasta.value = end.toISOString().slice(0, 10);
        form.submit();
    });
});
</script>
@stop
