@extends('adminlte::page')

@section('title', 'Vista de consumidor API | SITRA')

@section('css')
    <style>
        .consumer-page-banner { align-items: center; background: linear-gradient(115deg, #fff 0%, #f5f8fc 100%); border: 1px solid #e3eaf1; border-left: 4px solid #e0ad31; border-radius: 12px; display: flex; justify-content: space-between; margin: 0 0 18px; padding: 18px 22px; }
        .consumer-page-title { color: #18334e; display: block !important; float: none !important; font-size: 25px !important; font-weight: 700; line-height: 1.2; margin: 0 0 6px !important; }
        .consumer-page-description { color: #617287; margin: 0 0 9px; max-width: 860px; }
        .consumer-endpoint-line { align-items: center; display: flex; flex-wrap: wrap; gap: 8px; }
        .consumer-endpoint-line code { background: #edf2f7; border-radius: 6px; color: #37516b; font-size: 12px; padding: 5px 8px; }
        .consumer-kicker { color: #6b7b8d; font-size: 11px; font-weight: 700; letter-spacing: .07em; margin-bottom: 5px; text-transform: uppercase; }
        .consumer-card { border: 0; border-radius: 12px; box-shadow: 0 3px 14px rgba(25, 45, 70, .07); margin-bottom: 20px; }
        .consumer-card .card-title { color: #18334e; float: none; font-weight: 700; margin: 0; }
        .consumer-connection-heading { align-items: center; display: flex; flex-wrap: wrap; gap: 9px; justify-content: space-between; }
        .consumer-permission { background: #edf3fb; border-radius: 999px; color: #3e628b; font-size: 11px; font-weight: 700; padding: 6px 10px; }
        .consumer-request { background: #f6f8fb; border: 1px solid #e0e7ef; border-radius: 10px; display: grid; gap: 0 16px; grid-template-columns: minmax(0, 1.35fr) minmax(0, 1fr); padding: 17px; }
        .consumer-request > .form-group:nth-of-type(3), .consumer-request > #apiConsumerWriteGuard, .consumer-request > #apiConsumerSend, .consumer-request > #apiConsumerFeedback { grid-column: 1 / -1; }
        .consumer-muted { color: #718198; font-size: 12px; }
        .consumer-stage { border: 1px solid #e3eaf1; border-radius: 14px; min-height: 520px; padding: 22px; }
        .consumer-stage--tracking { background: linear-gradient(145deg, #f1f7ff 0%, #fff 65%); border-top: 4px solid #3478c8; }
        .consumer-stage--customs { background: linear-gradient(145deg, #fff8eb 0%, #fff 65%); border-top: 4px solid #d59b29; }
        .consumer-stage--ips { background: linear-gradient(145deg, #edf9f6 0%, #fff 65%); border-top: 4px solid #249579; }
        .consumer-empty { align-items: center; color: #6d7e91; display: flex; flex-direction: column; justify-content: center; min-height: 410px; text-align: center; }
        .consumer-empty i { align-items: center; background: #fff; border: 1px solid #e3eaf1; border-radius: 18px; color: #8ea2b7; display: flex; font-size: 25px; height: 64px; justify-content: center; margin-bottom: 16px; width: 64px; }
        .consumer-empty strong { color: #294560; font-size: 17px; }
        .consumer-key-grid { display: grid; gap: 8px; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); }
        .consumer-key { color: #77879a; display: block; font-size: 10px; font-weight: 700; letter-spacing: .045em; text-transform: uppercase; }
        .consumer-token-warning { background: #fff7e6; border: 1px solid #f2d28e; border-radius: 8px; color: #725212; padding: 10px 12px; }
        .consumer-data-node { border-top: 1px solid #e8edf2; margin-top: 12px; padding-top: 12px; }
        .consumer-data-node:first-child { border-top: 0; margin-top: 0; padding-top: 0; }
        .consumer-data-node-title { color: #294b6e; font-size: 12px; font-weight: 700; margin-bottom: 9px; }
        .consumer-data-empty { align-items: center; color: #7b8a9b; display: flex; font-size: 12px; gap: 8px; padding: 5px 0; }
        .consumer-data-empty i { color: #9baaba; }
        .consumer-data-collection { display: grid; gap: 10px; grid-template-columns: repeat(auto-fit, minmax(290px, 1fr)); }
        .consumer-data-item { background: #fff; border: 1px solid #e4eaf1; border-radius: 10px; padding: 13px; transition: border-color .18s ease, box-shadow .18s ease; }
        .consumer-data-item:hover { border-color: #cbd8e5; box-shadow: 0 5px 15px rgba(33, 58, 84, .06); }
        .consumer-data-record-header { align-items: center; display: flex; gap: 9px; min-width: 0; }
        .consumer-data-record-icon { align-items: center; background: #f0f4f8; border-radius: 8px; color: #58728c; display: flex; flex: 0 0 31px; font-size: 12px; height: 31px; justify-content: center; }
        .consumer-data-record-title { flex: 1; min-width: 0; }
        .consumer-data-record-title span { color: #8492a3; display: block; font-size: 9px; font-weight: 700; letter-spacing: .055em; text-transform: uppercase; }
        .consumer-data-record-title strong { color: #173d62; display: block; font-size: 13px; line-height: 1.3; margin-top: 2px; overflow-wrap: anywhere; }
        .consumer-data-record-index { align-items: center; background: #f5f7fa; border-radius: 7px; color: #8492a3; display: inline-flex; font-size: 10px; font-weight: 700; height: 25px; justify-content: center; min-width: 29px; padding: 0 5px; }
        .consumer-data-record-content { margin-top: 11px; }
        .consumer-data-record-content > .consumer-data-node:first-child { border-top: 1px solid #edf1f5; margin-top: 0; padding-top: 10px; }
        .consumer-data-field { background: #f8fafc; border: 1px solid #edf1f5; border-radius: 7px; min-width: 0; padding: 9px 10px; }
        .consumer-data-field strong { color: #26394d; display: block; font-size: 13px; font-weight: 650; line-height: 1.35; margin-top: 4px; overflow-wrap: anywhere; }
        .consumer-report { --report-accent: #244d73; --report-soft: #edf3f8; color: #26394d; }
        .consumer-stage--tracking .consumer-report { --report-accent: #3478c8; --report-soft: #edf5ff; }
        .consumer-stage--customs .consumer-report { --report-accent: #a66d08; --report-soft: #fff5df; }
        .consumer-stage--ips .consumer-report { --report-accent: #247d69; --report-soft: #eaf7f3; }
        .consumer-report-heading { align-items: center; background: rgba(255,255,255,.92); border: 1px solid #e3eaf1; border-radius: 13px; box-shadow: 0 3px 12px rgba(25,45,70,.035); display: flex; gap: 15px; margin-bottom: 15px; padding: 17px 19px; }
        .consumer-report-icon { align-items: center; background: var(--report-soft); border-radius: 13px; color: var(--report-accent); display: flex; flex: 0 0 50px; font-size: 20px; height: 50px; justify-content: center; }
        .consumer-report-heading-copy { flex: 1; min-width: 0; }
        .consumer-report-heading .consumer-kicker { color: var(--report-accent); margin-bottom: 4px; }
        .consumer-report-heading h2 { color: #18334e; font-size: 20px; font-weight: 750; letter-spacing: -.02em; margin: 0 0 4px; }
        .consumer-api-response { margin-top: 8px; }
        .consumer-response-section { background: #fff; border: 1px solid #e1e8ef; border-radius: 11px; margin: 0 0 13px; overflow: hidden; }
        .consumer-response-section:last-child { margin-bottom: 0; }
        .consumer-response-section-header { align-items: center; background: #f5f8fb; border-bottom: 1px solid #e7edf3; color: #244765; display: flex; font-size: 13px; font-weight: 700; gap: 9px; padding: 12px 15px; }
        .consumer-response-section-header i { color: var(--report-accent); font-size: 13px; width: 16px; }
        .consumer-response-section-body { padding: 13px 15px; }
        .consumer-response-tabs { border-bottom: 1px solid #e3eaf1; display: flex; gap: 8px; margin: 0 0 14px; padding-bottom: 9px; }
        .consumer-response-tab { background: #fff; border: 1px solid #dbe4ed; border-radius: 7px; color: #52677c; font-size: 12px; font-weight: 650; padding: 8px 12px; }
        .consumer-response-tab:hover { background: #f5f8fb; color: #244765; }
        .consumer-response-tab.active { background: #183b5b; border-color: #183b5b; color: #fff; }
        .consumer-json-response { background: #101923; border: 1px solid #26384b; border-radius: 10px; color: #e6edf5; font-family: Consolas, Monaco, 'Courier New', monospace; font-size: 12px; line-height: 1.55; margin: 0; max-height: 72vh; min-height: 220px; overflow-x: hidden; overflow-y: auto; overflow-wrap: anywhere; padding: 16px; tab-size: 2; white-space: pre-wrap; word-break: break-word; }
        .consumer-response-grid { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(290px, 1fr)); }
        .consumer-response-empty { color: #718198; font-size: 12px; padding: 4px 0; }
        .consumer-data-item .consumer-key-grid { grid-template-columns: repeat(auto-fit, minmax(135px, 1fr)); }
        .consumer-page-controls { align-items: center; background: #f7f9fc; border: 1px solid #e1e8ef; border-radius: 10px; display: flex; gap: 16px; justify-content: space-between; margin-top: 15px; padding: 13px 15px; }
        .consumer-page-controls strong { color: #294b6e; display: block; font-size: 13px; }
        .consumer-page-controls .small { color: #718198; margin-top: 3px; }
        @media (max-width: 767px) {
            .consumer-page-banner { align-items: flex-start; flex-direction: column; gap: 12px; padding: 15px; }
            .consumer-page-title { font-size: 21px !important; }
            .consumer-stage { min-height: 360px; padding: 13px; }
            .consumer-request { grid-template-columns: minmax(0, 1fr); }
            .consumer-request > .form-group, .consumer-request > #apiConsumerWriteGuard, .consumer-request > #apiConsumerSend, .consumer-request > #apiConsumerFeedback { grid-column: 1; }
            .consumer-report-heading { align-items: flex-start; gap: 11px; padding: 13px; }
            .consumer-report-icon { flex-basis: 42px; font-size: 17px; height: 42px; }
            .consumer-report-heading h2 { font-size: 17px; }
            .consumer-data-collection { grid-template-columns: minmax(0, 1fr); }
            .consumer-response-section-body { padding: 10px; }
            .consumer-response-grid { grid-template-columns: minmax(0, 1fr); }
            .consumer-page-controls { align-items: stretch; flex-direction: column; }
        }
    </style>
@stop

@section('content')
    <div class="container-fluid">
        <header class="consumer-page-banner">
            <div>
                <div class="consumer-kicker">{{ $api['group'] }}</div>
                <h1 class="consumer-page-title">{{ $api['consumer']['title'] }}</h1>
                <p class="consumer-page-description">{{ $api['description'] }}</p>
                <div class="consumer-endpoint-line"><span class="badge {{ $api['method'] === 'GET' ? 'badge-success' : 'badge-warning' }}">{{ $api['method'] }}</span><code>{{ $api['path'] }}</code></div>
            </div>
            <a class="btn btn-outline-secondary" href="{{ route('api-credentials.index') }}"><i class="fas fa-arrow-left mr-1"></i> Catálogo de APIs</a>
        </header>
        <div class="row">
            <div class="col-12">
                <section class="card consumer-card">
                    <div class="card-header bg-white border-0 pt-4 px-4">
                        <div class="consumer-connection-heading"><h2 class="card-title h5"><i class="fas fa-plug text-muted mr-2" aria-hidden="true"></i>Preparar solicitud</h2><span class="consumer-permission"><i class="fas fa-key mr-1" aria-hidden="true"></i>{{ $api['ability'] }}</span></div>
                        <p class="text-muted small mb-0 mt-2">Conecta una credencial autorizada para consultar los datos de este servicio.</p>
                    </div>
                    <div class="card-body px-4 pt-2 pb-4">
                        <div class="consumer-request">
                            <div class="form-group">
                                <label for="apiConsumerUrl">URL y parámetros</label>
                                <input id="apiConsumerUrl" class="form-control form-control-sm" type="url" spellcheck="false">
                                <small class="form-text text-muted">Se completa con un ejemplo de esta ruta; ajusta código, fechas u oficina a los datos de tu entorno.</small>
                            </div>
                            <div class="form-group">
                                <label for="apiConsumerToken">Token Bearer</label>
                                <div class="input-group input-group-sm">
                                    <input id="apiConsumerToken" class="form-control" type="password" autocomplete="off" spellcheck="false" placeholder="Pega una credencial autorizada">
                                    <div class="input-group-append"><button class="btn btn-outline-secondary" id="apiConsumerToggleToken" type="button" aria-label="Mostrar token"><i class="far fa-eye"></i></button></div>
                                </div>
                                <small class="form-text text-muted">Se usa solo mientras esta vista esté abierta; no se guarda.</small>
                            </div>
                            <div id="apiConsumerBodyGroup" class="form-group">
                                <label for="apiConsumerBody">Cuerpo JSON</label>
                                <textarea id="apiConsumerBody" class="form-control form-control-sm" rows="11" spellcheck="false"></textarea>
                            </div>
                            <div id="apiConsumerWriteGuard" class="consumer-token-warning mb-3 d-none">
                                <strong><i class="fas fa-exclamation-triangle mr-1"></i>Esta ruta puede escribir datos en IPS.</strong>
                                <div class="custom-control custom-checkbox mt-1"><input class="custom-control-input" type="checkbox" id="apiConsumerWriteConfirmed"><label class="custom-control-label small" for="apiConsumerWriteConfirmed">Confirmo que enviaré esta operación real.</label></div>
                            </div>
                            <button id="apiConsumerSend" class="btn btn-primary btn-block" type="button"><i class="fas fa-sync-alt mr-1"></i> {{ ($api['consumer']['write'] ?? false) ? 'Enviar operación real' : 'Consultar API' }}</button>
                            <div id="apiConsumerFeedback" class="consumer-muted mt-2" role="status">La consulta solo se ejecuta al presionar el botón.</div>
                        </div>
                    </div>
                </section>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <section class="consumer-stage consumer-stage--{{ $api['consumer_view'] }} consumer-card">
                    <div id="consumerEmpty" class="consumer-empty">
                        <i class="fas {{ $api['consumer_view'] === 'tracking' ? 'fa-route' : ($api['consumer_view'] === 'customs' ? 'fa-boxes' : 'fa-shipping-fast') }}"></i>
                        <strong>La información de esta operación aparecerá aquí.</strong>
                        <span class="small mt-1">Conecta una credencial autorizada y consulta para ver todos los datos recibidos.</span>
                    </div>
                    <div id="consumerContent" class="d-none"></div>
                </section>
            </div>
        </div>
    </div>
@stop

@section('js')
    <script>
        const apiConsumerMethod = @json($api['method']);
        const apiConsumerUrlBase = @json($endpointUrl);
        const apiConsumerConfig = @json($api['consumer']);
        const apiConsumerUrl = document.getElementById('apiConsumerUrl');
        const apiConsumerToken = document.getElementById('apiConsumerToken');
        const apiConsumerBody = document.getElementById('apiConsumerBody');
        const apiConsumerWriteConfirmed = document.getElementById('apiConsumerWriteConfirmed');
        const apiConsumerSend = document.getElementById('apiConsumerSend');
        const labels = {
            identifier: 'Identificador', codigo: 'Código', codigo_s10: 'Código S10', local_identifier: 'Código local',
            type: 'Tipo', tipo_servicio: 'Servicio', status: 'Estado', estado_postal: 'Estado postal',
            origin: 'Origen', origen: 'Origen', destination: 'Destino', destino: 'Destino',
            origin_ctci: 'CTCI origen', destination_ctci: 'CTCI destino', destination_country_code: 'País destino',
            occurred_at: 'Fecha y hora', eventDate: 'Fecha y hora', eventDateUtc: 'Fecha UTC', eventDateLocal: 'Fecha local',
            eventType: 'Movimiento', eventType_nm: 'Movimiento', evento_api: 'Movimiento', name: 'Descripción',
            office: 'Oficina', office_name: 'Nombre oficina', nextOffice: 'Siguiente oficina',
            weight_kg: 'Peso (kg)', inward_parcels_weight_kg: 'Peso declarado (kg)',
            sacks_count: 'Sacas', sacks_linked: 'Sacas vinculadas', packages_declared: 'Paquetes declarados',
            packages_identified: 'Paquetes S10 con código registrado', declarations_count: 'Declaraciones CDS',
            sacks_physically_received: 'Sacas con recepción registrada en IPS', arrival_status: 'Estado de recepción',
            sacks_without_receipt_event: 'Sacas sin recepción registrada en IPS',
            declaration_coverage: 'Cobertura CDS', number: 'Número', state: 'Estado', decision: 'Decisión comunicada por CDS',
            inward_parcels_count: 'Paquetes declarados en el despacho', dispatch_sequence: 'Secuencia del despacho',
            packages_declared_complete: 'Conteo declarado completo', sacks_weight_complete: 'Peso completo',
            sacks_weight_total_kg: 'Peso total de las sacas (kg)', sacks_with_count_mismatch: 'Sacas con conteo distinto al declarado',
            packages_with_cds_declaration: 'Paquetes con declaración CDS', predes_sacks_complete: 'Sacas PREDES completas',
            predes_items_complete: 'Paquetes PREDES completos', cds_lookup_complete: 'Consulta CDS completa', cds_truncated: 'Consulta CDS truncada',
            ips_predes: 'IPS · PREDES',
            number_of_items_declared: 'Paquetes S10 declarados en PREDES', items_identified: 'Paquetes S10 con código registrado', count_matches: 'Conteo coincide',
            physical_receipt: 'Recepción física IPS', class_code: 'Clase postal', content_format_code: 'Formato del contenido',
            subtype_code: 'Subtipo', exported_indicator: 'Indicador de exportación', net_weight_indicator: 'Peso neto',
            exempt_indicator: 'Exención', dispatch_document_indicator: 'Documento de despacho', other_characteristics: 'Otras características',
            origin_country_code: 'País de origen', destination_id: 'Identificador de destino', insured_value_sdr: 'Valor asegurado (DEG)',
            handling_class: 'Clase de manipulación', origin_operator: 'Operador de origen', lookup_status: 'Estado de consulta CDS',
            declarations: 'Declaraciones CDS', responses: 'Respuestas CDS', pieces: 'Artículos declarados', documents: 'Documentos',
            data: 'Información', fields: 'Campos', content_summary: 'Resumen del contenido', workflow_stage: 'Etapa del flujo',
            data_status: 'Estado de los datos', piece_count: 'Cantidad de artículos', document_count: 'Cantidad de documentos',
            decision_code: 'Código de decisión', packages: 'Paquetes S10', sacks: 'Sacas S9',
            transport_legs: 'Tramos de transporte', dispatch_event_at: 'Evento del despacho', dispatch_date: 'Fecha de despacho',
            arrival_date: 'Fecha estimada de llegada', estimated_indicator: 'Llegada estimada', origin_airport_code: 'Aeropuerto de origen',
            destination_airport_code: 'Aeropuerto de destino', actual_location_code: 'Ubicación registrada', actual_local_at: 'Hora local de recepción',
            inbound_dispatch_date: 'Fecha de despacho entrante', master_air_waybill: 'Guía aérea maestra', transport_number: 'Número de transporte',
            city: 'Ciudad', ciudad: 'Ciudad', country: 'País', pais: 'País', country_code: 'Código país',
            date: 'Fecha', created_at: 'Creado', updated_at: 'Actualizado',
            user_pid: 'PID usuario', user_fid: 'Usuario', user_name: 'Nombre', user_domain: 'Dominio', user_type: 'Tipo de usuario',
            office_cd: 'Código de oficina', office_fcd: 'Código postal de oficina', valid_ind: 'Vigencia',
            despacho: 'Despacho', dispatch_closed_at: 'Cierre del despacho', items_count: 'Paquetes declarados',
            items_count_source: 'Origen del conteo', declared_items_count: 'Declarados', registered_items_count: 'Registrados',
            remaining_items_count: 'Pendientes de registro', registration_status: 'Conciliación', allowed_actions: 'Acciones permitidas',
            local_code: 'Código local', event_at: 'Fecha del evento', event_cd: 'Código de evento', event_name: 'Evento postal',
            postal_status: 'Estado postal', next_office_name: 'Siguiente oficina', destination_country: 'País destino',
            MAILITM_FID: 'Código S10', MAILITM_LOCAL_ID: 'Código local', MAILITM_WEIGHT: 'Peso (kg)',
            MAIL_CLASS_NM: 'Clase postal', POSTAL_STATUS_NM: 'Estado postal', EVENT_NAME_ES: 'Último movimiento', EVT_GMT_DT: 'Fecha del evento',
            EVENT_TYPE_CD: 'Código de evento', EVENT_TYPE_NM: 'Nombre del evento', LOCAL_EVENT_TYPE_NM: 'Nombre del evento (ES)',
            OWN_OFFICE_CD: 'Código de oficina', OFFICE_FCD: 'Código postal de oficina', OFFICE_NM: 'Nombre de oficina',
            COUNTRY_CD: 'Código de país', COUNTRY_NM: 'País', DELIVERY_AGENT_CD: 'Código de cartero', DELIVERY_AGENT_NM: 'Cartero',
            MAIL_CLASS_CD: 'Código de clase', ITEM_CONDITION_CD: 'Código de condición', ITEM_CONDITION_NM: 'Condición',
            NON_DELIVERY_REASON_CD: 'Código de motivo', NON_DELIVERY_REASON_NM: 'Motivo de no entrega',
            NON_DELIVERY_MEASURE_CD: 'Código de medida', NON_DELIVERY_MEASURE_NM: 'Medida de no entrega',
            dispatch: 'Despacho', dispatches: 'Despachos', dispatch_number: 'Número de despacho', departure_at: 'Salida del despacho',
            summary: 'Resumen', related_identifiers: 'Códigos relacionados', identifier_analysis: 'Datos del código', details: 'Detalle',
            sources: 'Fuentes consultadas', source_status: 'Disponibilidad de las fuentes', source: 'Origen del registro',
            sacks_declared: 'Sacas declaradas', packages_linked: 'Paquetes vinculados',
            declared_package_count: 'Paquetes declarados', declarations_status: 'Disponibilidad de CDS',
            events_count: 'Movimientos registrados', movement_count: 'Movimientos registrados', delivery_count: 'Entregas registradas',
            sack_count: 'Sacas relacionadas', manifest_count: 'Manifiestos relacionados', content_item_count: 'Artículos declarados',
            predes_closing_at: 'Cierre del manifiesto PREDES', received_at: 'Fecha de recepción física',
            registration_number: 'Número de registro', insurance_number: 'Número de seguro', seal_number: 'Número de sello',
            subclass: 'Subclase postal', characteristic: 'Características', content_format: 'Formato del contenido',
            documentation_enclosed: 'Documentación incluida', comments: 'Observaciones', status_code: 'Código de estado',
            postal_status_code: 'Código de estado postal', last_event: 'Último movimiento', last_movement: 'Último movimiento postal',
            transport: 'Medio de transporte', flight: 'Vuelo', scheduled_departure_at: 'Salida programada',
            scheduled_arrival_at: 'Llegada programada', carrier_code: 'Código de transportista', carrier_number: 'Número de transporte',
            transport_type_code: 'Tipo de transporte', office_code: 'Código de oficina',
            category_code: 'Categoría postal', associated_service_code: 'Servicio postal asociado',
            customs_number: 'Número aduanero', duties_amount: 'Importe de derechos', value: 'Valor declarado',
            currency: 'Moneda', product_type: 'Tipo de producto', content: 'Contenido postal',
            nature: 'Naturaleza de la declaración', countries: 'Países declarados', truncated: '¿Hay datos pendientes?',
            complete: 'Consulta completa',
            filters: 'Filtros de consulta', pagination: 'Continuación', meta: 'Información de página',
            limit: 'Registros por página', has_more: 'Hay más resultados', next_cursor: 'Cursor de continuación', cursor: 'Cursor de continuación',
            detail_path: 'Ruta del detalle',
            page: 'Página', current_page: 'Página actual', last_page: 'Última página', per_page: 'Registros por página',
            total: 'Total de registros', count: 'Cantidad', success: 'Consulta exitosa', message: 'Mensaje', result: 'Resultado',
            items: 'Envíos', events: 'Movimientos', code: 'Código',
            class: 'Clase postal', code_analysis: 'Análisis del código',
            counts_match: 'Coincide con el conteo declarado',
            available: 'Disponible', unavailable: 'No disponible', enabled: 'Habilitado', disabled: 'No habilitado',
            matched: 'Coincide', pending: 'Pendiente', resolved: 'Resuelto',
            predes_closing_date_from: 'Cierre PREDES desde', predes_closing_date_to: 'Cierre PREDES hasta',
            local_codes: 'Códigos locales', local_identifiers: 'Códigos locales', role: 'Rol del contacto',
            address: 'Dirección', postal_code: 'Código postal', phone: 'Teléfono', email: 'Correo electrónico',
            delivery_records: 'Registros de entrega', signatory: 'Persona que recibió', location: 'Lugar de entrega',
            local_time_offset: 'Diferencia horaria local', non_delivery_reason_code: 'Motivo de no entrega',
            non_delivery_measure_code: 'Medida aplicada', event_code: 'Código del movimiento', event: 'Movimiento postal',
            captured_at: 'Fecha de registro', sender_id: 'Identificador del remitente', declaration_count: 'Declaraciones CDS',
            declarations_truncated: 'Declaraciones CDS incompletas', packages_truncated: 'Paquetes CDS incompletos',
            missing_codes: 'Códigos sin coincidencia', without_declaration_codes: 'Paquetes sin declaración',
            deleted_declaration_codes: 'Declaraciones eliminadas', unconfirmed_codes: 'Códigos sin confirmar',
            id: 'Identificador del registro', category: 'Categoría', flow: 'Flujo postal', posted_at: 'Fecha de admisión',
            declared_gross_weight: 'Peso bruto declarado (kg)', sender_reference: 'Referencia aduanera del remitente',
            recipient_reference: 'Referencia aduanera del destinatario', quantity: 'Cantidad', description: 'Descripción del artículo',
            declared_value: 'Valor declarado', net_weight_kg: 'Peso neto (kg)', tariff_heading: 'Partida arancelaria',
            customs: 'Datos aduaneros', declared_content: 'Contenido declarado', logistics: 'Información logística',
            customers: 'Remitente y destinatario',
            sack: 'Saca postal S9', package: 'Paquete postal S10', cds: 'Datos CDS', office: 'Oficina IPS',
            manifests: 'Manifiestos relacionados', scan_status: 'Estado de escaneo', scanned_at: 'Fecha de escaneo',
            package_count: 'Paquetes vinculados', declared_count: 'Cantidad declarada', linked_count: 'Cantidad vinculada',
            reconciliation: 'Conciliación de cantidades', state_cd: 'Código de estado operativo',
            user: 'Usuario', user_code: 'Código de usuario', kind: 'Tipo de evento', next_office: 'Siguiente oficina', detail: 'Detalle del evento',
            observation: 'Observación',
            SNm: 'Nombre del remitente', SAdL1: 'Dirección del remitente · línea 1', SAdL2: 'Dirección del remitente · línea 2',
            SZip: 'Código postal del remitente', SCty: 'Ciudad del remitente', SSta: 'Departamento o región del remitente',
            SCtr: 'País de origen del envío', STel: 'Teléfono del remitente', SEml: 'Correo del remitente', SIdR: 'Referencia aduanera del remitente',
            RNm: 'Nombre del destinatario', RAdL1: 'Dirección del destinatario · línea 1', RAdL2: 'Dirección del destinatario · línea 2',
            RZip: 'Código postal del destinatario', RCty: 'Ciudad del destinatario', RSta: 'Departamento o región del destinatario',
            RCtr: 'País de destino del envío', RTel: 'Teléfono del destinatario', REml: 'Correo del destinatario', RIdR: 'Referencia aduanera del destinatario',
            GWgt: 'Peso bruto declarado (kg)', TotNWgt: 'Peso neto total declarado (kg)', TotCPNo: 'Cantidad total de artículos declarados',
            TotCPVal: 'Valor total declarado', TotalCPValCur: 'Moneda del valor total', Ptg: 'Franqueo postal', PtgCur: 'Moneda del franqueo',
            IV: 'Valor del seguro', IvC: 'Moneda del seguro', Obs: 'Observaciones de la declaración',
            Desc: 'Descripción del artículo', RDesc: 'Descripción del artículo', No: 'Unidades del artículo', NWgt: 'Peso neto del artículo (kg)',
            Amt: 'Valor declarado del artículo', Cur: 'Moneda del artículo', HS: 'Partida arancelaria', RHS: 'Partida arancelaria',
            OCtr: 'País de origen del artículo', Ref: 'Referencia del documento', MailNo: 'Código del envío declarado', Decis: 'Código de decisión CDS',
            DecisReasNm: 'Motivo de la decisión CDS', NTyp: 'Código de naturaleza del envío', NTypDesc: 'Naturaleza del envío',
        };

        function escapeHtml(value) {
            return String(value ?? '—').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char]));
        }

        function displayFieldValue(value, field = '') {
            if (value === null) return 'null';
            if (value === undefined) return 'undefined';
            if (value === '') return '""';
            if (typeof value === 'string' && value.trim() === '') return JSON.stringify(value);
            if (typeof value === 'boolean') return value ? 'true' : 'false';
            return String(value);
        }

        function fieldLabel(key) {
            const text = String(key).replace(/([a-z0-9])([A-Z])/g, '$1 $2').replaceAll('_', ' ').replace(/\s+/g, ' ').trim();
            return labels[key] || text.replace(/^./, (character) => character.toLocaleUpperCase());
        }

        function renderRecordCard(item, label, ignored) {
            const type = fieldLabel(label || 'Registro');
            const headingKey = ['identifier', 'codigo', 'code', 'MAILITM_FID', 'RECPTCL_FID', 'number', 'name', 'event', 'event_name', 'occurred_at', 'EVENT_GMT_DT'].find((key) => item && Object.prototype.hasOwnProperty.call(item, key) && item[key] !== null && item[key] !== '');
            const recordTitle = headingKey ? displayFieldValue(item[headingKey], headingKey) : 'Registro recibido';
            const recordFields = headingKey && item && typeof item === 'object' && !Array.isArray(item)
                ? Object.fromEntries(Object.entries(item).filter(([key]) => key !== headingKey))
                : item;
            const titleLabel = headingKey ? fieldLabel(headingKey) : '';
            const recordContent = recordFields && typeof recordFields === 'object' && !Array.isArray(recordFields) && !Object.keys(recordFields).length
                ? ''
                : `<div class="consumer-data-record-content">${renderCompleteValue('', recordFields, ignored)}</div>`;
            return `<article class="consumer-data-item"><header class="consumer-data-record-header"><span class="consumer-data-record-icon"><i class="fas ${consumerIconFor(label)}" aria-hidden="true"></i></span><div class="consumer-data-record-title"><span>${escapeHtml(type)}</span><strong>${escapeHtml(titleLabel ? `${titleLabel}: ${recordTitle}` : recordTitle)}</strong></div></header>${recordContent}</article>`;
        }

        const cdsFieldGroups = [
            { title: 'Remitente declarado', fields: ['SNm', 'SAdL1', 'SAdL2', 'SZip', 'SCty', 'SSta', 'SCtr', 'STel', 'SEml', 'SIdR'] },
            { title: 'Destinatario declarado', fields: ['RNm', 'RAdL1', 'RAdL2', 'RZip', 'RCty', 'RSta', 'RCtr', 'RTel', 'REml', 'RIdR'] },
            { title: 'Contenido, peso y valor', fields: ['NTyp', 'NTypDesc', 'GWgt', 'TotNWgt', 'TotCPNo', 'TotCPVal', 'TotalCPValCur', 'Ptg', 'PtgCur', 'IV', 'IvC', 'Obs'] },
            { title: 'Decisión CDS', fields: ['Decis', 'DecisReasNm'] },
        ];

        function renderCdsFieldGroups(fields, ignored) {
            const consumed = new Set();
            const groups = cdsFieldGroups.map((group) => {
                const values = Object.fromEntries(group.fields.filter((key) => Object.prototype.hasOwnProperty.call(fields, key)).map((key) => {
                    consumed.add(key);
                    return [key, fields[key]];
                }));
                if (!Object.keys(values).length) return '';
                return `<section class="consumer-data-node"><div class="consumer-data-node-title">${escapeHtml(group.title)}</div>${renderCompleteValue('', values, ignored)}</section>`;
            }).join('');
            const remaining = Object.fromEntries(Object.entries(fields).filter(([key]) => !consumed.has(key) && !ignored.includes(key)));
            const other = Object.keys(remaining).length
                ? `<section class="consumer-data-node"><div class="consumer-data-node-title">Otros datos de la declaración</div>${renderCompleteValue('', remaining, ignored)}</section>`
                : '';
            return `<section class="consumer-data-node">${groups}${other}</section>`;
        }

        function renderCompleteValue(label, value, ignored = []) {
            const heading = label ? `<div class="consumer-data-node-title">${escapeHtml(label)}</div>` : '';
            if (value === null || value === undefined || typeof value !== 'object') {
                return `<div class="consumer-data-field"><span class="consumer-key">${escapeHtml(fieldLabel(label || 'Dato'))}</span><strong>${escapeHtml(displayFieldValue(value, label))}</strong></div>`;
            }
            if (Array.isArray(value)) {
                if (!value.length) return `<section class="consumer-data-node">${heading}<div class="consumer-data-field"><strong>[]</strong></div></section>`;
                if (value.every((item) => item === null || typeof item !== 'object')) {
                    return `<section class="consumer-data-node">${heading}<div class="d-flex flex-wrap">${value.map((item) => `<span class="badge badge-light border mr-1 mb-1">${escapeHtml(displayFieldValue(item, label))}</span>`).join('')}</div></section>`;
                }
                return `<section class="consumer-data-node">${heading}<div class="consumer-data-collection">${value.map((item) => renderRecordCard(item, label, ignored)).join('')}</div></section>`;
            }
            if ((label === 'fields' || label === fieldLabel('fields')) && cdsFieldGroups.some((group) => group.fields.some((key) => Object.prototype.hasOwnProperty.call(value, key)))) {
                return renderCdsFieldGroups(value, ignored);
            }
            const entries = Object.entries(value).filter(([key]) => !ignored.includes(key));
            const scalarEntries = entries.filter(([, item]) => item === null || typeof item !== 'object');
            const nestedEntries = entries.filter(([, item]) => item !== null && typeof item === 'object');
            const scalarFields = scalarEntries.map(([key, item]) => `<div class="consumer-data-field"><span class="consumer-key">${escapeHtml(fieldLabel(key))}</span><strong>${escapeHtml(displayFieldValue(item, key))}</strong></div>`).join('');
            const nestedFields = nestedEntries.map(([key, item]) => renderCompleteValue(fieldLabel(key), item, ignored)).join('');
            return `<section class="consumer-data-node">${heading}${scalarFields ? `<div class="consumer-key-grid">${scalarFields}</div>` : ''}${nestedFields || (!scalarFields && !entries.length ? '<div class="consumer-data-field"><strong>{}</strong></div>' : '')}</section>`;
        }

        function consumerIconFor(value) {
            const key = String(value || '').toLocaleLowerCase();
            if (/prealert|predes|inbox|dispatches/.test(key)) return 'fa-inbox';
            if (/dispatch|despacho|transport|flight/.test(key)) return 'fa-truck';
            if (/sack|saca|receptacle|envase/.test(key)) return 'fa-archive';
            if (/package|paquete|item|shipment|envio|envío/.test(key)) return 'fa-box';
            if (/declaration|declaracion|declaración|response|respuesta|document|cds/.test(key)) return 'fa-file-alt';
            if (/event|movement|historial|tracking|route|ruta/.test(key)) return 'fa-route';
            if (/source|fuente|status|disponibilidad/.test(key)) return 'fa-database';
            if (/filter|period|fecha|date/.test(key)) return 'fa-calendar-alt';
            if (/pagination|page|continuation|continuación|meta/.test(key)) return 'fa-list-ol';
            if (/identifier|codigo|código|code|lookup|busqueda|búsqueda/.test(key)) return 'fa-barcode';
            if (/summary|resumen|count|conteo|metric/.test(key)) return 'fa-chart-bar';
            if (/complete|coverage|cobertura|truncated|recort/.test(key)) return 'fa-check-circle';
            if (/user|usuario|person|personal/.test(key)) return 'fa-user';
            if (/office|oficina|catalog|catálogo/.test(key)) return 'fa-building';
            if (/delivery|entrega/.test(key)) return 'fa-shipping-fast';
            return 'fa-list-ul';
        }

        const responseLayouts = {
            'tracking.single': [
                { title: 'Envío consultado', icon: 'fa-barcode', fields: ['codigo', 'tipo_servicio', 'origen', 'destino', 'pais_destino', 'country_destino'] },
                { title: 'Estado y ruta', icon: 'fa-map-marked-alt', fields: ['meta', 'estado_postal', 'codigo_estado_postal'] },
                { title: 'Movimientos recibidos', icon: 'fa-route', fields: ['eventos_externos'] },
            ],
            'tracking.history': [
                { title: 'Envío consultado', icon: 'fa-barcode', fields: ['codigo', 'meta'] },
                { title: 'Historial recibido', icon: 'fa-history', fields: ['eventos', 'eventos_externos', 'data'] },
            ],
            'tracking.batch': [
                { title: 'Resultados por envío', icon: 'fa-boxes', fields: ['resultados', 'results', 'items', 'data'] },
                { title: 'Información de la consulta', icon: 'fa-list-ol', fields: ['meta', 'pagination'] },
            ],
            'tracking.destinations': [
                { title: 'Destinos recibidos', icon: 'fa-map-marked-alt', fields: ['resultados', 'results', 'destinos', 'destinations', 'items', 'data'] },
                { title: 'Información de la consulta', icon: 'fa-list-ol', fields: ['meta', 'pagination'] },
            ],
            'tracking.packages': [
                { title: 'Paquetes recibidos', icon: 'fa-boxes', fields: ['data', 'packages', 'paquetes'] },
                { title: 'Continuación de la lista', icon: 'fa-list-ol', fields: ['meta', 'pagination', 'links'] },
            ],
            'tracking.cds-list': [
                { title: 'Declaraciones CDS recibidas', icon: 'fa-file-alt', fields: ['data', 'packages', 'declarations', 'declaraciones'] },
                { title: 'Continuación de la lista', icon: 'fa-list-ol', fields: ['meta', 'pagination', 'links'] },
            ],
            'tracking.cds-batch': [
                { title: 'Resultado por código enviado', icon: 'fa-file-medical-alt', fields: ['data', 'results', 'results_by_code', 'packages', 'declarations'] },
            ],
            'customs.prealerts': [
                { title: 'Manifiestos con sacas pendientes de recepción registrada', icon: 'fa-inbox', fields: ['dispatches'] },
            ],
            'customs.prealert': [
                { title: 'Despacho anunciado', icon: 'fa-truck', fields: ['identifier', 'type', 'status', 'dispatch'] },
                { title: 'Sacas y paquetes declarados', icon: 'fa-archive', fields: ['sacks'] },
                { title: 'Datos recibidos del servicio', icon: 'fa-list-alt', fields: ['summary'] },
                { title: 'Estado de la información', icon: 'fa-database', fields: ['source_status', 'completeness'] },
            ],
            'customs.dispatch': [
                { title: 'Despachos IPS encontrados', icon: 'fa-truck', fields: ['dispatches'] },
                { title: 'Identificación y cobertura', icon: 'fa-barcode', fields: ['identifier', 'dispatch_number', 'truncated'] },
            ],
            'customs.receptacle': [
                { title: 'Saca S9', icon: 'fa-archive', fields: ['identifier', 'sack'] },
                { title: 'Referencias relacionadas', icon: 'fa-link', fields: ['related_identifiers'] },
                { title: 'Movimientos y CDS', icon: 'fa-file-alt', fields: ['summary'] },
                { title: 'Cobertura de la consulta', icon: 'fa-check-circle', fields: ['truncated'] },
            ],
            'customs.package': [
                { title: 'Paquete S10', icon: 'fa-box', fields: ['identifier', 'package'] },
                { title: 'Información relacionada', icon: 'fa-link', fields: ['related_identifiers'] },
                { title: 'Movimientos, sacas y CDS', icon: 'fa-route', fields: ['summary', 'movements'] },
                { title: 'Fuentes y cobertura', icon: 'fa-database', fields: ['sources', 'truncated'] },
            ],
            'customs.declarations': [
                { title: 'Paquetes y declaraciones CDS', icon: 'fa-file-alt', fields: ['identifier', 'packages'] },
                { title: 'Disponibilidad de los datos', icon: 'fa-database', fields: ['source_status', 'truncated', 'packages_truncated', 'declarations_truncated'] },
            ],
            'customs.lookup': [
                { title: 'Código identificado', icon: 'fa-barcode', fields: ['type', 'identifier', 'identifier_analysis'] },
                { title: 'Detalle postal recibido', icon: 'fa-box-open', fields: ['details'] },
            ],
            'ips.catalog': [
                { title: 'Referencias operativas', icon: 'fa-book', fields: ['events', 'operations', 'offices', 'mail_classes', 'countries', 'item_conditions'] },
                { title: 'Entrega y aduana', icon: 'fa-clipboard-list', fields: ['non_delivery_reasons', 'non_delivery_measures', 'delivery_agents', 'customs_office_types', 'customs_release_statuses'] },
                { title: 'Sacas disponibles en oficina', icon: 'fa-archive', fields: ['open_inbound_receptacles'] },
            ],
            'ips.delivery-catalog': [
                { title: 'Datos para la entrega', icon: 'fa-shipping-fast', fields: ['delivery_agents', 'offices', 'non_delivery_reasons', 'non_delivery_measures'] },
            ],
            'ips.users': [
                { title: 'Usuarios recibidos', icon: 'fa-users', fields: ['data', 'users'] },
                { title: 'Paginación', icon: 'fa-list-ol', fields: ['meta', 'pagination'] },
            ],
            'ips.user': [
                { title: 'Ficha del usuario', icon: 'fa-user', fields: ['data', 'user'] },
            ],
            'ips.packages': [
                { title: 'Paquetes postales', icon: 'fa-boxes', fields: ['data', 'packages'] },
                { title: 'Paginación', icon: 'fa-list-ol', fields: ['meta', 'pagination'] },
            ],
            'ips.pending-delivery': [
                { title: 'Entregas pendientes', icon: 'fa-shipping-fast', fields: ['data', 'packages'] },
                { title: 'Paginación', icon: 'fa-list-ol', fields: ['meta', 'pagination'] },
            ],
            'ips.queue-opening': [
                { title: 'Sacas pendientes de apertura', icon: 'fa-door-open', fields: ['data', 'receptacles'] },
                { title: 'Paginación', icon: 'fa-list-ol', fields: ['meta', 'pagination'] },
            ],
            'ips.queue-reception': [
                { title: 'Sacas pendientes de recepción', icon: 'fa-inbox', fields: ['data', 'receptacles'] },
                { title: 'Paginación', icon: 'fa-list-ol', fields: ['meta', 'pagination'] },
            ],
            'ips.queue-registration': [
                { title: 'Sacas abiertas y registro de paquetes', icon: 'fa-clipboard-check', fields: ['data', 'receptacles'] },
                { title: 'Paginación', icon: 'fa-list-ol', fields: ['meta', 'pagination'] },
            ],
            'ips.receptacle': [
                { title: 'Saca en IPS', icon: 'fa-archive', fields: ['data', 'receptacle'] },
                { title: 'Paquetes y movimientos recibidos', icon: 'fa-boxes', fields: ['items', 'events'] },
                { title: 'Registro por oficina', icon: 'fa-building', fields: ['office'] },
            ],
            'ips.package': [
                { title: 'Ficha del paquete', icon: 'fa-box', fields: ['data', 'package'] },
                { title: 'Movimientos postales', icon: 'fa-route', fields: ['events'] },
            ],
            'ips.operation': [
                { title: 'Resultado de la operación', icon: 'fa-clipboard-check', fields: ['data', 'operation'] },
            ],
            'ips.write': [
                { title: 'Respuesta de IPS', icon: 'fa-edit', fields: ['data', 'operation', 'status', 'message', 'codigo'] },
            ],
        };

        function renderResponseGroup(group, fields) {
            if (!Object.keys(fields).length) return '';
            return `<section class="consumer-response-section"><header class="consumer-response-section-header"><i class="fas ${escapeHtml(group.icon || 'fa-list-ul')}" aria-hidden="true"></i><span>${escapeHtml(group.title)}</span></header><div class="consumer-response-section-body">${renderCompleteValue('', fields, [])}</div></section>`;
        }

        function renderStructuredPayload(payload, groups) {
            if (payload === null || payload === undefined || typeof payload !== 'object' || Array.isArray(payload)) {
                return renderResponseGroup({ title: 'Dato recibido', icon: 'fa-info-circle' }, { result: payload });
            }

            const consumed = new Set();
            const sections = (groups || []).map((group) => {
                const fields = Object.fromEntries(group.fields.filter((key) => Object.prototype.hasOwnProperty.call(payload, key)).map((key) => {
                    consumed.add(key);
                    return [key, payload[key]];
                }));
                return renderResponseGroup(group, fields);
            }).join('');
            const remaining = Object.fromEntries(Object.entries(payload).filter(([key]) => !consumed.has(key)));
            const other = Object.keys(remaining).length
                ? renderResponseGroup({ title: 'Otros datos recibidos', icon: 'fa-list-ul' }, remaining)
                : '';
            return `${sections}${other}` || renderResponseGroup({ title: 'Datos recibidos', icon: 'fa-info-circle' }, payload);
        }

        function renderConsumer(response, formattedJson = '', linkHeader = '') {
            const renderer = apiConsumerConfig.renderer;
            const rendererIcons = {
                'tracking.single': 'fa-route', 'tracking.batch': 'fa-boxes', 'tracking.destinations': 'fa-map-marked-alt',
                'tracking.history': 'fa-history', 'tracking.packages': 'fa-boxes', 'tracking.cds-list': 'fa-file-alt',
                'customs.prealerts': 'fa-inbox', 'customs.prealert': 'fa-boxes', 'customs.dispatch': 'fa-truck',
                'customs.receptacle': 'fa-archive', 'customs.package': 'fa-box', 'customs.declarations': 'fa-file-alt',
                'customs.lookup': 'fa-barcode',
                'tracking.cds-batch': 'fa-file-medical-alt', 'ips.catalog': 'fa-book', 'ips.delivery-catalog': 'fa-clipboard-list',
                'ips.users': 'fa-users', 'ips.user': 'fa-user', 'ips.packages': 'fa-boxes',
                'ips.pending-delivery': 'fa-shipping-fast', 'ips.queue-opening': 'fa-door-open',
                'ips.queue-reception': 'fa-inbox', 'ips.queue-registration': 'fa-clipboard-check',
                'ips.receptacle': 'fa-archive', 'ips.package': 'fa-box', 'ips.operation': 'fa-clipboard-check',
                'ips.write': 'fa-edit',
            };
            const title = apiConsumerConfig.title || 'Respuesta recibida';
            const icon = rendererIcons[renderer] || (apiConsumerConfig.domain === 'tracking' ? 'fa-route' : (apiConsumerConfig.domain === 'ips' ? 'fa-shipping-fast' : 'fa-list-alt'));
            const hasDataEnvelope = response && typeof response === 'object' && !Array.isArray(response)
                && Object.prototype.hasOwnProperty.call(response, 'data')
                && response.data !== null && typeof response.data === 'object' && !Array.isArray(response.data);
            const payload = hasDataEnvelope ? response.data : response;
            const groups = responseLayouts[renderer] || [];
            const dataPanel = `<div class="consumer-response-groups">${renderStructuredPayload(payload, groups)}</div>`;
            const extraEnvelope = hasDataEnvelope
                ? Object.fromEntries(Object.entries(response).filter(([key]) => key !== 'data'))
                : {};
            const extraPanel = Object.keys(extraEnvelope).length
                ? renderResponseGroup({ title: 'Información adicional recibida', icon: 'fa-list-ul' }, extraEnvelope)
                : '';
            const continuation = nextPageRequest(response, linkHeader);
            const continuationPanel = continuation
                ? `<div class="consumer-page-controls"><div><strong><i class="fas fa-list-ol mr-1" aria-hidden="true"></i>El servicio indica que hay más resultados</strong><div class="small">Continúa para consultar el siguiente grupo que devuelve esta API.</div></div><button type="button" class="btn btn-outline-primary" data-consumer-next-page><i class="fas fa-arrow-down mr-1" aria-hidden="true"></i>${escapeHtml(continuation.label)}</button></div>`
                : '';
            const exactBody = typeof formattedJson === 'string' ? formattedJson : JSON.stringify(response, null, 2);
            const viewTabs = '<div class="consumer-response-tabs" role="tablist" aria-label="Presentación de la respuesta"><button type="button" class="consumer-response-tab active" role="tab" aria-selected="true" data-consumer-view-toggle="organized"><i class="fas fa-th-large mr-1" aria-hidden="true"></i>Vista organizada</button><button type="button" class="consumer-response-tab" role="tab" aria-selected="false" data-consumer-view-toggle="json"><i class="fas fa-code mr-1" aria-hidden="true"></i>JSON completo</button></div>';
            const organizedPanel = `<div role="tabpanel" data-consumer-view-panel="organized">${dataPanel}${extraPanel}${continuationPanel}</div>`;
            const jsonPanel = `<div class="d-none" role="tabpanel" data-consumer-view-panel="json"><pre class="consumer-json-response" aria-label="Cuerpo JSON completo recibido del servicio">${escapeHtml(exactBody)}</pre></div>`;
            return `<div class="consumer-view consumer-report"><header class="consumer-report-heading"><span class="consumer-report-icon"><i class="fas ${escapeHtml(icon)}" aria-hidden="true"></i></span><div class="consumer-report-heading-copy"><div class="consumer-kicker">Respuesta recibida</div><h2>${escapeHtml(title)}</h2><div class="consumer-muted">Campos y registros devueltos por esta operación</div></div></header><div class="consumer-api-response">${viewTabs}${organizedPanel}${jsonPanel}</div></div>`;
        }

        function nextPageRequest(response, linkHeader = '') {
            if (apiConsumerMethod !== 'GET') return null;
            if (apiConsumerConfig.renderer === 'customs.prealerts') {
                const match = String(linkHeader || '').match(/<([^>]+)>\s*;\s*rel\s*=\s*"?next"?/i);
                if (!match) return null;
                try {
                    const currentUrl = new URL(apiConsumerUrl.value, window.location.origin);
                    const nextUrl = new URL(match[1], currentUrl);
                    if (nextUrl.origin !== currentUrl.origin || nextUrl.pathname !== currentUrl.pathname) return null;
                    return { url: nextUrl.toString(), label: 'Cargar siguiente grupo de prealertas' };
                } catch (error) {
                    return null;
                }
            }
            const customsPagination = response?.data?.pagination;
            if (customsPagination?.has_more && customsPagination.next_cursor) {
                return { key: 'cursor', value: customsPagination.next_cursor, label: 'Cargar siguiente grupo de prealertas' };
            }
            const meta = response?.meta || response?.data?.meta;
            if (!meta) return null;
            const currentPage = Number(meta.page ?? meta.current_page ?? 1);
            const hasMore = meta.has_more === true || (meta.last_page && currentPage < Number(meta.last_page));
            return hasMore ? { key: 'page', value: String(currentPage + 1), label: 'Cargar siguiente página' } : null;
        }

        function bindNextPage(response, linkHeader = '') {
            const next = nextPageRequest(response, linkHeader);
            const button = document.querySelector('[data-consumer-next-page]');
            if (!next || !button) return;
            button.addEventListener('click', () => {
                const url = next.url
                    ? new URL(next.url, window.location.origin)
                    : new URL(apiConsumerUrl.value, window.location.origin);
                if (next.key) url.searchParams.set(next.key, next.value);
                apiConsumerUrl.value = url.toString();
                apiConsumerSend.click();
            });
        }

        function bindResponseViews(content) {
            const toggles = content.querySelectorAll('[data-consumer-view-toggle]');
            const panels = content.querySelectorAll('[data-consumer-view-panel]');
            toggles.forEach((toggle) => toggle.addEventListener('click', () => {
                const selectedView = toggle.getAttribute('data-consumer-view-toggle');
                toggles.forEach((tab) => {
                    const active = tab.getAttribute('data-consumer-view-toggle') === selectedView;
                    tab.classList.toggle('active', active);
                    tab.setAttribute('aria-selected', String(active));
                });
                panels.forEach((panel) => panel.classList.toggle('d-none', panel.getAttribute('data-consumer-view-panel') !== selectedView));
            }));
        }

        function prettyPrintJson(source) {
            const text = String(source ?? '');
            let output = '';
            let depth = 0;
            let inString = false;
            let escaped = false;
            const newline = () => { output += `\n${' '.repeat(depth * 2)}`; };
            const nextNonWhitespace = (start) => {
                let index = start;
                while (index < text.length && /\s/.test(text[index])) index++;
                return text[index];
            };
            const previousNonWhitespace = (start) => {
                let index = start;
                while (index >= 0 && /\s/.test(text[index])) index--;
                return text[index];
            };

            for (let index = 0; index < text.length; index++) {
                const character = text[index];
                if (inString) {
                    output += character;
                    if (escaped) escaped = false;
                    else if (character === '\\') escaped = true;
                    else if (character === '"') inString = false;
                    continue;
                }

                if (/\s/.test(character)) continue;
                if (character === '"') {
                    inString = true;
                    output += character;
                    continue;
                }
                if (character === '{' || character === '[') {
                    output += character;
                    const closing = character === '{' ? '}' : ']';
                    if (nextNonWhitespace(index + 1) !== closing) {
                        depth++;
                        newline();
                    }
                    continue;
                }
                if (character === '}' || character === ']') {
                    const opening = character === '}' ? '{' : '[';
                    if (previousNonWhitespace(index - 1) !== opening) {
                        depth = Math.max(0, depth - 1);
                        newline();
                    }
                    output += character;
                    continue;
                }
                if (character === ',') {
                    output += character;
                    newline();
                    continue;
                }
                if (character === ':') {
                    output += ': ';
                    continue;
                }
                output += character;
            }

            return output;
        }

        function apiConsumerTimestamp() {
            const date = new Date(Date.now() - 5 * 60 * 1000);
            const pad = (value) => String(value).padStart(2, '0');
            const offset = -date.getTimezoneOffset();
            const sign = offset >= 0 ? '+' : '-';
            return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}${sign}${pad(Math.floor(Math.abs(offset) / 60))}:${pad(Math.abs(offset) % 60)}`;
        }

        function initialUrl() {
            const configured = new URL(apiConsumerUrlBase, window.location.origin);
            const samplePath = configured.pathname
                .replace(/%7Bcodigo%7D/gi, apiConsumerConfig.identifier || 'EE000738405BO')
                .replace(/%7Bpid%7D/gi, apiConsumerConfig.identifier || '1001')
                .replace(/%7Bid%7D/gi, apiConsumerConfig.identifier || '00000000-0000-4000-8000-000000000001');
            const url = new URL(configured.origin);
            url.pathname = samplePath;
            const today = new Date();
            const dateText = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
            Object.entries(apiConsumerConfig.query || {}).forEach(([key, original]) => {
                let value = String(original);
                if (value === '__TODAY__') value = dateText(today);
                if (value === '__DATE_7_DAYS_AGO__') value = dateText(new Date(today.getTime() - 6 * 86400000));
                url.searchParams.set(key, value);
            });
            return url.toString();
        }

        function sampleBody() {
            const stamp = apiConsumerTimestamp();
            const body = JSON.parse(JSON.stringify(apiConsumerConfig.body || {}));
            const hydrate = (value) => {
                if (Array.isArray(value)) return value.map(hydrate);
                if (value && typeof value === 'object') return Object.fromEntries(Object.entries(value).map(([key, item]) => [key, hydrate(item)]));
                if (value === '__NOW__') return stamp;
                if (value === '__IDEMPOTENCY_KEY__') return `demo-${Date.now()}`;
                return value;
            };
            return hydrate(body);
        }

        function showApiResponse(raw, linkHeader = '') {
            document.getElementById('consumerEmpty').classList.add('d-none');
            const content = document.getElementById('consumerContent');
            content.classList.remove('d-none');
            let response;
            let formattedJson = raw;
            try {
                response = JSON.parse(raw);
                formattedJson = prettyPrintJson(raw);
            } catch (error) {
                response = { message: raw };
            }
            content.innerHTML = renderConsumer(response, formattedJson, linkHeader);
            bindResponseViews(content);
            bindNextPage(response, linkHeader);
        }

        function showError(message, responseBody = undefined) {
            document.getElementById('consumerEmpty').classList.add('d-none');
            const content = document.getElementById('consumerContent');
            content.classList.remove('d-none');
            const details = responseBody !== undefined
                ? `<div class="consumer-api-response">${renderCompleteValue('', responseBody, [])}</div>`
                : '';
            const notice = message ? `<div class="alert alert-warning"><strong>${escapeHtml(message)}</strong></div>` : '';
            content.innerHTML = `${notice}${details}`;
        }

        apiConsumerUrl.value = initialUrl();
        document.getElementById('apiConsumerBodyGroup').classList.toggle('d-none', apiConsumerMethod === 'GET' || apiConsumerMethod === 'HEAD');
        document.getElementById('apiConsumerWriteGuard').classList.toggle('d-none', !apiConsumerConfig.write);
        if (apiConsumerMethod !== 'GET' && apiConsumerMethod !== 'HEAD') apiConsumerBody.value = JSON.stringify(sampleBody(), null, 2);

        document.getElementById('apiConsumerToggleToken').addEventListener('click', (event) => {
            const show = apiConsumerToken.type === 'password';
            apiConsumerToken.type = show ? 'text' : 'password';
            event.currentTarget.innerHTML = show ? '<i class="far fa-eye-slash"></i>' : '<i class="far fa-eye"></i>';
        });

        apiConsumerSend.addEventListener('click', async () => {
            const feedback = document.getElementById('apiConsumerFeedback');
            if (!apiConsumerToken.value.trim()) { feedback.textContent = 'Pega una credencial con el permiso indicado.'; feedback.className = 'text-danger small mt-2'; apiConsumerToken.focus(); return; }
            if (!document.getElementById('apiConsumerWriteGuard').classList.contains('d-none') && !apiConsumerWriteConfirmed.checked) { feedback.textContent = 'Confirma el envío real de esta operación IPS.'; feedback.className = 'text-danger small mt-2'; apiConsumerWriteConfirmed.focus(); return; }

            let url;
            try {
                url = new URL(apiConsumerUrl.value.trim());
                const configuredPath = new URL(apiConsumerUrlBase, window.location.origin).pathname.replace(/%7B([^/]+?)%7D/gi, '{$1}');
                const pattern = configuredPath.split('/').map((part) => /^\{[^/]+\}$/.test(part) ? '[^/]+' : part.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('/');
                if (url.origin !== window.location.origin || !new RegExp(`^${pattern}/?$`).test(url.pathname) || !url.pathname.includes('/api/')) throw new Error('Usa la ruta seleccionada en este mismo sitio.');
            } catch (error) { feedback.textContent = error.message || 'Revisa la URL de la API.'; feedback.className = 'text-danger small mt-2'; return; }

            let body = null;
            if (apiConsumerMethod !== 'GET' && apiConsumerMethod !== 'HEAD') {
                try { body = JSON.parse(apiConsumerBody.value); } catch (error) { feedback.textContent = `El cuerpo JSON no es válido: ${error.message}`; feedback.className = 'text-danger small mt-2'; return; }
            }

            apiConsumerSend.disabled = true;
            apiConsumerSend.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Consultando…';
            feedback.textContent = 'Consultando la información…'; feedback.className = 'consumer-muted mt-2';
            document.getElementById('consumerContent').classList.add('d-none');
            const controller = new AbortController();
            const timeout = window.setTimeout(() => controller.abort(), 30000);
            try {
                const headers = { Accept: 'application/json', Authorization: `Bearer ${apiConsumerToken.value.trim()}` };
                if (body !== null) { headers['Content-Type'] = 'application/json'; if (body.idempotency_key) headers['Idempotency-Key'] = body.idempotency_key; }
                const response = await fetch(url.toString(), { method: apiConsumerMethod, headers, body: body === null ? undefined : JSON.stringify(body), cache: 'no-store', signal: controller.signal });
                const raw = await response.text();
                const linkHeader = response.headers.get('Link') || '';
                if (!response.ok) {
                    showApiResponse(raw, linkHeader);
                    feedback.textContent = 'No se pudo actualizar la información.'; feedback.className = 'text-warning small mt-2';
                } else {
                    const content = document.getElementById('consumerContent');
                    document.getElementById('consumerEmpty').classList.add('d-none');
                    content.classList.remove('d-none');
                    try {
                        showApiResponse(raw, linkHeader);
                        feedback.textContent = 'Información actualizada con la respuesta del servicio.'; feedback.className = 'text-success small mt-2';
                    } catch (renderError) {
                        showError('El servicio respondió, pero no se pudo presentar la información en este panel.');
                        feedback.textContent = 'La respuesta llegó, pero no se pudo organizar en pantalla.'; feedback.className = 'text-warning small mt-2';
                    }
                }
            } catch (error) {
                showError(error.name === 'AbortError' ? 'La consulta tardó demasiado. Intenta nuevamente.' : 'No se pudo conectar con el servicio.');
                feedback.textContent = 'No se recibió una respuesta del servicio.'; feedback.className = 'text-danger small mt-2';
            } finally {
                window.clearTimeout(timeout);
                apiConsumerSend.disabled = false;
                apiConsumerSend.innerHTML = `<i class="fas fa-sync-alt mr-1"></i> ${apiConsumerConfig.write ? 'Enviar operación real' : 'Consultar API'}`;
            }
        });

        window.addEventListener('pagehide', () => { apiConsumerToken.value = ''; });
    </script>
@stop
