@extends('adminlte::page')
@section('title', 'Remisión de declaraciones a Aduana | SITRA')
@section('content_header')
<div class="postal-heading">
    <div><span class="postal-eyebrow">SITRA · GESTIÓN ADUANERA</span><h1>Preparar remisión a Aduana</h1><p>Busca un marbete o saca. SITRA carga sus paquetes IPS y marca cuáles tienen declaración CDS.</p></div>
    <a class="btn btn-outline-primary" href="{{ route('postal.cds') }}"><i class="fas fa-arrow-left"></i> Volver a CDS</a>
</div>
@stop
@section('content')
<div class="postal-workspace">
    <form class="postal-search postal-bag-search" method="get" action="{{ route('postal.customs.remittance') }}">
        <label for="customs-bag-code">Marbete o saca para preparar la remisión</label>
        <div class="postal-search-row"><i class="fas fa-barcode" aria-hidden="true"></i><input id="customs-bag-code" name="marbete" value="{{ $marbete }}" maxlength="80" autocomplete="off" placeholder="Escanea o escribe el número completo del marbete"><button class="btn btn-primary" type="submit"><i class="fas fa-search"></i> Buscar paquetes</button></div>
        <small class="postal-note">SITRA consultará los paquetes relacionados en IPS y comprobará cuáles tienen declaración en CDS.</small>
    </form>
    <details class="postal-manual-codes" @if($input && !$marbete) open @endif>
        <summary><i class="fas fa-keyboard"></i> Ya tengo los códigos de los paquetes</summary>
    <form class="postal-search" method="get" action="{{ route('postal.customs.remittance') }}">
        <label for="customs-codes">Códigos postales o identificadores locales</label>
        <textarea id="customs-codes" name="codigos" rows="5" maxlength="3000" required placeholder="Un código por línea; también puedes separarlos con coma o espacio">{{ $input }}</textarea>
        @error('codigos')<div class="text-danger mt-2" role="alert">{{ $message }}</div>@enderror
        <div class="d-flex justify-content-between align-items-center flex-wrap mt-3" style="gap:12px">
            <small class="postal-note mb-0">Hasta 50 identificadores por consulta. CDS busca coincidencias exactas y no modifica las declaraciones.</small>
            <button class="btn btn-primary" type="submit"><i class="fas fa-search"></i> Consultar declaraciones</button>
        </div>
    </form>

    </details>
    <div class="alert alert-info" role="note"><strong>Uso del listado:</strong> prepara una relación de trabajo con los datos encontrados en CDS. No envía declaraciones a Aduana ni reemplaza el formulario o formato oficial que Aduana solicite.</div>

    @if($error)<div class="alert alert-danger" role="alert">{{ $error }}</div>@endif
    @if($bagError && !$bagResult)<div class="alert alert-warning" role="alert">{{ $bagError }}</div>@endif

    @if($bagResult)
        @if(count($bagResult['receptacles']) > 1)
            <section class="postal-panel"><h2>El identificador coincide con varias sacas</h2><p>Elige el marbete exacto antes de revisar los paquetes.</p><div class="table-responsive"><table class="table"><thead><tr><th>Marbete</th><th>Registro</th><th>Seguro</th><th>Fecha registrada</th><th></th></tr></thead><tbody>
                @foreach($bagResult['receptacles'] as $candidate)
                    <tr><td>{{ $candidate->RECPTCL_FID ?: 'Sin marbete' }}</td><td>{{ $candidate->RECPTCL_REG_NO ?: '—' }}</td><td>{{ $candidate->RECPTCL_INS_NO ?: '—' }}</td><td>{{ $candidate->EVT_GMT_DT ?: '—' }}</td><td>@if($candidate->RECPTCL_FID)<a class="btn btn-sm btn-outline-primary" href="{{ route('postal.customs.remittance', ['marbete'=>$candidate->RECPTCL_FID]) }}">Abrir esta saca</a>@endif</td></tr>
                @endforeach
            </tbody></table></div></section>
        @elseif($bag)
            <section class="postal-panel" data-customs-bag>
                <div class="postal-section-heading"><div><h2><i class="fas fa-boxes"></i> Paquetes de la saca {{ $bag->RECPTCL_FID ?: $marbete }}</h2><span>IPS: {{ count($bagRows) }} paquetes relacionados · CDS: {{ $bagDeclarationCount }} con declaración</span></div><span class="postal-source">Origen: IPS + CDS</span></div>
                @if($bagError)<div class="alert alert-warning">{{ $bagError }}</div>@endif
                @if($bagResult['truncated'] || $bagIndexTruncated)<div class="alert alert-warning">La saca o el índice CDS alcanzó el límite consultable. Verifica el total antes de entregar la relación.</div>@endif
                @if($bagRows)
                    <div class="postal-remittance-filters"><label for="bag-package-search">Filtrar esta lista</label><input id="bag-package-search" type="search" class="form-control" placeholder="Código, origen, destino o estado"><label for="bag-declaration-filter">Situación CDS</label><select id="bag-declaration-filter" class="form-control"><option value="all">Todos los paquetes</option><option value="declared">Con declaración activa</option><option value="deleted">Solo declaración eliminada</option><option value="record_only">Registro sin declaración</option><option value="missing">No encontrado en CDS</option><option value="unknown">No confirmado</option></select><span id="bag-visible-count" class="postal-note">{{ count($bagRows) }} paquetes</span></div>
                    <form method="get" action="{{ route('postal.customs.remittance') }}" data-customs-selection>
                        <input type="hidden" name="marbete" value="{{ $marbete }}">
                        <div class="postal-selection-actions"><span id="bag-selected-count">0 seleccionados · máximo 50</span><button type="button" class="btn btn-sm btn-outline-primary" data-select-all>Seleccionar hasta 50 con declaración</button><button type="submit" class="btn btn-primary" data-build-manifest><i class="fas fa-file-export"></i> Preparar remisión seleccionada</button></div>
                        <div class="table-responsive"><table class="table postal-remittance-table"><thead><tr><th>Incluir</th><th>Paquete IPS</th><th>Ruta</th><th>Último movimiento IPS</th><th>Situación CDS</th><th></th></tr></thead><tbody>
                            @foreach($bagRows as $row)
                                <tr data-has-declaration="{{ $row['has_declaration'] ? 'yes' : 'no' }}" data-cds-match="{{ $row['cds_match'] }}">
                                    <td>@if($row['has_declaration'] && $row['select_code'])<input type="checkbox" name="seleccionados[]" value="{{ $row['select_code'] }}" aria-label="Incluir {{ $row['code'] }} en la remisión" @if(in_array($row['select_code'], $selectedCodes, true)) checked @endif>@else<span class="text-muted">—</span>@endif</td>
                                    <td><strong>{{ $row['item']->MAILITM_FID ?: $row['item']->MAILITM_LOCAL_ID ?: 'Sin código' }}</strong>@if($row['item']->MAILITM_LOCAL_ID && $row['item']->MAILITM_FID)<small class="d-block text-muted">Local: {{ $row['item']->MAILITM_LOCAL_ID }}</small>@endif</td>
                                    <td>{{ $row['item']->ORIGIN_COUNTRY ?: '—' }} → {{ $row['item']->DEST_COUNTRY ?: '—' }}</td>
                                    <td>{{ $row['item']->EVENT_NAME_ES ?: $row['item']->EVENT_NAME ?: 'Sin movimiento' }}<small class="d-block text-muted">{{ $row['item']->EVT_GMT_DT ?: 'Fecha no informada' }} · estado IPS {{ $row['item']->POSTAL_STATUS_NAME ?: 'no informado' }}</small></td>
                                    <td><span class="postal-source {{ $row['has_declaration'] ? '' : 'postal-warning' }}">{{ $row['status'] }}</span>@if($row['declaration_count'] > 0)<small class="d-block text-muted">{{ $row['declaration_count'] }} registro(s) de declaración · {{ $row['declaration_states'] ?: 'estado no informado' }}</small>@elseif($row['group'])<small class="d-block text-muted">Se revisaron {{ count($row['group']['records']) }} registro(s) CDS para este código.</small>@endif</td>
                                    <td>@if($row['has_any_declaration'] && $row['select_code'])<a class="btn btn-sm btn-outline-primary" href="{{ route('postal.cds', ['codigo'=>$row['select_code']]) }}" target="_blank" rel="noopener">Ver declaraciones</a>@elseif($row['item']->MAILITM_FID)<a class="btn btn-sm btn-outline-secondary" href="{{ route('postal.ips', ['codigo'=>$row['item']->MAILITM_FID]) }}">Ver paquete</a>@endif</td>
                                </tr>
                            @endforeach
                        </tbody></table></div>
                        <p class="postal-note mb-0">Solo se pueden agregar paquetes con una declaración CDS no eliminada. Los borradores aparecen identificados como tales; confirma su etapa antes de entregar el listado. La remisión permite hasta 50 paquetes por lote.</p>
                    </form>
                @else
                    <div class="postal-empty postal-empty-compact"><i class="fas fa-box-open"></i><h3>No hay paquetes vinculados para listar</h3><p>IPS no devolvió paquetes asociados a los eventos de esta saca.</p></div>
                @endif
            </section>
        @else
            <div class="postal-empty postal-panel"><i class="fas fa-search"></i><h2>No encontramos ese marbete en IPS</h2><p>Verifica el código completo; se busca por marbete, número de registro o seguro.</p></div>
        @endif
    @endif

    @if($manifest)
        <section class="postal-panel">
            <div class="postal-section-heading">
                <div><h2><i class="fas fa-file-export"></i> Resultado de la consulta</h2><span>Generado {{ $manifest['generated_at']->format('d/m/Y H:i') }} · hora de Bolivia</span></div>
                <div class="postal-actions">
                    <a class="btn btn-outline-primary" target="_blank" rel="noopener" href="{{ route('postal.customs.remittance.print', ['codigos'=>$input, 'marbete'=>$marbete ?: null]) }}"><i class="fas fa-print"></i> Imprimir / guardar PDF</a>
                    <a class="btn btn-outline-success" href="{{ route('postal.customs.remittance.csv', ['codigos'=>$input, 'marbete'=>$marbete ?: null]) }}"><i class="fas fa-file-csv"></i> Descargar CSV</a>
                </div>
            </div>

            <div class="postal-remittance-summary">
                <div><strong>{{ count($manifest['packages']) }}</strong><span>paquetes encontrados</span></div>
                <div><strong>{{ $manifest['declaration_count'] }}</strong><span>declaraciones CDS</span></div>
                <div><strong>{{ count($manifest['missing_codes']) }}</strong><span>no encontrados en CDS</span></div>
                <div><strong>{{ count($manifest['without_declaration_codes'] ?? []) }}</strong><span>con registro, sin declaración</span></div>
                <div><strong>{{ count($manifest['deleted_declaration_codes'] ?? []) }}</strong><span>con declaración eliminada</span></div>
                <div><strong>{{ count($manifest['unconfirmed_codes'] ?? []) }}</strong><span>no confirmados por límite</span></div>
            </div>

            @if($manifest['duplicate_count'] > 0)<div class="alert alert-secondary">Se ignoraron {{ $manifest['duplicate_count'] }} identificador(es) repetido(s) en la entrada.</div>@endif
            @if($manifest['truncated'])<div class="alert alert-warning">CDS alcanzó el límite de registros configurado. Revisa el lote en grupos más pequeños para evitar que falte información.</div>@endif

            @forelse($manifest['packages'] as $group)
                <article class="postal-remittance-package">
                    <div class="postal-section-heading">
                        <div><h3>{{ $group['matched_codes'][0] }}</h3><span>Coincidencias: {{ implode(', ', $group['matched_codes']) }} · {{ count($group['records']) }} registro(s) CDS</span></div>
                        <span class="postal-source {{ ($group['has_active_declaration'] ?? !empty($group['declarations'])) ? '' : 'postal-warning' }}">{{ $group['status'] }}</span>
                    </div>
                    @foreach($group['records'] as $record)
                        @php($package = $record['package'])
                        <div class="postal-remittance-meta">
                            <span><strong>ID objeto CDS:</strong> {{ $package->MAIL_OBJECT_PID ?? 'No informado' }}</span>
                            <span><strong>Código postal:</strong> {{ ($package->MAIL_OBJECT_ID ?? '') ?: 'No informado' }}</span>
                            <span><strong>Identificador local:</strong> {{ (($package->MAIL_OBJECT_LOCAL_ID ?? '') ?: ($package->MAIL_OBJECT_LOCAL_ID2 ?? '')) ?: 'No informado' }}</span>
                            <span><strong>Estado CDS:</strong> {{ ($package->MAIL_STATE_NM ?? '') ?: 'No informado' }}</span>
                            <span><strong>Fecha de registro:</strong> {{ ($package->POSTING_DATE ?? '') ?: 'No informada' }}</span>
                            <span><strong>Tipo / flujo:</strong> {{ ($package->MAIL_OBJECT_TYPE_CD ?? '') ?: 'No informado' }} / {{ $package->MAIL_FLOW_CD ?? 'No informado' }}</span>
                        </div>
                        @if($record['declarations'])
                            @foreach($record['declarations'] as $declaration)
                                @include('postal.declaration', ['declaration'=>$declaration])
                            @endforeach
                        @else
                            <p class="postal-note mb-2">Este registro CDS no tiene declaración vinculada.</p>
                        @endif
                        @if($record['responses'])
                            <div class="postal-response"><h4>Respuestas de Aduana para este registro</h4>
                                @foreach($record['responses'] as $response)
                                    <p><strong>{{ $response['decision'] ?? 'Respuesta CDS' }}</strong> · {{ $response['state'] ?? 'Estado no informado' }} <small>(registro {{ $response['id'] }})</small></p>
                                @endforeach
                            </div>
                        @endif
                    @endforeach
                </article>
            @empty
                <div class="postal-empty"><i class="fas fa-folder-open" aria-hidden="true"></i><h2>{{ !empty($manifest['unconfirmed_codes']) ? 'CDS no pudo confirmar todos los códigos' : 'CDS no encontró paquetes para estos códigos' }}</h2><p>{{ !empty($manifest['unconfirmed_codes']) ? 'Reduce el lote y vuelve a consultar antes de concluir que no existen.' : 'Comprueba que los identificadores estén completos y vuelve a consultar.' }}</p></div>
            @endforelse

            @if($manifest['missing_codes'])
                <section class="postal-remittance-missing">
                    <h3><i class="fas fa-exclamation-triangle"></i> Códigos no encontrados en CDS</h3>
                    <p>No se incluyeron como declaraciones en el listado. Verifica los códigos o consulta con la oficina responsable.</p>
                    <ul>@foreach($manifest['missing_codes'] as $code)<li><code>{{ $code }}</code> — No encontrado en CDS</li>@endforeach</ul>
                </section>
            @endif
            @if($manifest['unconfirmed_codes'] ?? [])
                <section class="postal-remittance-missing"><h3><i class="fas fa-hourglass-half"></i> Códigos no confirmados</h3><p>CDS alcanzó el límite de resultados antes de poder verificar estos códigos. Reduce el lote y vuelve a consultar antes de concluir que no existen.</p><ul>@foreach($manifest['unconfirmed_codes'] as $code)<li><code>{{ $code }}</code></li>@endforeach</ul></section>
            @endif
        </section>
    @endif
</div>
@stop
@section('js')<script src="{{ asset('js/customs-remittance.js') }}" defer></script>@stop
