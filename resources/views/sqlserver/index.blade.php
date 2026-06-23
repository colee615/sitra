@extends('adminlte::page')

@section('title', 'Tracking IPS')

@section('content_header')
    @php
        $mainPackage = $packageRows[0] ?? null;
        $latestEvent = $trackingRows[0] ?? null;
        $sender = collect($customerRows)->firstWhere('SENDER_PAYEE_IND', 'S');
        $recipient = collect($customerRows)->firstWhere('SENDER_PAYEE_IND', 'P')
            ?? collect($customerRows)->firstWhere('SENDER_PAYEE_IND', 'R')
            ?? collect($customerRows)->firstWhere('SENDER_PAYEE_IND', 'A');
        $hasResults = collect([
            $packageRows ?? collect(),
            $trackingRows ?? collect(),
            $deliveryRows ?? collect(),
            $logisticRows ?? collect(),
            $manifestRows ?? collect(),
            $ediRows ?? collect(),
        ])->contains(fn ($rows) => count($rows) > 0);
        $latestStatus = trim((string) ($mainPackage->POSTAL_STATUS_NM ?? 'Sin estado postal'));
        $latestEventName = trim((string) ($latestEvent->EVENT_TYPE_NM_ES ?? $mainPackage->EVT_TYPE_NM_ES ?? 'Sin evento'));
        $origin = trim((string) ($mainPackage->ORIG_COUNTRY_NM ?? $mainPackage->ORIG_COUNTRY_CD ?? '-'));
        $destination = trim((string) ($mainPackage->DEST_COUNTRY_NM ?? $mainPackage->DEST_COUNTRY_CD ?? '-'));
        $latestOffice = $latestEvent
            ? trim(implode(' - ', array_filter([
                $latestEvent->OFFICE_FCD ?? '',
                $latestEvent->OFFICE_NM ?? '',
            ])))
            : trim(implode(' - ', array_filter([
                $mainPackage->EVT_OFFICE_FCD ?? '',
                $mainPackage->EVT_OFFICE_NM ?? '',
            ])));
        $highlightEvents = collect($trackingRows)->take(8);
        $eventSources = collect($trackingRows)->groupBy(fn ($row) => $row->SOURCE_DB ?: 'IPS5Db');
        $ediCount = count($eventSources->get('IPS5Db-EDI', []));
        $indirectCount = collect($eventSources)->except(['IPS5Db', 'IPS5Db-EDI'])->sum(fn ($rows) => count($rows));
    @endphp

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center">
        <div>
            <span class="workspace-kicker">
                <i class="fas fa-database"></i>
                IPS5Db
            </span>
            <h1 class="workspace-title">Tracking operativo interno</h1>
            <p class="workspace-subtitle mb-0">
                Consulta la trazabilidad postal usando IPS como fuente operativa. Esta vista interna no altera la API
                externa que consume el otro proyecto.
            </p>
        </div>
        <a href="{{ route('dashboard') }}" class="btn btn-outline-secondary mt-3 mt-md-0">
            <i class="fas fa-arrow-left mr-1"></i> Volver al centro de consultas
        </a>
    </div>
@stop

