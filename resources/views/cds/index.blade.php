@extends('adminlte::page')

@section('title', 'CDS / Avisos y Declaraciones')

@section('content_header')
    @php
        $main = $objectRows[0] ?? null;
        $currentState = $stateRows[0] ?? null;
        $latestDeclarationEvent = $declarationEventRows[0] ?? null;
        $latestResponseEvent = $responseEventRows[0] ?? null;
        $latestEdi = $ediExportRows[0] ?? null;
        $hasResults = count($objectRows) > 0
            || count($stateRows) > 0
            || count($declarationRows) > 0
            || count($declarationEventRows) > 0
            || count($responseRows) > 0
            || count($responseEventRows) > 0
            || count($ediExportRows) > 0
            || count($anDeclarationRows) > 0;

        $decl = null;
        $xmlPreview = null;
        foreach ($declarationRows as $row) {
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
                    'sender_line_2' => trim((string) ($xml['SAdL2'] ?? '')),
                    'sender_city' => trim((string) ($xml['SCty'] ?? '')),
                    'sender_country' => trim((string) ($xml['SCtr'] ?? '')),
                    'sender_phone' => trim((string) ($xml['STel'] ?? '')),
                    'recipient_name' => trim((string) ($xml['RNm'] ?? '')),
                    'recipient_line_1' => trim((string) ($xml['RAdL1'] ?? '')),
                    'recipient_line_2' => trim((string) ($xml['RAdL2'] ?? '')),
                    'recipient_city' => trim((string) ($xml['RCty'] ?? '')),
                    'recipient_country' => trim((string) ($xml['RCtr'] ?? '')),
                    'recipient_phone' => trim((string) ($xml['RTel'] ?? '')),
                    'declared_weight' => trim((string) ($xml['TotNWgt'] ?? '')),
                    'gross_weight' => trim((string) ($xml['GWgt'] ?? $xml['Gwgt'] ?? '')),
                    'declared_value' => trim((string) ($xml['TotCPVal'] ?? '')),
                    'declared_currency' => trim((string) ($xml['TotCPValCur'] ?? '')),
                    'transport_mode' => trim((string) ($xml['TrMod'] ?? '')),
                    'mail_class' => trim((string) ($xml['HC'] ?? '')),
                    'type_desc' => trim((string) ($xml['NTypDesc'] ?? '')),
                    'posting_date' => trim((string) ($xml['TrDat'] ?? '')),
                ];

                $xmlPreview = $xmlText;
                break;
            } catch (\Throwable) {
            }
        }
    @endphp

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center">
        <div>
            <span class="workspace-kicker">
                <i class="fas fa-broadcast-tower"></i>
                CDSDb
            </span>
            <h1 class="workspace-title">Aviso anticipado y capa documental</h1>
            <p class="workspace-subtitle mb-0">
                Consulta la parte electrónica del objeto postal: datos declarados, eventos CDS, respuestas y exportaciones
                EDI que explican el aviso previo a la operación.
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
                <h2 class="search-panel__title">Buscar objeto en CDS</h2>
                <p class="search-panel__subtitle">
                    Consulta por S10 o identificador local para ver preaviso documental, XML de declaración y eventos CDS.
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
                <strong>Error CDS:</strong> {{ $error }}
            </div>
        @endif

        <form method="GET" action="{{ route('cds.datos') }}">
            <div class="input-group input-group-lg">
                <input
                    type="text"
                    name="codigo"
                    value="{{ $codigo }}"
                    class="form-control"
                    placeholder="Ej: RA931985256US o identificador local"
                    autocomplete="off">
                <div class="input-group-append">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search mr-1"></i> Consultar
                    </button>
                    <a href="{{ route('cds.datos') }}" class="btn btn-default">Limpiar</a>
                </div>
            </div>
        </form>
    </section>

    @if($codigo !== '' && !$hasResults && !$error)
        <section class="empty-state">
            <h3>Sin resultados para {{ $codigo }}</h3>
            <p>No se encontraron objetos ni declaraciones en CDSDb con ese identificador.</p>
        </section>
    @endif

    @if($hasResults)
        <section class="summary-banner summary-banner--cds mb-4">
            <div class="summary-banner__meta">
                <span class="summary-banner__chip"><i class="fas fa-barcode"></i> {{ $main->MAIL_OBJECT_ID ?? $codigo }}</span>
                @if(!empty($main?->MAIL_OBJECT_LOCAL_ID))
                    <span class="summary-banner__chip"><i class="fas fa-fingerprint"></i> {{ $main->MAIL_OBJECT_LOCAL_ID }}</span>
                @endif
                @if(!empty($main?->ORIG_POST_ORGANIZATION_CD) || !empty($main?->DEST_POST_ORGANIZATION_CD))
                    <span class="summary-banner__chip"><i class="fas fa-route"></i> {{ $main->ORIG_POST_ORGANIZATION_CD ?? '-' }} -> {{ $main->DEST_POST_ORGANIZATION_CD ?? '-' }}</span>
                @endif
            </div>
            <h2 class="summary-banner__title">{{ $currentState->MAIL_STATE_NM ?? ('Estado CDS ' . ($main->MAIL_STATE_CD ?? '-')) }}</h2>
            <p class="summary-banner__subtitle">
                Objeto documental en CDS.
                Último evento de declaración: {{ $latestDeclarationEvent->D_EVENT_GMT_DT ?? 'sin dato' }}.
                Última exportación EDI: {{ $latestEdi->EVENT_DATE ?? 'sin dato' }}.
            </p>
        </section>

        <section class="stat-grid mb-4">
            <div class="stat-card">
                <span class="stat-card__label">Objetos</span>
                <span class="stat-card__value">{{ count($objectRows) }}</span>
            </div>
            <div class="stat-card">
                <span class="stat-card__label">Declaraciones</span>
                <span class="stat-card__value">{{ count($declarationRows) }}</span>
            </div>
            <div class="stat-card">
                <span class="stat-card__label">Eventos declaración</span>
                <span class="stat-card__value">{{ count($declarationEventRows) }}</span>
            </div>
            <div class="stat-card">
                <span class="stat-card__label">Respuestas</span>
                <span class="stat-card__value">{{ count($responseRows) }}</span>
            </div>
            <div class="stat-card">
                <span class="stat-card__label">Eventos respuesta</span>
                <span class="stat-card__value">{{ count($responseEventRows) }}</span>
            </div>
            <div class="stat-card">
                <span class="stat-card__label">Export EDI</span>
                <span class="stat-card__value">{{ count($ediExportRows) }}</span>
            </div>
        </section>

        <div class="two-up mb-4">
            <section class="panel-card">
                <h3>Ficha del objeto</h3>
                <div class="kv-list">
                    <div class="kv-item">
                        <span class="kv-item__label">Identificador CDS</span>
                        <strong>{{ $main->MAIL_OBJECT_ID ?? $codigo }}</strong>
                    </div>
                    <div class="kv-item">
                        <span class="kv-item__label">Flujo</span>
                        <strong>{{ $main->MAIL_FLOW_CD ?? '-' }}</strong>
                    </div>
                    <div class="kv-item">
                        <span class="kv-item__label">Tipo / clase</span>
                        <strong>{{ $main->MAIL_OBJECT_TYPE_CD ?? '-' }} / {{ $main->MAIL_CLASS_CD ?? '-' }}</strong>
                    </div>
                    <div class="kv-item">
                        <span class="kv-item__label">Posting date</span>
                        <strong>{{ $main->POSTING_DATE ?? '-' }}</strong>
                    </div>
                </div>
            </section>

            <section class="panel-card">
                <h3>Lectura funcional</h3>
                <div class="kv-list">
                    <div class="kv-item">
                        <span class="kv-item__label">Último evento de declaración</span>
                        <strong>{{ $latestDeclarationEvent->D_EVENT_GMT_DT ?? '-' }}</strong>
                    </div>
                    <div class="kv-item">
                        <span class="kv-item__label">Último evento de respuesta</span>
                        <strong>{{ $latestResponseEvent->R_EVENT_GMT_DT ?? '-' }}</strong>
                    </div>
                    <div class="kv-item">
                        <span class="kv-item__label">Última exportación EDI</span>
                        <strong>{{ $latestEdi->EDI_MESSAGE_CD ?? '-' }} / {{ $latestEdi->EVENT_DATE ?? '-' }}</strong>
                    </div>
                </div>
            </section>
        </div>

        @if($decl)
            <div class="two-up mb-4">
                <section class="panel-card">
                    <h3>Remitente declarado</h3>
                    <div class="kv-list">
                        <div class="kv-item">
                            <span class="kv-item__label">Nombre</span>
                            <strong>{{ $decl['sender_name'] ?: '-' }}</strong>
                        </div>
                        <div class="kv-item">
                            <span class="kv-item__label">Dirección</span>
                            <strong>{{ trim($decl['sender_line_1'] . ' ' . $decl['sender_line_2']) ?: '-' }}</strong>
                            <div>{{ trim($decl['sender_city'] . ' ' . $decl['sender_country']) }}</div>
                        </div>
                        <div class="kv-item">
                            <span class="kv-item__label">Teléfono</span>
                            <strong>{{ $decl['sender_phone'] ?: '-' }}</strong>
                        </div>
                    </div>
                </section>

                <section class="panel-card">
                    <h3>Destinatario declarado</h3>
                    <div class="kv-list">
                        <div class="kv-item">
                            <span class="kv-item__label">Nombre</span>
                            <strong>{{ $decl['recipient_name'] ?: '-' }}</strong>
                        </div>
                        <div class="kv-item">
                            <span class="kv-item__label">Dirección</span>
                            <strong>{{ trim($decl['recipient_line_1'] . ' ' . $decl['recipient_line_2']) ?: '-' }}</strong>
                            <div>{{ trim($decl['recipient_city'] . ' ' . $decl['recipient_country']) }}</div>
                        </div>
                        <div class="kv-item">
                            <span class="kv-item__label">Teléfono</span>
                            <strong>{{ $decl['recipient_phone'] ?: '-' }}</strong>
                        </div>
                    </div>
                </section>
            </div>

            <section class="panel-card mb-4">
                <h3>Resumen de declaración</h3>
                <div class="stat-grid">
                    <div class="stat-card">
                        <span class="stat-card__label">Fecha declarada</span>
                        <span class="stat-card__value" style="font-size:1rem;">{{ $decl['posting_date'] ?: '-' }}</span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-card__label">Peso neto</span>
                        <span class="stat-card__value">{{ $decl['declared_weight'] ?: '-' }}</span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-card__label">Peso bruto</span>
                        <span class="stat-card__value">{{ $decl['gross_weight'] ?: '-' }}</span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-card__label">Valor</span>
                        <span class="stat-card__value">{{ $decl['declared_value'] ?: '-' }}</span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-card__label">Moneda</span>
                        <span class="stat-card__value">{{ $decl['declared_currency'] ?: '-' }}</span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-card__label">Modo / clase</span>
                        <span class="stat-card__value">{{ ($decl['transport_mode'] ?: '-') . ' / ' . ($decl['mail_class'] ?: '-') }}</span>
                    </div>
                </div>
            </section>
        @endif

        <div class="two-up mb-4">
            <section class="panel-card">
                <h3>Eventos de declaración</h3>
                <div class="table-wrap">
                    <table class="table table-sm table-striped mb-0">
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Tipo CDS</th>
                                <th>Oficina</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($declarationEventRows as $row)
                                <tr>
                                    <td>{{ $row->D_EVENT_GMT_DT ?? '-' }}</td>
                                    <td>{{ $row->CDS_EVENT_TYPE_CD ?? '-' }}</td>
                                    <td>{{ $row->OFFICE_CD ?? '-' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="text-center text-muted">Sin eventos de declaración.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="panel-card">
                <h3>Exportaciones EDI</h3>
                <div class="table-wrap">
                    <table class="table table-sm table-striped mb-0">
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Mensaje</th>
                                <th>Sender</th>
                                <th>Recipient</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($ediExportRows as $row)
                                <tr>
                                    <td>{{ $row->EVENT_DATE ?? '-' }}</td>
                                    <td>{{ $row->EDI_MESSAGE_CD ?? ($row->EDI_MESSAGE_TYPE ?? '-') }}</td>
                                    <td>{{ $row->SENDER_ORGANIZATION ?? '-' }}</td>
                                    <td>{{ $row->RECIPIENT_ORGANIZATION ?? '-' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center text-muted">Sin exportaciones EDI asociadas.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        @if($xmlPreview)
            <section class="panel-card mb-4">
                <h3>XML de declaración</h3>
                <details>
                    <summary><strong>Ver XML completo</strong></summary>
                    <pre class="mt-3 mb-0" style="white-space: pre-wrap;">{{ $xmlPreview }}</pre>
                </details>
            </section>
        @endif
    @endif
@stop
