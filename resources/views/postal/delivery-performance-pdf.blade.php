<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Rendimiento de entregas IPS · SITRA</title>
    <style>
        @page{margin:28px 30px 42px}
        *{box-sizing:border-box}
        body{font-family:DejaVu Sans,sans-serif;color:#152f4e;font-size:9px;line-height:1.4;margin:0}
        .brand{display:flex;align-items:center;gap:18px;background:#082b52;color:#fff;padding:18px 20px;border-radius:8px;border-bottom:5px solid #ffce32}
        .brand img{display:block;width:112px;max-height:60px;object-fit:contain;border-radius:3px}
        .brand-copy{flex:1}
        .brand-top{font-size:8px;font-weight:bold;letter-spacing:1.7px;color:#a9c2dc;text-transform:uppercase}
        h1{font-size:24px;line-height:1.12;margin:7px 0 4px;color:#fff}
        .subtitle{font-size:10px;color:#d6e3f0;margin:0}
        .meta{margin-top:14px;padding:10px 13px;background:#f2f6fa;border:1px solid #dbe5ee;border-radius:6px}
        .meta span{display:inline-block;margin-right:22px;color:#55708b}.meta strong{color:#12375d}
        .section-title{margin:20px 0 9px;font-size:13px;color:#082b52;border-left:4px solid #ffce32;padding-left:8px}
        .kpis{width:100%;border-spacing:7px 0;margin:0 -7px 4px}
        .kpis td{width:20%;vertical-align:top;background:#f5f8fb;border:1px solid #dfe7ef;border-radius:7px;padding:11px 12px}
        .kpis .main{background:#fff6d8;border-color:#f1d471}
        .kpi-label{font-size:7px;color:#627891;text-transform:uppercase;font-weight:bold;letter-spacing:.5px}
        .kpi-value{display:block;font-size:21px;font-weight:bold;color:#123b69;margin:3px 0}
        .kpi-note{font-size:7px;color:#6d8094}
        .insight{margin:10px 0;padding:9px 11px;background:#edf5f8;border-left:3px solid #0e8991;color:#42627b}
        .grid{width:100%;border-spacing:8px 0;margin:0 -8px}
        .grid>tbody>tr>td{width:50%;vertical-align:top;padding:0}
        .panel{border:1px solid #dbe4ec;border-radius:7px;padding:11px 12px;margin-bottom:9px}
        .panel h2{font-size:11px;color:#123b69;margin:0 0 3px}
        .panel p{font-size:7px;color:#74869a;margin:0 0 10px}
        table.data{width:100%;border-collapse:collapse}
        table.data thead{display:table-header-group}
        table.data th{font-size:7px;color:#526982;text-align:left;text-transform:uppercase;letter-spacing:.35px;background:#f0f4f8;padding:6px 7px}
        table.data td{font-size:8px;padding:6px 7px;border-bottom:1px solid #e7edf2;vertical-align:top}
        table.data tr{page-break-inside:avoid}
        .num{text-align:right;font-weight:bold;color:#123b69;white-space:nowrap}
        .rank{color:#0e8991;font-weight:bold;width:26px}
        .barline{display:inline-block;width:95px;height:5px;background:#e8eef3;border-radius:5px;vertical-align:middle;margin-right:6px}
        .barline i{display:block;height:5px;background:#0e8991;border-radius:5px}
        .trend-table{width:100%;border-collapse:collapse;margin-top:8px}
        .trend-table td{width:{{ count($result['trend']) ? round(100/count($result['trend']), 3) : 3.2 }}%;text-align:center;vertical-align:bottom;padding:2px 1px;color:#6b7e91;font-size:6px}
        .trend-value{display:block;color:#123b69;font-size:6px;height:10px}
        .bar-slot{height:88px;background:#f4f7fa;border-radius:3px 3px 0 0;position:relative;vertical-align:bottom}
        .bar-slot i{display:block;position:absolute;bottom:0;left:0;right:0;background:#0c8992;border-radius:3px 3px 0 0}
        .note{font-size:8px;color:#526a81;background:#f7f9fb;border:1px solid #e2e9ef;padding:9px 11px;border-radius:5px;margin-top:12px}
        .warning{color:#765a18;background:#fff8e1;border-color:#f1dfaa}
        .detail{margin-top:20px}
        .detail th{background:#082b52!important;color:#fff!important}
        footer{position:fixed;bottom:-25px;left:0;right:0;border-top:1px solid #dbe4ec;padding-top:6px;font-size:7px;color:#7b8ca0}
        .footer-right{float:right}
    </style>
</head>
<body>
    @php
        $formatNumber = static fn ($value, $decimals = 0) => number_format((float) $value, $decimals, ',', '.');
    @endphp
    <header class="brand">
        <img src="{{ public_path('images/correos-bolivia.png') }}" alt="Correos de Bolivia">
        <div class="brand-copy">
            <div class="brand-top">SITRA · Correos de Bolivia · Inteligencia postal IPS · Operador BOA</div>
            <h1>Rendimiento de entregas</h1>
            <p class="subtitle">Informe ejecutivo por periodo, oficina y usuario que registró el evento.</p>
        </div>
    </header>

    <div class="meta">
        <span><strong>Periodo:</strong> {{ $filters['from']->format('d/m/Y') }} al {{ $filters['to']->format('d/m/Y') }} · hora de Bolivia</span>
        <span><strong>Oficina:</strong> @if($filters['office']){{ optional($catalog['offices']->firstWhere('code', $filters['office']))->name ?: optional($catalog['offices']->firstWhere('code', $filters['office']))->short_name ?: 'Código '.$filters['office'] }}@else Todas las oficinas @endif</span>
        <span><strong>Servicio:</strong> @if($filters['service']){{ optional($catalog['services']->firstWhere('code', $filters['service']))->name ?: $filters['service'] }}@else Todos los servicios @endif</span>
        @if($filters['user'])<span><strong>Usuario IPS:</strong> {{ $filters['user'] }}</span>@endif
        <span><strong>Preparado:</strong> {{ $result['generated_at']->format('d/m/Y H:i') }} por {{ $preparedBy }}</span>
    </div>

    <h2 class="section-title">Resumen ejecutivo</h2>
    <table class="kpis"><tbody><tr>
        <td class="main"><span class="kpi-label">Envíos entregados únicos</span><strong class="kpi-value">{{ $formatNumber($result['totals']['delivered']) }}</strong><span class="kpi-note">Conteo sin duplicar envíos</span></td>
        <td><span class="kpi-label">Eventos de entrega</span><strong class="kpi-value">{{ $formatNumber($result['totals']['delivery_events']) }}</strong><span class="kpi-note">Registros IPS · códigos 37 y 1250</span></td>
        <td><span class="kpi-label">Usuarios registradores</span><strong class="kpi-value">{{ $formatNumber($result['totals']['registrars']) }}</strong><span class="kpi-note">Cuentas distintas identificadas</span></td>
        <td><span class="kpi-label">Oficinas activas</span><strong class="kpi-value">{{ $formatNumber($result['totals']['offices']) }}</strong><span class="kpi-note">Con al menos una entrega</span></td>
        <td><span class="kpi-label">Promedio diario</span><strong class="kpi-value">{{ $formatNumber($result['totals']['daily_average'], 1) }}</strong><span class="kpi-note">Por día calendario seleccionado</span></td>
    </tr></tbody></table>
    <div class="insight"><strong>Mayor actividad:</strong> {{ $result['totals']['top_registrar'] }} registró {{ $formatNumber($result['totals']['top_registrar_total']) }} envíos únicos en los filtros seleccionados.</div>

    <table class="grid"><tbody><tr>
        <td><section class="panel"><h2>Tendencia {{ $result['trend_grain'] === 'mes' ? 'mensual' : 'diaria' }} de entregas</h2><p>Envíos únicos agrupados por {{ $result['trend_grain'] }} local de Bolivia</p>
            <table class="trend-table"><tbody><tr>@foreach($result['trend'] as $point)<td><span class="trend-value">{{ $point['delivered'] ?: '' }}</span><div class="bar-slot"><i style="height: {{ $point['height'] }}%"></i></div>{{ $point['label'] }}</td>@endforeach</tr></tbody></table>
        </section></td>
        <td><section class="panel"><h2>Usuarios que más registraron</h2><p>Una fila por usuario IPS · envíos atribuidos al último evento del periodo</p>
            @php($userMax = max(1, (int) collect($result['users'])->max('delivered')))
            <table class="data"><thead><tr><th>#</th><th>Usuario registrador</th><th>Oficinas</th><th class="num">Envíos</th></tr></thead><tbody>
            @forelse($result['users'] as $actor)<tr><td class="rank">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</td><td>{{ $actor->USER_NM ?: $actor->USER_FID ?: 'Usuario no informado por IPS' }}@if($actor->USER_FID)<br><span style="color:#8191a2;font-size:7px">{{ $actor->USER_FID }}</span>@endif</td><td>{{ $formatNumber((int) $actor->offices) }}</td><td class="num"><span class="barline"><i style="width:{{ min(100, round(((int) $actor->delivered / $userMax) * 100)) }}%"></i></span>{{ $formatNumber((int) $actor->delivered) }}</td></tr>@empty<tr><td colspan="4">Sin registros de entrega para el periodo.</td></tr>@endforelse
            </tbody></table>
        </section></td>
    </tr><tr>
        <td><section class="panel"><h2>Entregas por oficina</h2><p>Oficina informada en el evento de entrega IPS</p>
            <table class="data"><thead><tr><th>Oficina</th><th>Usuarios</th><th class="num">Envíos</th></tr></thead><tbody>
            @forelse($result['offices_ranking'] as $office)<tr><td>{{ trim(($office->OFFICE_FCD ?? '').' '.($office->OFFICE_NM ?? '')) ?: 'Oficina no informada' }}</td><td>{{ $formatNumber((int) $office->registrars) }}</td><td class="num">{{ $formatNumber((int) $office->delivered) }}</td></tr>@empty<tr><td colspan="3">Sin oficinas con entregas.</td></tr>@endforelse
            </tbody></table>
        </section></td>
        <td><section class="panel"><h2>Composición por servicio</h2><p>Envíos únicos entregados por clase postal</p>
            <table class="data"><thead><tr><th>Clase postal</th><th class="num">Envíos</th></tr></thead><tbody>
            @forelse($result['services'] as $service)<tr><td>{{ $service->MAIL_CLASS_NM ?: $service->MAIL_CLASS_CD ?: 'Clase no informada' }}</td><td class="num">{{ $formatNumber((int) $service->delivered) }}</td></tr>@empty<tr><td colspan="2">Sin clases postales.</td></tr>@endforelse
            </tbody></table>
        </section></td>
    </tr></tbody></table>

    <div class="note"><strong>Lectura del indicador:</strong> cada envío se cuenta una sola vez. Si aparece en más de un evento de entrega dentro del periodo, se atribuye al usuario y la oficina del evento más reciente. Los eventos se muestran como una métrica independiente. “Registrado por” identifica la cuenta de usuario que guardó el evento en IPS y no certifica por sí sola la identidad del cartero.</div>

    <div class="detail">
        <h2 class="section-title">Detalle de envíos entregados</h2>
        @if($result['detail_truncated'])<div class="note warning">Este PDF muestra {{ $formatNumber($result['detail_limit']) }} de {{ $formatNumber($result['detail_total']) }} envíos. El resumen y los rankings sí consideran el total del filtro; descargue el CSV para obtener la lista completa.</div>@endif
        <table class="data"><thead><tr><th>#</th><th>Fecha y hora local</th><th>Código del envío</th><th>Evento IPS</th><th>Oficina</th><th>Registrado por</th><th>Clase postal</th></tr></thead><tbody>
        @forelse($result['rows'] as $row)
            @php($localTime = \Carbon\CarbonImmutable::parse($row->EVENT_GMT_DT, 'UTC')->setTimezone($filters['timezone']))
            <tr><td>{{ $loop->iteration }}</td><td>{{ $localTime->format('d/m/Y H:i') }}</td><td><strong>{{ $row->MAILITM_FID ?: 'No informado' }}</strong>@if($row->MAILITM_LOCAL_ID)<br><span style="color:#8191a2">ID local: {{ $row->MAILITM_LOCAL_ID }}</span>@endif</td><td>{{ $row->EVENT_NAME ?: 'Evento de entrega IPS' }}<br><span style="color:#8191a2">Código {{ $row->EVENT_TYPE_CD }}</span></td><td>{{ trim(($row->OFFICE_FCD ?? '').' '.($row->OFFICE_NM ?? '')) ?: 'Oficina no informada' }}</td><td>{{ $row->USER_NM ?: $row->USER_FID ?: 'Usuario no informado por IPS' }}@if($row->USER_FID)<br><span style="color:#8191a2">{{ $row->USER_FID }}</span>@endif</td><td>{{ $row->MAIL_CLASS_NM ?: $row->MAIL_CLASS_CD ?: 'No informada' }}</td></tr>
        @empty<tr><td colspan="7">No se encontraron entregas en este periodo.</td></tr>@endforelse
        </tbody></table>
    </div>
    <footer><span>SITRA · Reporte operativo IPS · Uso institucional · Fuente: L_MAILITM_EVENTS + L_MAILITMS</span><span class="footer-right">Generado {{ $result['generated_at']->format('d/m/Y H:i') }} · Bolivia</span></footer>
</body>
</html>