@section('content')
    <section class="search-panel mb-4">
        <div class="search-panel__top">
            <div>
                <h2 class="search-panel__title">Buscar envío en IPS</h2>
                <p class="search-panel__subtitle">
                    Admite código S10 o código local. La consulta reúne resumen del paquete, recorrido, logística,
                    entrega y trazas EDI asociadas.
                </p>
            </div>
            <div>
                @if($error)
                    <span class="badge badge-danger px-3 py-2">Error de conexión</span>
                @else
                    <span class="badge badge-success px-3 py-2">Solo lectura / conexión activa</span>
                @endif
            </div>
        </div>

        @if($error)
            <div class="alert alert-danger mb-3">
                <strong>Error IPS:</strong> {{ $error }}
            </div>
        @endif

        <form method="GET" action="{{ route('sqlserver.datos') }}">
            <div class="input-group input-group-lg">
                <input
                    type="text"
                    name="codigo"
                    value="{{ $codigo }}"
                    class="form-control"
                    placeholder="Ej: RA931985256US o código local"
                    autocomplete="off">
                <div class="input-group-append">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search mr-1"></i> Consultar
                    </button>
                    <a href="{{ route('sqlserver.datos') }}" class="btn btn-default">Limpiar</a>
                </div>
            </div>
        </form>
    </section>

    @if($codigo !== '' && !$hasResults && !$error)
        <section class="empty-state">
            <h3>Sin resultados para {{ $codigo }}</h3>
            <p>No se encontraron registros en IPS5Db con ese identificador.</p>
        </section>
    @endif

    @if($hasResults)
        <section class="summary-banner mb-4">
            <div class="summary-banner__meta">
                <span class="summary-banner__chip"><i class="fas fa-barcode"></i> {{ $mainPackage->MAILITM_FID ?? $codigo }}</span>
                @if(!empty($mainPackage?->MAILITM_LOCAL_ID))
                    <span class="summary-banner__chip"><i class="fas fa-fingerprint"></i> {{ $mainPackage->MAILITM_LOCAL_ID }}</span>
                @endif
                @if(!empty($mainPackage?->MAIL_CLASS_NM))
                    <span class="summary-banner__chip"><i class="fas fa-tag"></i> {{ $mainPackage->MAIL_CLASS_NM }}</span>
                @endif
            </div>
            <h2 class="summary-banner__title">{{ $latestEventName }}</h2>
            <p class="summary-banner__subtitle">
                Estado postal: {{ $latestStatus }}.
                Origen: {{ $origin }}.
                Destino: {{ $destination }}.
                @if($latestOffice !== '')
                    Oficina de referencia: {{ $latestOffice }}.
                @endif
            </p>
        </section>

        <section class="stat-grid mb-4">
            <div class="stat-card">
                <span class="stat-card__label">Eventos</span>
                <span class="stat-card__value">{{ count($trackingRows) }}</span>
            </div>
            <div class="stat-card">
                <span class="stat-card__label">Entrega</span>
                <span class="stat-card__value">{{ count($deliveryRows) }}</span>
            </div>
            <div class="stat-card">
                <span class="stat-card__label">EDI</span>
                <span class="stat-card__value">{{ $ediCount }}</span>
            </div>
            <div class="stat-card">
                <span class="stat-card__label">Indirectos</span>
                <span class="stat-card__value">{{ $indirectCount }}</span>
            </div>
            <div class="stat-card">
                <span class="stat-card__label">Sacas / despachos</span>
                <span class="stat-card__value">{{ count($logisticRows) }}</span>
            </div>
            <div class="stat-card">
                <span class="stat-card__label">Manifiestos</span>
                <span class="stat-card__value">{{ count($manifestRows) }}</span>
            </div>
        </section>

        <div class="two-up mb-4">
            <section class="panel-card">
                <h3>Ficha del envío</h3>
                <div class="kv-list">
                    <div class="kv-item">
                        <span class="kv-item__label">Código consultado</span>
                        <strong>{{ $codigo }}</strong>
                    </div>
                    <div class="kv-item">
                        <span class="kv-item__label">Origen / destino</span>
                        <strong>{{ $origin }} -> {{ $destination }}</strong>
                    </div>
                    <div class="kv-item">
                        <span class="kv-item__label">Peso / valor</span>
                        <strong>{{ $mainPackage->MAILITM_WEIGHT ?? '-' }} / {{ $mainPackage->MAILITM_VALUE ?? '-' }}</strong>
                    </div>
                    <div class="kv-item">
                        <span class="kv-item__label">Último evento</span>
                        <strong>{{ $latestEventName }}</strong>
                    </div>
                </div>
            </section>

            <section class="panel-card">
                <h3>Partes involucradas</h3>
                <div class="kv-list">
                    <div class="kv-item">
                        <span class="kv-item__label">Remitente</span>
                        <strong>{{ $sender->CUSTOMER_NAME ?? '-' }}</strong>
                        <div>{{ $sender->CUSTOMER_ADDRESS ?? '-' }} {{ $sender->CUSTOMER_CITY ?? '' }}</div>
                    </div>
                    <div class="kv-item">
                        <span class="kv-item__label">Destinatario</span>
                        <strong>{{ $recipient->CUSTOMER_NAME ?? '-' }}</strong>
                        <div>{{ $recipient->CUSTOMER_ADDRESS ?? '-' }} {{ $recipient->CUSTOMER_CITY ?? '' }}</div>
                    </div>
                </div>
            </section>
        </div>

        <section class="panel-card mb-4">
            <h3>Lectura rápida del recorrido</h3>
            <div class="timeline-stack">
                @foreach($highlightEvents as $event)
                    <article class="timeline-event">
                        <div class="timeline-event__time">{{ $event->EVENT_GMT_DT ?? '-' }}</div>
                        <div>
                            <h4 class="timeline-event__title">{{ $event->EVENT_TYPE_NM_ES ?: ('Evento #' . ($event->EVENT_TYPE_CD ?? '-')) }}</h4>
                            <div class="timeline-event__meta">
                                Oficina:
                                {{ trim(implode(' - ', array_filter([$event->OFFICE_FCD ?? '', $event->OFFICE_NM ?? '']))) ?: '-' }}
                                @if(!empty($event->SOURCE_DB))
                                    | Fuente: {{ $event->SOURCE_DB }}
                                @endif
                                @if(!empty($event->DETAIL_TXT))
                                    | {{ $event->DETAIL_TXT }}
                                @endif
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>

        <div class="two-up mb-4">
            <section class="panel-card">
                <h3>Logística: saca y despacho</h3>
                <div class="table-wrap">
                    <table class="table table-sm table-striped mb-0">
                        <thead>
                            <tr>
                                <th>Saca</th>
                                <th>Despacho</th>
                                <th>Origen</th>
                                <th>Destino</th>
                                <th>Salida</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($logisticRows as $row)
                                <tr>
                                    <td>{{ $row->RECPTCL_FID ?: '-' }}</td>
                                    <td>{{ $row->DESPTCH_FID ?: '-' }}</td>
                                    <td>{{ $row->ORIG_OFFICE_FCD ?: '-' }}</td>
                                    <td>{{ $row->DEST_OFFICE_FCD ?: '-' }}</td>
                                    <td>{{ $row->DESPTCH_DEPARTURE_DT ?: '-' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center text-muted">Sin vínculos logísticos.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="panel-card">
                <h3>Intercambio EDI</h3>
                <div class="table-wrap">
                    <table class="table table-sm table-striped mb-0">
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Evento</th>
                                <th>Sender</th>
                                <th>Despacho</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($ediRows as $row)
                                <tr>
                                    <td>{{ $row->CAPTURE_GMT_DT ?: ($row->EVENT_LOCAL_DT ?: '-') }}</td>
                                    <td>{{ $row->EVENT_TYPE_NM_ES ?: ('Evento #' . ($row->EVENT_TYPE_CD ?? '-')) }}</td>
                                    <td>{{ $row->SENDER_ID ?: '-' }}</td>
                                    <td>{{ $row->DESPATCH_NUMBER ?: '-' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center text-muted">Sin eventos EDI para este código.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <section class="panel-card mb-4">
            <h3>Recorrido completo</h3>
            <div class="table-wrap">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Evento</th>
                            <th>Oficina</th>
                            <th>Siguiente oficina</th>
                            <th>Detalle</th>
                            <th>Fuente</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($trackingRows as $row)
                            <tr>
                                <td>{{ $row->EVENT_GMT_DT ?? '-' }}</td>
                                <td>{{ $row->EVENT_TYPE_NM_ES ?: ('Evento #' . ($row->EVENT_TYPE_CD ?? '-')) }}</td>
                                <td>{{ trim(implode(' - ', array_filter([$row->OFFICE_FCD ?? '', $row->OFFICE_NM ?? '']))) ?: '-' }}</td>
                                <td>{{ trim(implode(' - ', array_filter([$row->NEXT_OFFICE_FCD ?? '', $row->NEXT_OFFICE_NM ?? '']))) ?: '-' }}</td>
                                <td>{{ $row->DETAIL_TXT ?: '-' }}</td>
                                <td>{{ $row->SOURCE_DB ?: 'IPS5Db' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted">Sin recorrido disponible.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endif
@stop
