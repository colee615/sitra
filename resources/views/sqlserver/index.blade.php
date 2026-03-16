@extends('adminlte::page')

@section('title', 'Rastreo IPS5')

@section('content_header')
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center">
        <div>
            <h1 class="m-0">Rastreo IPS5</h1>
            <small class="text-muted">Consulta operativa por codigo S10 o codigo local</small>
        </div>
        <a href="{{ route('dashboard') }}" class="btn btn-outline-secondary btn-sm mt-3 mt-md-0">
            <i class="fas fa-arrow-left mr-1"></i> Volver al Dashboard
        </a>
    </div>
@stop

@section('content')
    <div class="container-fluid">
        @php
            $mainPackage = $packageRows[0] ?? null;
            $latestEvent = $trackingRows[0] ?? null;
            $sender = collect($customerRows)->firstWhere('SENDER_PAYEE_IND', 'S');
            $recipient = collect($customerRows)->firstWhere('SENDER_PAYEE_IND', 'P') ?? collect($customerRows)->firstWhere('SENDER_PAYEE_IND', 'R');
            $latestDelivery = $deliveryRows[0] ?? null;
            $latestLogistic = $logisticRows[0] ?? null;
            $latestManifest = $manifestRows[0] ?? null;
            $latestEdi = $ediRows[0] ?? null;
            $hasResults = collect([$packageRows, $trackingRows, $customerRows, $deliveryRows, $logisticRows, $manifestRows, $ediRows])
                ->contains(fn ($rows) => count($rows) > 0);
            $latestStatus = trim((string) ($mainPackage->POSTAL_STATUS_NM ?? ''));
            $latestEventName = trim((string) ($latestEvent->EVENT_TYPE_NM_ES ?? $mainPackage->EVT_TYPE_NM_ES ?? 'Sin evento'));
            $latestOffice = $latestEvent
                ? trim(($latestEvent->OFFICE_FCD ? $latestEvent->OFFICE_FCD . ' - ' : '') . ($latestEvent->OFFICE_NM ?: ($latestEvent->EVENT_OFFICE_CD ?: '-')))
                : trim(($mainPackage && $mainPackage->EVT_OFFICE_FCD ? $mainPackage->EVT_OFFICE_FCD . ' - ' : '') . ($mainPackage->EVT_OFFICE_NM ?? '-'));
            $latestEventNameLower = strtolower($latestEventName);
            $delivered = str_contains($latestEventNameLower, 'entregado')
                || str_contains($latestEventNameLower, 'entregar envio')
                || str_contains($latestEventNameLower, 'entregar envío')
                || str_contains(strtolower($latestStatus), 'delivered')
                || (!empty($latestDelivery?->SIGNATORY_NM) && !empty($latestDelivery?->EVENT_GMT_DT));
            $hasDeliveryIssue = str_contains(strtolower($latestEventName), 'intento fallido')
                || str_contains(strtolower($latestEventName), 'retener')
                || !empty($latestDelivery?->NON_DELIVERY_REASON_CD);
            $inTransit = str_contains(strtolower($latestEventName), 'transit')
                || str_contains(strtolower($latestEventName), 'enviar')
                || str_contains(strtolower($latestStatus), 'transit');
            $heroClass = $delivered ? 'success' : ($hasDeliveryIssue ? 'warning' : ($inTransit ? 'info' : 'primary'));
            $heroLabel = $delivered ? 'Entregado / cierre operativo' : ($hasDeliveryIssue ? 'Requiere revision de entrega' : ($inTransit ? 'En curso / en movimiento' : 'Seguimiento disponible'));
            $sourceStats = collect($trackingRows)->groupBy(fn ($row) => $row->SOURCE_DB ?: 'IPS5Db');
            $localEvents = count($sourceStats->get('IPS5Db', []));
            $ediEvents = count($sourceStats->get('IPS5Db-EDI', []));
            $indirectEvents = collect($sourceStats)
                ->except(['IPS5Db', 'IPS5Db-EDI'])
                ->sum(fn ($rows) => count($rows));
            $highlightEvents = collect($trackingRows)
                ->filter(function ($row) {
                    $name = strtolower((string) ($row->EVENT_TYPE_NM_ES ?? ''));

                    return str_contains($name, 'recibir')
                        || str_contains($name, 'enviar')
                        || str_contains($name, 'entregar')
                        || str_contains($name, 'intento')
                        || str_contains($name, 'retener')
                        || str_contains($name, 'saca');
                })
                ->take(6)
                ->values();
        @endphp

        <div class="row">
            <div class="col-12">
                <div class="card card-primary card-outline search-shell">
                    <div class="card-body">
                        <div class="row align-items-end">
                            <div class="col-lg-8">
                                <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-start mb-3">
                                    <div>
                                        <span class="badge badge-primary px-3 py-2">Modo solo lectura</span>
                                        <h3 class="mt-3 mb-1">Consulta de paquete</h3>
                                        <p class="text-muted mb-0">Busca por codigo S10 o local y revisa rastreo, entrega, logistica y EDI en una sola vista.</p>
                                    </div>
                                    <div class="mt-3 mt-lg-0 text-lg-right">
                                        @if($error)
                                            <span class="badge badge-danger px-3 py-2">SQL Server con error</span>
                                        @else
                                            <span class="badge badge-success px-3 py-2">Conexion activa a IPS5Db</span>
                                        @endif
                                    </div>
                                </div>

                                @if($error)
                                    <div class="alert alert-danger mb-3">
                                        <strong>Error SQL Server:</strong> {{ $error }}
                                    </div>
                                @endif

                                <form method="GET" action="{{ route('sqlserver.datos') }}">
                                    <div class="input-group input-group-lg">
                                        <input type="text" name="codigo" value="{{ $codigo }}" class="form-control"
                                            placeholder="Ej: EE005479036BO o codigo local" autocomplete="off">
                                        <div class="input-group-append">
                                            <button type="submit" class="btn btn-success">
                                                <i class="fas fa-search mr-1"></i> Rastrear
                                            </button>
                                            <a href="{{ route('sqlserver.datos') }}" class="btn btn-default">Limpiar</a>
                                        </div>
                                    </div>
                                </form>

                                <div class="search-hints mt-3">
                                    <span class="mr-3"><i class="fas fa-barcode text-primary mr-1"></i> S10 internacional</span>
                                    <span class="mr-3"><i class="fas fa-fingerprint text-info mr-1"></i> Codigo local</span>
                                    <span><i class="fas fa-database text-success mr-1"></i> Sin cambios en base de datos</span>
                                </div>
                            </div>
                            <div class="col-lg-4 mt-4 mt-lg-0">
                                <div class="search-sidecard">
                                    <div class="metric">
                                        <span class="metric-label">Ultima consulta</span>
                                        <strong>{{ $codigo !== '' ? $codigo : 'Sin codigo cargado' }}</strong>
                                    </div>
                                        <div class="metric-grid">
                                        <div>
                                            <span class="metric-label">Base</span>
                                            <strong>{{ count($packageRows) }}</strong>
                                        </div>
                                        <div>
                                            <span class="metric-label">Eventos</span>
                                            <strong>{{ count($trackingRows) }}</strong>
                                        </div>
                                        <div>
                                            <span class="metric-label">Entrega</span>
                                            <strong>{{ count($deliveryRows) }}</strong>
                                        </div>
                                        <div>
                                            <span class="metric-label">EDI</span>
                                            <strong>{{ $ediEvents }}</strong>
                                        </div>
                                        <div>
                                            <span class="metric-label">Indirectos</span>
                                            <strong>{{ $indirectEvents }}</strong>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @if($codigo !== '')
            @if(!$hasResults && !$error)
                <div class="row">
                    <div class="col-12">
                        <div class="alert alert-warning">
                            <h5 class="mb-1"><i class="fas fa-exclamation-triangle mr-1"></i> Sin coincidencias para {{ $codigo }}</h5>
                            <p class="mb-0">No se encontraron registros en IPS5Db para ese codigo. Prueba con el S10 completo o con el codigo local exacto.</p>
                        </div>
                    </div>
                </div>
            @else
                <div class="row">
                    <div class="col-12">
                        <div class="card card-outline card-{{ $heroClass }} hero-card">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-lg-8">
                                        <div class="d-flex flex-wrap align-items-center mb-3">
                                            <span class="badge badge-{{ $heroClass }} px-3 py-2 mr-2">{{ $heroLabel }}</span>
                                            @if($mainPackage?->MAIL_CLASS_NM)
                                                <span class="badge badge-light border px-3 py-2 mr-2">{{ $mainPackage->MAIL_CLASS_NM }}</span>
                                            @endif
                                            @if($mainPackage?->PRODUCT_TYPE_NM)
                                                <span class="badge badge-light border px-3 py-2">{{ $mainPackage->PRODUCT_TYPE_NM }}</span>
                                            @endif
                                        </div>
                                        <h2 class="hero-code mb-2">{{ $mainPackage->MAILITM_FID ?? $codigo }}</h2>
                                        <p class="text-muted mb-3">
                                            {{ $mainPackage->MAILITM_LOCAL_ID ?? 'Sin codigo local visible' }}
                                            @if($mainPackage?->ORIG_COUNTRY_NM || $mainPackage?->DEST_COUNTRY_NM)
                                                <span class="mx-2">|</span>
                                                {{ $mainPackage->ORIG_COUNTRY_NM ?? '-' }} -> {{ $mainPackage->DEST_COUNTRY_NM ?? '-' }}
                                            @endif
                                        </p>
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <div class="hero-pill">
                                                    <span class="hero-pill-label">Estado actual</span>
                                                    <strong>{{ $latestStatus ?: 'Sin estado postal' }}</strong>
                                                    <small>{{ $latestEventName }}</small>
                                                </div>
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <div class="hero-pill">
                                                    <span class="hero-pill-label">Ultima ubicacion</span>
                                                    <strong>{{ $latestOffice ?: '-' }}</strong>
                                                    <small>{{ $latestEvent->EVENT_GMT_DT ?? $mainPackage->EVT_GMT_DT ?? 'Sin fecha' }}</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-lg-4">
                                        <div class="hero-stats">
                                            <div>
                                                <span>Locales</span>
                                                <strong>{{ $localEvents }}</strong>
                                            </div>
                                            <div>
                                                <span>EDI</span>
                                                <strong>{{ $ediEvents }}</strong>
                                            </div>
                                            <div>
                                                <span>Indirectos</span>
                                                <strong>{{ $indirectEvents }}</strong>
                                            </div>
                                            <div>
                                                <span>Manifiestos</span>
                                                <strong>{{ count($manifestRows) }}</strong>
                                            </div>
                                        </div>
                                        <div class="mt-3 text-muted small">
                                            <div><strong>Ultimo dato de entrega:</strong> {{ $latestDelivery->EVENT_TYPE_NM_ES ?? 'Sin registro' }}</div>
                                            <div><strong>Firmante:</strong> {{ $latestDelivery->SIGNATORY_NM ?? '-' }}</div>
                                            <div><strong>Ultimo manifiesto:</strong> {{ $latestManifest->MANIFEST_LIST_ID ?? '-' }}</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            @if($hasResults)
            <div class="row">
                <div class="col-lg col-md-6 col-12">
                    <div class="small-box bg-info">
                        <div class="inner">
                            <h3>{{ count($packageRows) }}</h3>
                            <p>Registros base</p>
                        </div>
                        <div class="icon"><i class="fas fa-box"></i></div>
                    </div>
                </div>
                <div class="col-lg col-md-6 col-12">
                    <div class="small-box bg-success">
                        <div class="inner">
                            <h3>{{ count($trackingRows) }}</h3>
                            <p>Eventos de rastreo</p>
                        </div>
                        <div class="icon"><i class="fas fa-stream"></i></div>
                    </div>
                </div>
                <div class="col-lg col-md-6 col-12">
                    <div class="small-box bg-warning">
                        <div class="inner">
                            <h3>{{ count($deliveryRows) }}</h3>
                            <p>Eventos de entrega</p>
                        </div>
                        <div class="icon"><i class="fas fa-shipping-fast"></i></div>
                    </div>
                </div>
                <div class="col-lg col-md-6 col-12">
                    <div class="small-box bg-secondary">
                        <div class="inner">
                            <h3>{{ $indirectEvents }}</h3>
                            <p>Eventos indirectos</p>
                        </div>
                        <div class="icon"><i class="fas fa-project-diagram"></i></div>
                    </div>
                </div>
                <div class="col-lg col-md-6 col-12">
                    <div class="small-box bg-secondary">
                        <div class="inner">
                            <h3>{{ $ediEvents }}</h3>
                            <p>Intercambios EDI</p>
                        </div>
                        <div class="icon"><i class="fas fa-exchange-alt"></i></div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-12">
                    <div class="callout callout-info">
                        <h5>Lectura sugerida</h5>
                        <p class="mb-1">Empieza por el bloque superior para saber el estado actual, luego revisa los hitos del recorrido y finalmente valida entrega, logistica y EDI.</p>
                        <p class="mb-0">La tabla completa mezcla eventos directos del paquete, eventos EDI y eventos indirectos de envases o receptaculos asociados, todos etiquetados por fuente.</p>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-lg-4">
                    <div class="card card-outline card-primary h-100">
                        <div class="card-header"><h3 class="card-title">Ficha rapida</h3></div>
                        <div class="card-body p-0">
                            <div class="list-group list-group-flush">
                                <div class="list-group-item">
                                    <small class="text-muted d-block">Codigo consultado</small>
                                    <strong>{{ $codigo }}</strong>
                                </div>
                                <div class="list-group-item">
                                    <small class="text-muted d-block">Codigo S10</small>
                                    <strong>{{ $mainPackage->MAILITM_FID ?? '-' }}</strong>
                                </div>
                                <div class="list-group-item">
                                    <small class="text-muted d-block">Codigo local</small>
                                    <strong>{{ $mainPackage->MAILITM_LOCAL_ID ?? '-' }}</strong>
                                </div>
                                <div class="list-group-item">
                                    <small class="text-muted d-block">Clase / producto</small>
                                    <strong>{{ $mainPackage ? (($mainPackage->MAIL_CLASS_NM ?: '-') . ($mainPackage->PRODUCT_TYPE_NM ? ' / ' . $mainPackage->PRODUCT_TYPE_NM : '')) : '-' }}</strong>
                                </div>
                                <div class="list-group-item">
                                    <small class="text-muted d-block">Peso / valor</small>
                                    <strong>{{ $mainPackage->MAILITM_WEIGHT ?? '-' }} / {{ $mainPackage->MAILITM_VALUE ?? '-' }} {{ $mainPackage->CURRENCY_CD ?? '' }}</strong>
                                </div>
                                <div class="list-group-item">
                                    <small class="text-muted d-block">Contenido</small>
                                    <strong>{{ $mainPackage->MAILITM_CONTENT_NM ?? '-' }}</strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card card-outline card-info h-100">
                        <div class="card-header"><h3 class="card-title">Partes y destino</h3></div>
                        <div class="card-body p-0">
                            <div class="list-group list-group-flush">
                                <div class="list-group-item">
                                    <small class="text-muted d-block">Remitente</small>
                                    <strong>{{ $sender ? trim(($sender->CUSTOMER_NAME ?: '') . ' ' . ($sender->CUSTOMER_FORENAME ?: '')) : '-' }}</strong>
                                    <div class="text-muted small">{{ $sender->CUSTOMER_CITY ?? '-' }} | {{ $sender->COUNTRY_NM ?? '-' }}</div>
                                </div>
                                <div class="list-group-item">
                                    <small class="text-muted d-block">Destinatario</small>
                                    <strong>{{ $recipient ? trim(($recipient->CUSTOMER_NAME ?: '') . ' ' . ($recipient->CUSTOMER_FORENAME ?: '')) : '-' }}</strong>
                                    <div class="text-muted small">{{ $recipient->CUSTOMER_CITY ?? '-' }} | {{ $recipient->COUNTRY_NM ?? '-' }}</div>
                                </div>
                                <div class="list-group-item">
                                    <small class="text-muted d-block">Ruta pais</small>
                                    <strong>{{ $mainPackage->ORIG_COUNTRY_NM ?? '-' }} -> {{ $mainPackage->DEST_COUNTRY_NM ?? '-' }}</strong>
                                </div>
                                <div class="list-group-item">
                                    <small class="text-muted d-block">Contacto destinatario</small>
                                    <strong>{{ $recipient->CUSTOMER_PHONE_NO ?? '-' }}</strong>
                                    <div class="text-muted small">{{ $recipient->CUSTOMER_EMAIL_ADDRESS ?? '-' }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card card-outline card-warning h-100">
                        <div class="card-header"><h3 class="card-title">Entrega y soporte</h3></div>
                        <div class="card-body p-0">
                            <div class="list-group list-group-flush">
                                <div class="list-group-item">
                                    <small class="text-muted d-block">Ultimo dato de entrega</small>
                                    <strong>{{ $latestDelivery->EVENT_TYPE_NM_ES ?? 'Sin registro de entrega' }}</strong>
                                    <div class="text-muted small">{{ $latestDelivery->EVENT_GMT_DT ?? '-' }}</div>
                                </div>
                                <div class="list-group-item">
                                    <small class="text-muted d-block">Firmante / lugar</small>
                                    <strong>{{ $latestDelivery->SIGNATORY_NM ?? '-' }}</strong>
                                    <div class="text-muted small">{{ $latestDelivery->DELIV_LOCATION ?? '-' }}</div>
                                </div>
                                <div class="list-group-item">
                                    <small class="text-muted d-block">No entrega</small>
                                    <strong>{{ $latestDelivery ? (($latestDelivery->NON_DELIVERY_REASON_CD ?: '-') . ' / ' . ($latestDelivery->NON_DELIVERY_MEASURE_CD ?: '-')) : '-' }}</strong>
                                </div>
                                <div class="list-group-item">
                                    <small class="text-muted d-block">Manifiesto / EDI</small>
                                    <strong>{{ $latestManifest->MANIFEST_LIST_ID ?? '-' }}</strong>
                                    <div class="text-muted small">{{ $latestEdi->EVENT_TYPE_NM_ES ?? 'Sin EDI reciente' }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-lg-7">
                    <div class="card card-outline card-primary">
                        <div class="card-header"><h3 class="card-title">Hitos del recorrido</h3></div>
                        <div class="card-body">
                            @if($highlightEvents->isEmpty())
                                <p class="text-muted mb-0">No hay hitos destacados para este codigo.</p>
                            @else
                                <div class="timeline-lite">
                                    @foreach($highlightEvents as $ev)
                                        @php
                                            $eventName = strtolower((string) ($ev->EVENT_TYPE_NM_ES ?? ''));
                                            $dotClass = str_contains($eventName, 'entregar')
                                                ? 'is-success'
                                                : ((str_contains($eventName, 'intento') || str_contains($eventName, 'retener')) ? 'is-warning' : 'is-info');
                                        @endphp
                                        <div class="timeline-lite__item">
                                            <div class="timeline-lite__dot {{ $dotClass }}"></div>
                                            <div class="timeline-lite__content">
                                                <div class="d-flex flex-column flex-md-row justify-content-between">
                                                    <div>
                                                        <strong>{{ $ev->EVENT_TYPE_NM_ES ?: ('Evento #' . $ev->EVENT_TYPE_CD) }}</strong>
                                                        <div class="small mt-1">
                                                            <span class="badge badge-{{ ($ev->SOURCE_DB ?? '') === 'IPS5Db' ? 'primary' : ((($ev->SOURCE_DB ?? '') === 'IPS5Db-EDI') ? 'secondary' : 'warning') }}">
                                                                {{ $ev->SOURCE_DB ?: '-' }}
                                                            </span>
                                                        </div>
                                                    </div>
                                                    <span class="text-muted small">{{ $ev->EVENT_GMT_DT ?? '-' }}</span>
                                                </div>
                                                <div class="text-muted small mt-1">
                                                    {{ ($ev->OFFICE_FCD ? $ev->OFFICE_FCD . ' - ' : '') . ($ev->OFFICE_NM ?: ($ev->EVENT_OFFICE_CD ?: '-')) }}
                                                    @if($ev->NEXT_OFFICE_FCD || $ev->NEXT_OFFICE_NM)
                                                        <span class="mx-1">-></span>
                                                        {{ ($ev->NEXT_OFFICE_FCD ?: '-') . ($ev->NEXT_OFFICE_NM ? ' - ' . $ev->NEXT_OFFICE_NM : '') }}
                                                    @endif
                                                </div>
                                                @if($ev->DETAIL_TXT || $ev->CONDITION_TXT)
                                                    <div class="small mt-2">{{ $ev->DETAIL_TXT ?: $ev->CONDITION_TXT }}</div>
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="card card-outline card-secondary">
                        <div class="card-header"><h3 class="card-title">Resumen logistico</h3></div>
                        <div class="card-body">
                            <div class="mb-3">
                                <small class="text-muted d-block">Saca o receptaculo</small>
                                <strong>{{ $latestLogistic->RECPTCL_FID ?? '-' }}</strong>
                            </div>
                            <div class="mb-3">
                                <small class="text-muted d-block">Despacho</small>
                                <strong>{{ $latestLogistic->DESPTCH_FID ?? '-' }}</strong>
                            </div>
                            <div class="mb-3">
                                <small class="text-muted d-block">Ruta operativa</small>
                                <strong>{{ $latestLogistic ? (($latestLogistic->ORIG_OFFICE_FCD ?: '-') . ' -> ' . ($latestLogistic->DEST_OFFICE_FCD ?: '-')) : '-' }}</strong>
                            </div>
                            <div class="mb-3">
                                <small class="text-muted d-block">Salida registrada</small>
                                <strong>{{ $latestLogistic->DESPTCH_DEPARTURE_DT ?? '-' }}</strong>
                            </div>
                            <div>
                                <small class="text-muted d-block">Ultimo manifiesto</small>
                                <strong>{{ $latestManifest->MANIFEST_LIST_ID ?? '-' }}</strong>
                                <div class="text-muted small">{{ ($latestManifest?->OFFICE_FCD ? $latestManifest->OFFICE_FCD . ' - ' : '') . ($latestManifest->OFFICE_NM ?? '-') }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-12">
                    <div class="card card-outline card-primary">
                        <div class="card-header"><h3 class="card-title">Detalle de identificacion y estado del paquete</h3></div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-sm table-striped mb-0">
                                    <thead class="thead-dark">
                                        <tr>
                                            <th>S10</th>
                                            <th>Local</th>
                                            <th>Peso</th>
                                            <th>Valor</th>
                                            <th>Moneda</th>
                                            <th>Clase</th>
                                            <th>Contenido</th>
                                            <th>Producto</th>
                                            <th>Origen</th>
                                            <th>Destino</th>
                                            <th>Estado actual</th>
                                            <th>Ultimo evento</th>
                                            <th>Ultima oficina</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse($packageRows as $p)
                                            <tr>
                                                <td>{{ $p->MAILITM_FID ?: '-' }}</td>
                                                <td>{{ $p->MAILITM_LOCAL_ID ?: '-' }}</td>
                                                <td>{{ $p->MAILITM_WEIGHT }}</td>
                                                <td>{{ $p->MAILITM_VALUE }}</td>
                                                <td>{{ ($p->CURRENCY_CD ?: '') . ($p->CURRENCY_NM ? ' - ' . $p->CURRENCY_NM : '') }}</td>
                                                <td>{{ ($p->MAIL_CLASS_CD ?: '') . ($p->MAIL_CLASS_NM ? ' - ' . $p->MAIL_CLASS_NM : '') }}</td>
                                                <td>{{ ($p->MAILITM_CONTENT_CD ?: '') . ($p->MAILITM_CONTENT_NM ? ' - ' . $p->MAILITM_CONTENT_NM : '') }}</td>
                                                <td>{{ ($p->PRODUCT_TYPE_CD ?: '') . ($p->PRODUCT_TYPE_NM ? ' - ' . $p->PRODUCT_TYPE_NM : '') }}</td>
                                                <td>{{ ($p->ORIG_COUNTRY_CD ?: '') . ($p->ORIG_COUNTRY_NM ? ' - ' . $p->ORIG_COUNTRY_NM : '') }}</td>
                                                <td>{{ ($p->DEST_COUNTRY_CD ?: '') . ($p->DEST_COUNTRY_NM ? ' - ' . $p->DEST_COUNTRY_NM : '') }}</td>
                                                <td>{{ ($p->POSTAL_STATUS_CD !== null ? $p->POSTAL_STATUS_CD : '-') . ($p->POSTAL_STATUS_NM ? ' - ' . $p->POSTAL_STATUS_NM : '') }}</td>
                                                <td>{{ $p->EVT_TYPE_NM_ES ?: ('Evento #' . $p->EVT_TYPE_CD) }}</td>
                                                <td>{{ ($p->EVT_OFFICE_FCD ? $p->EVT_OFFICE_FCD . ' - ' : '') . ($p->EVT_OFFICE_NM ?: '-') }}</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="13" class="text-center text-muted">Sin datos de cabecera.</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 col-12">
                    <div class="card card-outline card-info">
                        <div class="card-header"><h3 class="card-title">Partes involucradas: remitente y destinatario</h3></div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-sm table-striped mb-0">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>Tipo</th><th>Nombre</th><th>Ciudad</th><th>Pais</th><th>Telefono</th><th>Email</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse($customerRows as $c)
                                            <tr>
                                                <td>{{ $c->SENDER_PAYEE_IND ?: '-' }}</td>
                                                <td>{{ trim(($c->CUSTOMER_NAME ?: '') . ' ' . ($c->CUSTOMER_FORENAME ?: '')) ?: '-' }}</td>
                                                <td>{{ $c->CUSTOMER_CITY ?: '-' }}</td>
                                                <td>{{ ($c->COUNTRY_CD ?: '') . ($c->COUNTRY_NM ? ' - ' . $c->COUNTRY_NM : '') }}</td>
                                                <td>{{ $c->CUSTOMER_PHONE_NO ?: '-' }}</td>
                                                <td>{{ $c->CUSTOMER_EMAIL_ADDRESS ?: '-' }}</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="6" class="text-center text-muted">Sin datos de cliente.</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-12">
                    <div class="card card-outline card-warning">
                        <div class="card-header"><h3 class="card-title">Situacion de entrega</h3></div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-sm table-striped mb-0">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>Fecha</th><th>Evento</th><th>No entrega</th><th>Firmante</th><th>Lugar</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse($deliveryRows as $d)
                                            <tr>
                                                <td>{{ $d->EVENT_GMT_DT }}</td>
                                                <td>{{ $d->EVENT_TYPE_NM_ES ?: ('Evento #' . $d->EVENT_TYPE_CD) }}</td>
                                                <td>{{ ($d->NON_DELIVERY_REASON_CD ?: '-') . ' / ' . ($d->NON_DELIVERY_MEASURE_CD ?: '-') }}</td>
                                                <td>{{ $d->SIGNATORY_NM ?: '-' }}</td>
                                                <td>{{ $d->DELIV_LOCATION ?: '-' }}</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="5" class="text-center text-muted">Sin datos de entrega.</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 col-12">
                    <div class="card card-outline card-secondary">
                        <div class="card-header"><h3 class="card-title">Movimiento logistico: saca y despacho</h3></div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-sm table-striped mb-0">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>Saca</th><th>Peso</th><th>Items</th><th>Despacho</th><th>Origen</th><th>Destino</th><th>Salida</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse($logisticRows as $l)
                                            <tr>
                                                <td>{{ $l->RECPTCL_FID ?: '-' }}</td>
                                                <td>{{ $l->RECPTCL_WEIGHT }}</td>
                                                <td>{{ $l->RECPTCL_MAILITMS_NO }}</td>
                                                <td>{{ $l->DESPTCH_FID ?: '-' }}</td>
                                                <td>{{ $l->ORIG_OFFICE_FCD ?: '-' }}</td>
                                                <td>{{ $l->DEST_OFFICE_FCD ?: '-' }}</td>
                                                <td>{{ $l->DESPTCH_DEPARTURE_DT ?: '-' }}</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="7" class="text-center text-muted">Sin vinculos logisticos.</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-12">
                    <div class="card card-outline card-dark">
                        <div class="card-header"><h3 class="card-title">Soporte documental: manifiestos</h3></div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-sm table-striped mb-0">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>Manifiesto</th><th>Fecha creacion</th><th>Oficina</th><th>Tipo</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse($manifestRows as $m)
                                            <tr>
                                                <td>{{ $m->MANIFEST_LIST_ID }}</td>
                                                <td>{{ $m->CREATION_LCL_DT }}</td>
                                                <td>{{ ($m->OFFICE_FCD ? $m->OFFICE_FCD . ' - ' : '') . ($m->OFFICE_NM ?: ($m->OWN_OFFICE_CD ?: '-')) }}</td>
                                                <td>{{ $m->MANIF_TYPE_ID }}</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="4" class="text-center text-muted">Sin manifiestos asociados.</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-12">
                    <div class="card card-outline card-success">
                        <div class="card-header"><h3 class="card-title">Intercambio externo EDI</h3></div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-sm table-striped mb-0">
                                    <thead class="thead-dark">
                                        <tr>
                                            <th>Fecha captura</th><th>Fecha local</th><th>Evento EDI</th><th>Location</th><th>Sender</th><th>Despacho</th><th>S10</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse($ediRows as $er)
                                            <tr>
                                                <td>{{ $er->CAPTURE_GMT_DT ?: '-' }}</td>
                                                <td>{{ $er->EVENT_LOCAL_DT ?: '-' }}</td>
                                                <td>{{ $er->EVENT_TYPE_NM_ES ?: ('Evento #' . $er->EVENT_TYPE_CD) }}</td>
                                                <td>{{ $er->LOCATION_ID ?: '-' }}</td>
                                                <td>{{ $er->SENDER_ID ?: '-' }}</td>
                                                <td>{{ $er->DESPATCH_NUMBER ?: '-' }}</td>
                                                <td>{{ $er->MAILITM_FID ?: '-' }}</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="7" class="text-center text-muted">Sin eventos EDI.</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-12">
                    <div class="card card-outline card-primary">
                        <div class="card-header"><h3 class="card-title">Recorrido completo del paquete</h3></div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-striped table-hover table-sm mb-0">
                                    <thead class="thead-dark">
                                        <tr>
                                            <th>Fecha/Hora (GMT)</th>
                                            <th>Tipo Evento</th>
                                            <th>Scanned</th>
                                            <th>Workstation</th>
                                            <th>Condition</th>
                                            <th>Oficina</th>
                                            <th>Siguiente Oficina</th>
                                            <th>Detalle</th>
                                            <th>Codigo S10 (UPU)</th>
                                            <th>Codigo Local</th>
                                            <th>Fuente</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse($trackingRows as $ev)
                                            <tr>
                                                <td>{{ $ev->EVENT_GMT_DT }}</td>
                                                <td>{{ $ev->EVENT_TYPE_NM_ES ?: ('Evento #' . $ev->EVENT_TYPE_CD) }}</td>
                                                <td>{{ $ev->SCANNED_TXT ?: '-' }}</td>
                                                <td>{{ $ev->WORKSTATION_TXT ?: '-' }}</td>
                                                <td>{{ $ev->CONDITION_TXT ?: '-' }}</td>
                                                <td>{{ ($ev->OFFICE_FCD ? $ev->OFFICE_FCD . ' - ' : '') . ($ev->OFFICE_NM ?: ($ev->EVENT_OFFICE_CD ?: '-')) }}</td>
                                                <td>{{ ($ev->NEXT_OFFICE_FCD ?: '-') . ($ev->NEXT_OFFICE_NM ? ' - ' . $ev->NEXT_OFFICE_NM : '') }}</td>
                                                <td>{{ $ev->DETAIL_TXT ?: '-' }}</td>
                                                <td>{{ $ev->MAILITM_FID ?: '-' }}</td>
                                                <td>{{ $ev->MAILITM_LOCAL_ID ?: '-' }}</td>
                                                <td>
                                                    <span class="badge badge-{{ ($ev->SOURCE_DB ?? '') === 'IPS5Db-EDI' ? 'secondary' : 'primary' }}">
                                                        {{ $ev->SOURCE_DB ?: '-' }}
                                                    </span>
                                                </td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="11" class="text-center text-muted">No se encontraron eventos para ese codigo.</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-12">
                    <div class="card card-outline card-info">
                        <div class="card-header">
                            <h3 class="card-title">Origen tecnico de la informacion</h3>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-sm table-striped mb-0">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>Tabla</th><th>Que hace</th><th>Atributos relevantes</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($tableMap as $tm)
                                            <tr>
                                                <td><strong>{{ $tm['table'] }}</strong></td>
                                                <td>{{ $tm['purpose'] }}</td>
                                                <td>{{ $tm['attrs'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            @endif
        @endif
    </div>
@stop

@section('css')
    <link rel="stylesheet" href="{{ asset('vendor/fontawesome-free/css/all.min.css') }}">
    <style>
        .search-shell,
        .hero-card {
            border-radius: 1rem;
            overflow: hidden;
        }

        .search-shell .card-body {
            background:
                radial-gradient(circle at top right, rgba(0, 123, 255, 0.14), transparent 32%),
                linear-gradient(135deg, #ffffff 0%, #f4f8fc 100%);
        }

        .search-sidecard {
            background: #0f172a;
            color: #fff;
            border-radius: 1rem;
            padding: 1.25rem;
            box-shadow: 0 18px 45px rgba(15, 23, 42, 0.15);
        }

        .metric-label,
        .hero-pill-label {
            display: block;
            color: rgba(255, 255, 255, 0.68);
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .metric-grid,
        .hero-stats {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem;
        }

        .metric-grid {
            margin-top: 1rem;
        }

        .metric-grid strong,
        .metric strong {
            display: block;
            font-size: 1.35rem;
            line-height: 1.1;
        }

        .search-hints {
            font-size: 0.92rem;
            color: #5c6670;
        }

        .hero-card .card-body {
            background:
                radial-gradient(circle at top left, rgba(0, 123, 255, 0.1), transparent 30%),
                linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
        }

        .hero-code {
            font-size: 2rem;
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        .hero-pill {
            background: #0f172a;
            color: #fff;
            border-radius: 0.9rem;
            padding: 1rem 1.1rem;
            min-height: 100%;
        }

        .hero-pill strong {
            display: block;
            font-size: 1.05rem;
            margin-top: 0.15rem;
        }

        .hero-pill small {
            color: rgba(255, 255, 255, 0.72);
        }

        .hero-stats div {
            background: #fff;
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 0.9rem;
            padding: 0.9rem 1rem;
        }

        .hero-stats span {
            display: block;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #6b7280;
        }

        .hero-stats strong {
            display: block;
            margin-top: 0.15rem;
            font-size: 1.4rem;
            line-height: 1;
        }

        .timeline-lite {
            position: relative;
            padding-left: 1.4rem;
        }

        .timeline-lite::before {
            content: '';
            position: absolute;
            left: 0.38rem;
            top: 0.2rem;
            bottom: 0.2rem;
            width: 2px;
            background: linear-gradient(180deg, #0d6efd, #d6e4ff);
        }

        .timeline-lite__item {
            position: relative;
            padding-left: 1.25rem;
            padding-bottom: 1.25rem;
        }

        .timeline-lite__dot {
            position: absolute;
            left: -0.06rem;
            top: 0.35rem;
            width: 0.9rem;
            height: 0.9rem;
            border-radius: 999px;
            background: #17a2b8;
            border: 3px solid #fff;
            box-shadow: 0 0 0 2px rgba(23, 162, 184, 0.2);
        }

        .timeline-lite__dot.is-success {
            background: #28a745;
            box-shadow: 0 0 0 2px rgba(40, 167, 69, 0.2);
        }

        .timeline-lite__dot.is-warning {
            background: #f59e0b;
            box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.2);
        }

        .timeline-lite__content {
            background: #fff;
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 0.9rem;
            padding: 0.95rem 1rem;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.05);
        }

        @media (max-width: 767.98px) {
            .hero-code {
                font-size: 1.5rem;
            }
        }
    </style>
@stop
