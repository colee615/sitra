@extends('adminlte::page')

@section('title', 'Consulta Unificada')

@section('content_header')
    @php
        $ipsHasResults = collect([
            $ips['packageRows'] ?? collect(),
            $ips['trackingRows'] ?? collect(),
            $ips['deliveryRows'] ?? collect(),
            $ips['logisticRows'] ?? collect(),
            $ips['manifestRows'] ?? collect(),
            $ips['ediRows'] ?? collect(),
        ])->contains(fn ($rows) => count($rows) > 0);

        $cdsHasResults = count($cds['objectRows'] ?? []) > 0
            || count($cds['stateRows'] ?? []) > 0
            || count($cds['declarationRows'] ?? []) > 0
            || count($cds['declarationEventRows'] ?? []) > 0
            || count($cds['responseRows'] ?? []) > 0
            || count($cds['responseEventRows'] ?? []) > 0
            || count($cds['ediExportRows'] ?? []) > 0
            || count($cds['anDeclarationRows'] ?? []) > 0;

        $mainPackage = $ips['packageRows'][0] ?? null;
        $latestEvent = $ips['trackingRows'][0] ?? null;
        $mainObject = $cds['objectRows'][0] ?? null;
        $currentState = $cds['stateRows'][0] ?? null;

        $decl = null;
        foreach (($cds['declarationRows'] ?? []) as $row) {
            $xmlText = trim((string) ($row->DECLARATION_DATA ?? ''));
            if ($xmlText === '') {
                continue;
            }

            try {
                $xml = @simplexml_load_string($xmlText);
                if (!$xml) {
                    continue;
                }

                $decl = [
                    'sender_name' => trim((string) ($xml['SNm'] ?? '')),
                    'sender_line_1' => trim((string) ($xml['SAdL1'] ?? '')),
                    'sender_city' => trim((string) ($xml['SCty'] ?? '')),
                    'recipient_name' => trim((string) ($xml['RNm'] ?? '')),
                    'recipient_line_1' => trim((string) ($xml['RAdL1'] ?? '')),
                    'recipient_city' => trim((string) ($xml['RCty'] ?? '')),
                    'transport_mode' => trim((string) ($xml['TrMod'] ?? '')),
                    'declared_value' => trim((string) ($xml['TotCPVal'] ?? '')),
                    'declared_currency' => trim((string) ($xml['TotCPValCur'] ?? '')),
                ];
                break;
            } catch (\Throwable) {
            }
        }
    @endphp

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center">
        <div>
            <span class="workspace-kicker">
                <i class="fas fa-search"></i>
                Consulta unificada
            </span>
            <h1 class="workspace-title">Una historia: CDS primero, IPS despues</h1>
            <p class="workspace-subtitle mb-0">
                Busca una sola vez y entiende el objeto como una secuencia completa: aviso documental en CDS y operacion real en IPS.
            </p>
        </div>
        <a href="{{ route('dashboard') }}" class="btn btn-outline-secondary mt-3 mt-md-0">
            <i class="fas fa-arrow-left mr-1"></i> Volver al inicio
        </a>
    </div>
@stop

