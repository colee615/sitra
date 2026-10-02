@extends('adminlte::page')

@section('title', 'Actividad postal | SITRA')

@section('content_header')
    <div class="postal-heading">
        <div>
            <span class="postal-eyebrow">SITRA · OPERACIONES</span>
            <h1>Actividad postal por oficina</h1>
            <p>Consulta los últimos movimientos IPS de los paquetes dentro del periodo elegido.</p>
        </div>
        <span class="postal-source"><i class="fas fa-database" aria-hidden="true"></i> Fuente: IPS</span>
    </div>
@stop

@section('content')
<div class="postal-workspace postal-activity-report">
    <form class="postal-search postal-report-filters" action="{{ route('postal.operations') }}" method="get">
        <div class="postal-report-filter-grid">
            <div><label for="report-from">Desde</label><input class="form-control" id="report-from" type="date" name="desde" value="{{ $filters['from']->format('Y-m-d') }}" required></div>
            <div><label for="report-to">Hasta</label><input class="form-control" id="report-to" type="date" name="hasta" value="{{ $filters['to']->format('Y-m-d') }}" required></div>
            <div><label for="report-office">Oficina del movimiento @if($result)<small class="text-muted">· {{ number_format($result['offices']->count()) }} vigentes en IPS</small>@endif</label><select class="form-control" id="report-office" name="oficina"><option value="">Todas las oficinas</option>@if($result)@foreach($result['offices'] as $office)<option value="{{ $office->code }}" @selected((string)$filters['office'] === (string)$office->code)>{{ $office->short_name ?: $office->code }} · {{ $office->name ?: 'Nombre no registrado' }}</option>@endforeach@endif</select><small class="form-text text-muted">Incluye oficinas activas aunque no tengan movimientos en este periodo.</small></div>
            <div><label for="report-event">Código de evento (opcional)</label><input class="form-control" id="report-event" name="evento" value="{{ $filters['event'] }}" maxlength="15" placeholder="Ej. 32"></div>
            <div class="postal-report-search"><label for="report-search">Buscar paquete o movimiento</label><input class="form-control" id="report-search" name="buscar" value="{{ $filters['search'] }}" maxlength="60" placeholder="Código, oficina o descripción"></div>
        </div>
        <div class="postal-report-actions"><span>Máximo {{ config('postal.report_max_days', 31) }} días por consulta para mantener el reporte ágil.</span><div><a class="btn btn-light" href="{{ route('postal.operations') }}"><i class="fas fa-undo"></i> Limpiar</a><button class="btn btn-primary"><i class="fas fa-filter"></i> Aplicar filtros</button></div></div>
        @if($errors->any())<div class="alert alert-warning mt-3 mb-0">{{ $errors->first() }}</div>@endif
    </form>

    @if($error)
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> {{ $error }}</div>
    @elseif($result)
        <section class="postal-panel postal-report-summary" aria-label="Resumen del reporte">
            <div><span class="postal-task-icon"><i class="fas fa-boxes"></i></span><strong>{{ number_format($result['total']) }}</strong><span>paquetes con movimiento en el periodo</span></div>
            <div><span class="postal-task-icon teal"><i class="fas fa-calendar-alt"></i></span><strong>{{ $filters['from']->format('d/m/Y') }} – {{ $filters['to']->format('d/m/Y') }}</strong><span>fechas seleccionadas · hora local Bolivia</span></div>
            <a class="btn btn-outline-primary" href="{{ $exportUrl }}"><i class="fas fa-file-csv"></i> Descargar CSV</a>
        </section>

        @if($result['truncated'])
            <div class="alert alert-warning"><i class="fas fa-info-circle"></i> Se muestran los primeros 1.000 paquetes. Reduce el periodo o agrega filtros para revisar los demás.</div>
        @endif
        <section class="postal-panel">
            <div class="postal-section-heading"><h2><i class="fas fa-list-alt"></i> Movimientos encontrados</h2><span>Una fila por paquete · último movimiento dentro del rango</span></div>
            <p class="postal-note">La fecha del evento se guarda en GMT/UTC; el resumen del periodo usa el horario de Bolivia. Este reporte refleja lo registrado en IPS, no confirma por sí solo la ubicación física actual ni un plazo oficial de entrega.</p>
            <div class="table-responsive">
                <table class="table table-hover postal-activity-table">
                    <thead><tr><th>Fecha (GMT)</th><th>Paquete</th><th>Movimiento IPS</th><th>Estado actual</th><th>Oficina → siguiente</th><th>Saca</th><th>Registró</th></tr></thead>
                    <tbody>
                    @forelse($result['rows'] as $row)
                        <tr>
                            <td><time datetime="{{ $row->EVENT_GMT_DT }}">{{ $row->EVENT_GMT_DT }}<small class="d-block text-muted">Evento registrado en IPS</small></time></td>
                            <td><a class="postal-report-code" href="{{ route('postal.ips', ['codigo'=>$row->MAILITM_FID ?: $row->MAILITM_LOCAL_ID]) }}">{{ $row->MAILITM_FID ?: 'Sin código S10' }}</a>@if($row->MAILITM_LOCAL_ID)<small class="d-block text-muted">ID local: {{ $row->MAILITM_LOCAL_ID }}</small>@endif</td>
                            <td><strong>{{ $row->EVENT_NAME ?: 'Movimiento sin descripción en catálogo' }}</strong><small class="d-block text-muted">Código IPS: {{ $row->EVENT_TYPE_CD }}</small></td>
                            <td>{{ $row->POSTAL_STATUS_NM ?: 'Estado no descrito por IPS' }}<small class="d-block text-muted">{{ $row->MAIL_CLASS_NM ?: $row->MAIL_CLASS_CD ?: 'Clase no informada' }}</small></td>
                            <td>{{ trim(($row->OFFICE_FCD ?? '').' '.($row->OFFICE_NM ?? '')) ?: 'Oficina no informada' }}<small class="d-block text-muted">Siguiente: {{ trim(($row->NEXT_OFFICE_FCD ?? '').' '.($row->NEXT_OFFICE_NM ?? '')) ?: 'IPS no informó siguiente oficina' }}</small></td>
                            <td>{{ $row->RECPTCL_FID ?: 'Sin vínculo encontrado' }}</td>
                            <td>{{ $row->USER_NM ?: $row->USER_FID ?: 'IPS no informó responsable' }}@if($row->RETENTION_REASON_CD)<small class="d-block text-warning">Retención registrada (código {{ $row->RETENTION_REASON_CD }})</small>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><div class="postal-empty postal-empty-compact"><i class="fas fa-search"></i><h2>No hay movimientos con esos filtros</h2><p>Amplía las fechas o quita el filtro de oficina, código de evento o búsqueda.</p></div></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</div>
@stop

@section('css')
    <link rel="stylesheet" href="{{ asset('css/postal-operations.css') }}">
@stop
