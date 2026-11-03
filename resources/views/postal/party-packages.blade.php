@extends('adminlte::page')

@section('title', 'Paquetes por persona | SITRA')

@section('content')
<div class="postal-workspace postal-party-search">
    <div class="postal-heading">
        <div>
            <span class="postal-eyebrow">CONSULTA IPS</span>
            <h1>Paquetes por remitente o destinatario</h1>
            <p>Busca por el nombre registrado y revisa los envíos asociados.</p>
        </div>
    </div>

    <form class="postal-search postal-party-search-form" action="{{ route('postal.party-packages') }}" method="get">
        <div class="postal-party-filter-grid">
            <div>
                <label for="party-sender">Nombre del remitente</label>
                <input class="form-control" id="party-sender" name="remitente" value="{{ $filters['remitente'] }}" maxlength="80" minlength="2" autocomplete="off" placeholder="Ej. María Pérez">
            </div>
            <div>
                <label for="party-recipient">Nombre del destinatario</label>
                <input class="form-control" id="party-recipient" name="destinatario" value="{{ $filters['destinatario'] }}" maxlength="80" minlength="2" autocomplete="off" placeholder="Ej. Juan López">
            </div>
            <div>
                <label for="party-page-size">Resultados por página</label>
                <select class="form-control" id="party-page-size" name="por_pagina">
                    @foreach([25, 50, 100] as $pageSize)
                        <option value="{{ $pageSize }}" @selected($filters['por_pagina'] === $pageSize)>{{ $pageSize }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="postal-party-search-actions">
            <span>Escribe al menos dos letras. Si completas los dos nombres, ambos deben coincidir en el mismo paquete.</span>
            <div>
                <a class="btn btn-light" href="{{ route('postal.party-packages') }}"><i class="fas fa-undo"></i> Limpiar</a>
                <button class="btn btn-primary"><i class="fas fa-search"></i> Buscar paquetes</button>
            </div>
        </div>
        @if($errors->any())
            <div class="alert alert-warning mt-3 mb-0">{{ $errors->first() }}</div>
        @endif
    </form>

    @if($error)
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> {{ $error }}</div>
    @elseif($searched && $results)
        <section class="postal-panel postal-party-summary" aria-label="Resumen de resultados">
            <div><span class="postal-task-icon"><i class="fas fa-boxes"></i></span><strong>{{ number_format($results->total()) }}</strong><span>paquetes encontrados</span></div>
            <span>Ordenados por fecha de admisión, correspondiente al primer evento registrado en IPS.</span>
        </section>

        <section class="postal-panel">
            <div class="postal-section-heading">
                <h2><i class="fas fa-list-alt"></i> Paquetes asociados</h2>
                <span>{{ $results->firstItem() ?? 0 }}–{{ $results->lastItem() ?? 0 }} de {{ number_format($results->total()) }}</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover postal-party-table">
                    <thead>
                        <tr><th>Paquete</th><th>Remitente</th><th>Destinatario</th><th>Origen</th><th>Destino</th><th>Estado IPS</th><th>Fecha de admisión</th></tr>
                    </thead>
                    <tbody>
                    @forelse($results as $package)
                        @php($packageCode = trim((string) ($package->MAILITM_FID ?: $package->MAILITM_LOCAL_ID)))
                        @php($admissionTime = \App\Support\IpsEventTime::present($package->ADMISSION_GMT_DT ?? null, $package->ADMISSION_LOCAL_OFFSET ?? null))
                        @php($admissionTimestamp = $admissionTime['event_at_local'] ?? $admissionTime['event_at_utc'] ?? null)
                        <tr>
                            <td>
                                @if($packageCode !== '')
                                    <a class="postal-report-code" href="{{ route('postal.combined', ['codigo' => $packageCode]) }}">{{ $package->MAILITM_FID ?: $package->MAILITM_LOCAL_ID }}</a>
                                @else
                                    Sin código registrado
                                @endif
                                @if($package->MAILITM_FID && $package->MAILITM_LOCAL_ID)
                                    <small class="d-block text-muted">ID local: {{ $package->MAILITM_LOCAL_ID }}</small>
                                @endif
                                <small class="d-block text-muted">{{ $package->MAIL_CLASS_NM ?: $package->MAIL_CLASS_CD ?: 'Clase no informada' }}</small>
                            </td>
                            <td>{{ $package->SENDER_NAME ?: 'Sin nombre registrado' }}</td>
                            <td>{{ $package->RECIPIENT_NAME ?: 'Sin nombre registrado' }}</td>
                            <td>{{ $package->ORIG_COUNTRY_NM ?: $package->ORIG_COUNTRY_CD ?: 'No informado' }}</td>
                            <td>{{ $package->DEST_COUNTRY_NM ?: $package->DEST_COUNTRY_CD ?: 'No informado' }}</td>
                            <td>{{ $package->POSTAL_STATUS_NM ?: 'Estado no informado' }}</td>
                            <td>
                                @if($admissionTimestamp)
                                    {{ \Carbon\Carbon::parse($admissionTimestamp)->format('d/m/Y H:i') }}
                                    @if(empty($admissionTime['event_at_local']))<small class="d-block text-muted">UTC · IPS</small>@endif
                                @else
                                    Fecha de admisión no informada
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><div class="postal-empty postal-empty-compact"><i class="fas fa-search"></i><h2>No encontramos paquetes asociados</h2><p>Prueba con otra parte del nombre o revisa la escritura registrada en IPS.</p></div></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if($results->hasPages())
                <div class="postal-party-pagination">{{ $results->links() }}</div>
            @endif
        </section>
    @else
        <section class="postal-panel postal-party-empty">
            <span class="postal-task-icon"><i class="fas fa-user-tag"></i></span>
            <div><h2>Busca los envíos de una persona</h2><p>Ingresa el nombre del remitente, del destinatario o ambos para ver sus paquetes asociados en IPS.</p></div>
        </section>
    @endif
</div>
@stop

@section('css')
    <link rel="stylesheet" href="{{ asset('css/postal-operations.css') }}">
    <link rel="stylesheet" href="{{ asset('css/postal-party-search.css') }}">
@stop