@section('content')
    <section class="search-panel mb-4">
        <div class="search-panel__top">
            <div>
                <h2 class="search-panel__title">Buscar en IPS y CDS al mismo tiempo</h2>
                <p class="search-panel__subtitle">
                    El mismo codigo se consulta en ambas fuentes para mostrar que se anuncio, que se proceso y en que punto esta ahora.
                </p>
            </div>
            <div class="d-flex flex-wrap" style="gap:0.5rem;">
                <span class="badge badge-info px-3 py-2">IPS operativo</span>
                <span class="badge badge-success px-3 py-2">CDS documental</span>
            </div>
        </div>

        <form method="GET" action="{{ route('consultas.index') }}">
            <div class="input-group input-group-lg">
                <input
                    type="text"
                    name="codigo"
                    value="{{ $codigo }}"
                    class="form-control"
                    placeholder="Ej: RA931985256US o codigo local"
                    autocomplete="off">
                <div class="input-group-append">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search mr-1"></i> Buscar en ambos
                    </button>
                    <a href="{{ route('consultas.index') }}" class="btn btn-default">Limpiar</a>
                </div>
            </div>
        </form>
    </section>

    @if(($ips['error'] ?? null) || ($cds['error'] ?? null))
        <div class="two-up mb-4">
            @if($ips['error'])
                <div class="alert alert-danger mb-0">
                    <strong>IPS:</strong> {{ $ips['error'] }}
                </div>
            @endif
            @if($cds['error'])
                <div class="alert alert-danger mb-0">
                    <strong>CDS:</strong> {{ $cds['error'] }}
                </div>
            @endif
        </div>
    @endif

    @if($codigo !== '')
        @if(!$ipsHasResults && !$cdsHasResults && !($ips['error'] ?? null) && !($cds['error'] ?? null))
            <section class="empty-state">
                <h3>Sin coincidencias para {{ $codigo }}</h3>
                <p>No hubo resultados ni en IPS ni en CDS con ese identificador.</p>
            </section>
        @else
            <section class="workspace-hero mb-4">
                <div class="row align-items-center">
                    <div class="col-lg-8">
                        <span class="workspace-kicker">
                            <i class="fas fa-project-diagram"></i>
                            Lectura conjunta
                        </span>
                        <h2 class="workspace-title mb-2">{{ $codigo }}</h2>
                        <p class="workspace-subtitle mb-3">{{ $story['headline'] ?? 'Sin resumen disponible.' }}</p>
                        <div class="d-flex flex-wrap" style="gap:0.65rem;">
                            <span class="summary-banner__chip">CDS ahora: {{ $story['status']['cds_now'] ?? '-' }}</span>
                            <span class="summary-banner__chip">IPS ahora: {{ $story['status']['ips_now'] ?? '-' }}</span>
                        </div>
                    </div>
                    <div class="col-lg-4 mt-4 mt-lg-0">
                        <div class="stat-grid">
                            <div class="stat-card">
                                <span class="stat-card__label">CDS visible</span>
                                <span class="stat-card__value">{{ $cdsHasResults ? 'Si' : 'No' }}</span>
                            </div>
                            <div class="stat-card">
                                <span class="stat-card__label">IPS visible</span>
                                <span class="stat-card__value">{{ $ipsHasResults ? 'Si' : 'No' }}</span>
                            </div>
                            <div class="stat-card">
                                <span class="stat-card__label">Eventos IPS</span>
                                <span class="stat-card__value">{{ count($ips['trackingRows'] ?? []) }}</span>
                            </div>
                            <div class="stat-card">
                                <span class="stat-card__label">Eventos CDS</span>
                                <span class="stat-card__value">{{ count($cds['declarationEventRows'] ?? []) + count($cds['responseEventRows'] ?? []) }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <div class="two-up mb-4">
                <section class="panel-card">
                    <h3>1. Que se anuncio por CDS</h3>
                    @if($cdsHasResults)
                        <div class="kv-list">
                            <div class="kv-item">
                                <span class="kv-item__label">Estado documental</span>
                                <strong>{{ $currentState->MAIL_STATE_NM ?? $mainObject->MAIL_STATE_CD ?? '-' }}</strong>
                            </div>
                            <div class="kv-item">
                                <span class="kv-item__label">Flujo postal</span>
                                <strong>{{ $mainObject->ORIG_POST_ORGANIZATION_CD ?? '-' }} -> {{ $mainObject->DEST_POST_ORGANIZATION_CD ?? '-' }}</strong>
                            </div>
                            <div class="kv-item">
                                <span class="kv-item__label">Fecha de posting</span>
                                <strong>{{ $mainObject->POSTING_DATE ?? '-' }}</strong>
                            </div>
                            @if($decl)
                                <div class="kv-item">
                                    <span class="kv-item__label">Declaracion interpretada</span>
                                    <strong>{{ $decl['sender_name'] ?: '-' }} -> {{ $decl['recipient_name'] ?: '-' }}</strong>
                                    <div>{{ trim(($decl['recipient_line_1'] ?? '') . ' ' . ($decl['recipient_city'] ?? '')) ?: '-' }}</div>
                                </div>
                            @endif
                        </div>
                    @else
                        <p class="text-muted mb-0">No hay capa documental visible en CDS para este codigo.</p>
                    @endif
                </section>

                <section class="panel-card">
                    <h3>2. Que proceso Bolivia en IPS</h3>
                    @if($ipsHasResults)
                        <div class="kv-list">
                            <div class="kv-item">
                                <span class="kv-item__label">Estado operativo</span>
                                <strong>{{ $latestEvent->EVENT_TYPE_NM_ES ?? $mainPackage->POSTAL_STATUS_NM ?? '-' }}</strong>
                            </div>
                            <div class="kv-item">
                                <span class="kv-item__label">Origen / destino</span>
                                <strong>{{ $mainPackage->ORIG_COUNTRY_NM ?? $mainPackage->ORIG_COUNTRY_CD ?? '-' }} -> {{ $mainPackage->DEST_COUNTRY_NM ?? $mainPackage->DEST_COUNTRY_CD ?? '-' }}</strong>
                            </div>
                            <div class="kv-item">
                                <span class="kv-item__label">Ultima oficina</span>
                                <strong>{{ trim(implode(' - ', array_filter([$latestEvent->OFFICE_FCD ?? '', $latestEvent->OFFICE_NM ?? '']))) ?: '-' }}</strong>
                            </div>
                        </div>
                    @else
                        <p class="text-muted mb-0">No hay capa operativa visible en IPS para este codigo.</p>
                    @endif
                </section>
            </div>

            <section class="panel-card mb-4">
                <h3>3. Linea de tiempo combinada</h3>
                <p class="text-muted mb-3">
                    Esta vista une CDS e IPS en orden cronologico para que puedas entender la secuencia completa sin saltar entre pantallas.
                </p>

                @if(count($story['timeline'] ?? []) > 0)
                    <div class="timeline-stack">
                        @foreach($story['timeline'] as $step)
                            <article class="timeline-event">
                                <div class="timeline-event__time">
                                    <span class="badge badge-{{ ($step['tone'] ?? 'info') === 'success' ? 'success' : 'info' }}">{{ $step['source'] }}</span>
                                    <div class="mt-2">{{ $step['occurred_at'] }}</div>
                                </div>
                                <div>
                                    <h4 class="timeline-event__title">{{ $step['title'] }}</h4>
                                    <div class="timeline-event__meta">{{ $step['summary'] ?: '-' }}</div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                @else
                    <p class="text-muted mb-0">No se pudo construir la linea de tiempo combinada.</p>
                @endif
            </section>

            <div class="two-up mb-4">
                <section class="panel-card">
                    <h3>Como leer este caso</h3>
                    <div class="kv-list">
                        @foreach($story['understanding'] ?? [] as $note)
                            <div class="kv-item">
                                <strong>{{ $note }}</strong>
                            </div>
                        @endforeach
                    </div>
                </section>

                <section class="panel-card">
                    <h3>Resumen ejecutivo</h3>
                    <div class="kv-list">
                        <div class="kv-item">
                            <span class="kv-item__label">CDS responde</span>
                            <strong>Que informacion electronica o documental llego primero.</strong>
                        </div>
                        <div class="kv-item">
                            <span class="kv-item__label">IPS responde</span>
                            <strong>Que hizo Bolivia operativamente con el objeto.</strong>
                        </div>
                        <div class="kv-item">
                            <span class="kv-item__label">Lectura correcta</span>
                            <strong>Normalmente: CDS antes, IPS despues.</strong>
                        </div>
                    </div>
                </section>
            </div>

            <div class="two-up">
                <section class="panel-card">
                    <h3>Detalle completo IPS</h3>
                    <p class="mb-3">Si necesitas bajar a la vista operativa detallada, abre el modulo IPS.</p>
                    <a href="{{ route('sqlserver.datos', ['codigo' => $codigo]) }}" class="btn btn-primary">
                        Abrir detalle IPS
                    </a>
                </section>

                <section class="panel-card">
                    <h3>Detalle completo CDS</h3>
                    <p class="mb-3">Si necesitas XML, respuestas y exportaciones EDI completas, abre el modulo CDS.</p>
                    <a href="{{ route('cds.datos', ['codigo' => $codigo]) }}" class="btn btn-success">
                        Abrir detalle CDS
                    </a>
                </section>
            </div>
        @endif
    @endif
@stop
