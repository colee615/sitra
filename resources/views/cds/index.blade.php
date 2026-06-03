@extends('adminlte::page')

@section('title', 'Rastreo CDS')

@section('content_header')
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center">
        <div>
            <h1 class="m-0">Rastreo CDSDb</h1>
            <small class="text-muted">Consulta por codigo S10 o codigo local</small>
        </div>
        <a href="{{ route('dashboard') }}" class="btn btn-outline-secondary btn-sm mt-3 mt-md-0">
            <i class="fas fa-arrow-left mr-1"></i> Volver al Dashboard
        </a>
    </div>
@stop

@section('content')
    @php
        $main = $objectRows[0] ?? null;
        $latestDeclarationEvent = $declarationEventRows[0] ?? null;
        $latestEdi = $ediExportRows[0] ?? null;
        $currentState = $stateRows[0] ?? null;
        $hasResults = count($objectRows) > 0
            || count($stateRows) > 0
            || count($declarationRows) > 0
            || count($declarationEventRows) > 0
            || count($responseRows) > 0
            || count($responseEventRows) > 0
            || count($ediExportRows) > 0
            || count($auditRows) > 0
            || count($anDeclarationRows) > 0;

        $declared = [];
        foreach ($declarationRows as $d) {
            $xml = trim((string) ($d->DECLARATION_DATA ?? ''));
            if ($xml === '') {
                continue;
            }
            try {
                $sx = @simplexml_load_string($xml);
                if (!$sx) {
                    continue;
                }
                $sender = trim((string) ($sx['SNm'] ?? ''));
                $senderAddr = trim((string) (($sx['SAdL1'] ?? ($sx['SAd1'] ?? ''))));
                $senderAddr2 = trim((string) (($sx['SAdL2'] ?? ($sx['SAd2'] ?? ''))));
                $senderCity = trim((string) ($sx['SCty'] ?? ''));
                $receiver = trim((string) ($sx['RNm'] ?? ''));
                $receiverAddr = trim((string) (($sx['RAdL1'] ?? ($sx['RAd1'] ?? ''))));
                $receiverAddr2 = trim((string) (($sx['RAdL2'] ?? ($sx['RAd2'] ?? ''))));
                $receiverCity = trim((string) ($sx['RCty'] ?? ''));
                $phone = trim((string) ($sx['STel'] ?? ''));
                $currency = trim((string) ($sx['TotCPValCur'] ?? ''));
                $totalValue = trim((string) ($sx['TotCPVal'] ?? ''));
                $totalWeight = trim((string) ($sx['TotNWgt'] ?? ''));
                $postingDate = trim((string) ($sx['TrDat'] ?? ''));
                $firstPiece = $sx->xpath('//ContPiece[1]');
                $allPieces = $sx->xpath('//ContPiece');
                if (!$allPieces || count($allPieces) === 0) {
                    $allPieces = $sx->xpath('//ContPc');
                    $firstPiece = $allPieces[0] ?? null;
                }
                $piece = $firstPiece[0] ?? null;
                $desc = $piece ? trim((string) ($piece['Desc'] ?? '')) : '';
                $hs = $piece ? trim((string) ($piece['HS'] ?? '')) : '';
                $pieceVal = $piece ? trim((string) ($piece['Amt'] ?? '')) : '';
                $pieceCur = $piece ? trim((string) ($piece['Cur'] ?? '')) : '';
                $pieceWgt = $piece ? trim((string) ($piece['NWgt'] ?? '')) : '';
                if ($piece && $pieceWgt === '') {
                    $pieceWgt = trim((string) ($piece['Wgt'] ?? ''));
                }
                $items = [];
                if ($allPieces) {
                    foreach ($allPieces as $p) {
                        $items[] = [
                            'desc' => trim((string) ($p['Desc'] ?? '')),
                            'hs' => trim((string) ($p['HS'] ?? '')),
                            'amount' => trim((string) ($p['Amt'] ?? '')),
                            'currency' => trim((string) ($p['Cur'] ?? '')),
                            'weight' => trim((string) ($p['NWgt'] ?? '')),
                            'origin' => trim((string) ($p['OCtr'] ?? '')),
                            'extra' => trim((string) ($p['ExRtLoc'] ?? '')),
                        ];
                    }
                }
                $declared[] = [
                    'sender' => $sender,
                    'sender_addr' => trim($senderAddr . ($senderCity ? ', ' . $senderCity : '')),
                    'sender_addr_1' => $senderAddr,
                    'sender_addr_2' => $senderAddr2,
                    'receiver' => $receiver,
                    'receiver_addr' => trim($receiverAddr . ($receiverCity ? ', ' . $receiverCity : '')),
                    'receiver_addr_1' => $receiverAddr,
                    'receiver_addr_2' => $receiverAddr2,
                    'phone' => $phone,
                    'posting_date' => $postingDate,
                    'desc' => $desc,
                    'hs' => $hs,
                    'piece_value' => $pieceVal,
                    'piece_currency' => $pieceCur,
                    'piece_weight' => $pieceWgt,
                    'total_value' => $totalValue,
                    'total_currency' => $currency,
                    'total_weight' => $totalWeight,
                    'mail_class' => trim((string) ($sx['HC'] ?? '')),
                    'mail_category' => trim((string) ($sx['IvC'] ?? '')),
                    'origin_country' => trim((string) ($sx['SCtr'] ?? '')),
                    'dest_country' => trim((string) ($sx['RCtr'] ?? '')),
                    'dest_zip' => trim((string) ($sx['RZip'] ?? '')),
                    'sender_zip' => trim((string) ($sx['SZip'] ?? '')),
                    'transport_mode' => trim((string) ($sx['TrMod'] ?? '')),
                    'gross_weight' => trim((string) ($sx['Gwgt'] ?? ($sx['GWgt'] ?? ''))),
                    'package_type' => trim((string) ($sx['Ptg'] ?? '')),
                    'package_currency' => trim((string) ($sx['PtgCur'] ?? '')),
                    'sender_state' => trim((string) ($sx['SSta'] ?? '')),
                    'receiver_state' => trim((string) ($sx['RSta'] ?? '')),
                    'sender_id_ref' => trim((string) ($sx['SIdR'] ?? '')),
                    'receiver_id_ref' => trim((string) ($sx['RIdR'] ?? '')),
                    'receiver_phone' => trim((string) ($sx['RTel'] ?? '')),
                    'obs' => trim((string) ($sx['Obs'] ?? '')),
                    'type_code' => trim((string) ($sx['NTyp'] ?? '')),
                    'type_desc' => trim((string) ($sx['NTypDesc'] ?? '')),
                    'total_pieces' => trim((string) ($sx['TotCPNo'] ?? '')),
                    'exchange_rate' => trim((string) ($sx['ExRt'] ?? '')),
                    'image_path' => trim((string) ($sx['piLocPath'] ?? '')),
                    'image_src' => trim((string) ($sx['piSrcInfo'] ?? '')),
                'items' => $items,
                ];
            } catch (\Throwable $e) {
            }
        }
        $decl = $declared[0] ?? null;
    @endphp

    <div class="card card-primary card-outline">
        <div class="card-body">
            @if($error)
                <div class="alert alert-danger"><strong>Error SQL Server:</strong> {{ $error }}</div>
            @endif

            <form method="GET" action="{{ route('cds.datos') }}">
                <div class="input-group input-group-lg">
                    <input type="text" name="codigo" value="{{ $codigo }}" class="form-control" placeholder="Ej: RR001835787BO o codigo local" autocomplete="off">
                    <div class="input-group-append">
                        <button type="submit" class="btn btn-success"><i class="fas fa-search mr-1"></i> Buscar</button>
                        <a href="{{ route('cds.datos') }}" class="btn btn-default">Limpiar</a>
                    </div>
                </div>
            </form>
            <div class="mt-2 text-muted">Conexion activa: <strong>CDSDb</strong> (solo lectura)</div>
        </div>
    </div>

    @if($codigo !== '' && !$hasResults && !$error)
        <div class="alert alert-warning">No se encontraron registros en CDSDb para <strong>{{ $codigo }}</strong>.</div>
    @endif

    @if($hasResults)
        <div class="card card-outline card-primary">
            <div class="card-body">
                <h4 class="mb-1">{{ $main->MAIL_OBJECT_ID ?? $codigo }}</h4>
                <div class="text-muted mb-3">Local: {{ $main->MAIL_OBJECT_LOCAL_ID ?? '-' }} | Origen: {{ $main->ORIG_POST_ORGANIZATION_CD ?? '-' }} | Destino: {{ $main->DEST_POST_ORGANIZATION_CD ?? '-' }}</div>
                <div class="row">
                    <div class="col-md-4 mb-2"><div class="p-2 border rounded"><small class="text-muted d-block">Estado actual</small><strong>{{ $currentState->MAIL_STATE_NM ?? ('Codigo ' . ($main->MAIL_STATE_CD ?? '-')) }}</strong></div></div>
                    <div class="col-md-4 mb-2"><div class="p-2 border rounded"><small class="text-muted d-block">Ultimo evento aduana</small><strong>{{ $latestDeclarationEvent->D_EVENT_GMT_DT ?? '-' }}</strong></div></div>
                    <div class="col-md-4 mb-2"><div class="p-2 border rounded"><small class="text-muted d-block">Ultima integracion EDI</small><strong>{{ $latestEdi->EVENT_DATE ?? '-' }}</strong></div></div>
                </div>
                @if($decl)
                <div class="row mt-2">
                    <div class="col-md-6 mb-2"><div class="p-2 border rounded"><small class="text-muted d-block">Remitente</small><strong>{{ $decl['sender'] ?: '-' }}</strong><div>{{ $decl['sender_addr'] ?: '-' }}</div></div></div>
                    <div class="col-md-6 mb-2"><div class="p-2 border rounded"><small class="text-muted d-block">Destinatario</small><strong>{{ $decl['receiver'] ?: '-' }}</strong><div>{{ $decl['receiver_addr'] ?: '-' }}</div></div></div>
                    <div class="col-md-3 mb-2"><div class="p-2 border rounded"><small class="text-muted d-block">Contenido declarado</small><strong>{{ $decl['desc'] ?: '-' }}</strong></div></div>
                    <div class="col-md-2 mb-2"><div class="p-2 border rounded"><small class="text-muted d-block">Codigo HS</small><strong>{{ $decl['hs'] ?: '-' }}</strong></div></div>
                    <div class="col-md-2 mb-2"><div class="p-2 border rounded"><small class="text-muted d-block">Peso declarado</small><strong>{{ $decl['piece_weight'] ?: ($decl['total_weight'] ?: '-') }}</strong></div></div>
                    <div class="col-md-3 mb-2"><div class="p-2 border rounded"><small class="text-muted d-block">Valor declarado</small><strong>{{ ($decl['piece_value'] ?: $decl['total_value']) ?: '-' }} {{ ($decl['piece_currency'] ?: $decl['total_currency']) ?: '' }}</strong></div></div>
                    <div class="col-md-2 mb-2"><div class="p-2 border rounded"><small class="text-muted d-block">Telefono remitente</small><strong>{{ $decl['phone'] ?: '-' }}</strong></div></div>
                </div>
                @endif
            </div>
        </div>

        <div class="card card-outline card-warning">
            <div class="card-header"><h3 class="card-title">Declaraciones aduaneras</h3></div>
            <div class="card-body p-0"><div class="table-responsive"><table class="table table-sm table-striped mb-0"><thead class="thead-light"><tr><th>ID declaracion</th><th>ID objeto</th><th>Estado CDS</th><th>Org postal</th><th>Org aduana</th><th>ID AN</th></tr></thead><tbody>@forelse($declarationRows as $d)<tr><td>{{ $d->DECLARATION_PID ?? '-' }}</td><td>{{ $d->MAIL_OBJECT_PID ?? '-' }}</td><td>{{ $d->CDS_STATE_CD ?? '-' }}</td><td>{{ $d->POST_ORGANIZATION_CD ?? '-' }}</td><td>{{ $d->CUST_ORGANIZATION_CD ?? '-' }}</td><td>{{ $d->AN_DECLARATION_ID ?? '-' }}</td></tr>@empty<tr><td colspan="6" class="text-center text-muted">Sin declaraciones.</td></tr>@endforelse</tbody></table></div></div>
        </div>

        <div class="card card-outline card-warning">
            <div class="card-header"><h3 class="card-title">Declaración en formato legible</h3></div>
            <div class="card-body">
                @if($decl)
                    <div class="row">
                        <div class="col-md-6">
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered">
                                    <thead class="thead-light"><tr><th colspan="2">Remitente</th></tr></thead>
                                    <tbody>
                                        <tr><th>Nombre</th><td>{{ $decl['sender'] ?: '-' }}</td></tr>
                                        <tr><th>Dirección línea 1</th><td>{{ $decl['sender_addr_1'] ?: '-' }}</td></tr>
                                        <tr><th>Dirección comp.</th><td>{{ $decl['sender_addr_2'] ?: '-' }}</td></tr>
                                        <tr><th>Ciudad</th><td>{{ $decl['sender_addr'] ?: '-' }}</td></tr>
                                        <tr><th>Teléfono</th><td>{{ $decl['phone'] ?: '-' }}</td></tr>
                                        <tr><th>País</th><td>{{ $decl['origin_country'] ?: '-' }}</td></tr>
                                        <tr><th>ZIP</th><td>{{ $decl['sender_zip'] ?: '-' }}</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered">
                                    <thead class="thead-light"><tr><th colspan="2">Destinatario</th></tr></thead>
                                    <tbody>
                                        <tr><th>Nombre</th><td>{{ $decl['receiver'] ?: '-' }}</td></tr>
                                        <tr><th>Dirección línea 1</th><td>{{ $decl['receiver_addr_1'] ?: '-' }}</td></tr>
                                        <tr><th>Dirección comp.</th><td>{{ $decl['receiver_addr_2'] ?: '-' }}</td></tr>
                                        <tr><th>Ciudad</th><td>{{ $decl['receiver_addr'] ?: '-' }}</td></tr>
                                        <tr><th>País</th><td>{{ $decl['dest_country'] ?: '-' }}</td></tr>
                                        <tr><th>ZIP</th><td>{{ $decl['dest_zip'] ?: '-' }}</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12">
                            <div class="table-responsive">
                                <table class="table table-sm table-striped table-bordered mb-0">
                                    <thead class="thead-dark">
                                        <tr>
                                            <th>ID declaración</th><th>ID AN</th><th>Fecha declaración</th><th>Clase</th><th>Categoría</th><th>Modo</th><th>Peso neto</th><th>Peso bruto</th><th>Valor total</th><th>Moneda</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>{{ $declarationRows[0]->DECLARATION_PID ?? '-' }}</td>
                                            <td>{{ $declarationRows[0]->AN_DECLARATION_ID ?? '-' }}</td>
                                            <td>{{ $decl['posting_date'] ?: '-' }}</td>
                                            <td>{{ $decl['mail_class'] ?: '-' }}</td>
                                            <td>{{ $decl['mail_category'] ?: '-' }}</td>
                                            <td>{{ $decl['transport_mode'] ?: '-' }}</td>
                                            <td>{{ $decl['total_weight'] ?: '-' }}</td>
                                            <td>{{ $decl['gross_weight'] ?: '-' }}</td>
                                            <td>{{ $decl['total_value'] ?: '-' }}</td>
                                            <td>{{ $decl['total_currency'] ?: '-' }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="row mt-3">
                        <div class="col-md-12">
                            <div class="table-responsive">
                                <table class="table table-sm table-striped table-bordered mb-0">
                                    <thead class="thead-dark">
                                        <tr>
                                            <th>Descripción ítem</th><th>Código HS</th><th>Monto</th><th>Moneda</th><th>Peso</th><th>País origen</th><th>Extra</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse(($decl['items'] ?? []) as $it)
                                            <tr>
                                                <td>{{ $it['desc'] ?: '-' }}</td>
                                                <td>{{ $it['hs'] ?: '-' }}</td>
                                                <td>{{ $it['amount'] ?: '-' }}</td>
                                                <td>{{ $it['currency'] ?: '-' }}</td>
                                                <td>{{ $it['weight'] ?: '-' }}</td>
                                                <td>{{ $it['origin'] ?: '-' }}</td>
                                                <td>{{ $it['extra'] ?: '-' }}</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="7" class="text-center text-muted">Sin ítems declarados en estructura reconocida.</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="row mt-3">
                        <div class="col-md-12">
                            <details>
                                <summary><strong>Ver XML crudo completo</strong></summary>
                                <pre class="mt-2 mb-0" style="white-space: pre-wrap;">{{ $declarationRows[0]->DECLARATION_DATA ?? '-' }}</pre>
                            </details>
                        </div>
                    </div>
                @else
                    <div class="text-muted">No se pudo interpretar el XML de declaración para este código.</div>
                @endif
            </div>
        </div>

        <div class="card card-outline card-dark">
            <div class="card-header"><h3 class="card-title">AN Declaration (origen aduanero)</h3></div>
            <div class="card-body p-0"><div class="table-responsive"><table class="table table-sm table-striped mb-0"><thead class="thead-dark"><tr><th>ID AN</th><th>Fecha posting</th><th>Origen</th><th>Clase origen</th><th>Convertido</th><th>DATA AN</th></tr></thead><tbody>@forelse($anDeclarationRows as $an)<tr><td>{{ $an->AN_DECLARATION_ID ?? '-' }}</td><td>{{ $an->POSTING_DATE ?? '-' }}</td><td>{{ $an->SOURCE_CD ?? '-' }}</td><td>{{ $an->SOURCE_CLASS ?? '-' }}</td><td>{{ $an->CONVERTED_DT ?? '-' }}</td><td><pre class="mb-0" style="white-space: pre-wrap;">{{ $an->AN_DECLARATION_DATA ?? '-' }}</pre></td></tr>@empty<tr><td colspan="6" class="text-center text-muted">Sin AN declaration asociada.</td></tr>@endforelse</tbody></table></div></div>
        </div>
                @if($decl)
                <div class="card card-outline card-info">
                    <div class="card-header"><h3 class="card-title">Ficha completa del envío (declaración)</h3></div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-striped mb-0">
                                <tbody>
                                    <tr><th>Fecha declaración</th><td>{{ $decl['posting_date'] ?: '-' }}</td><th>Clase correo</th><td>{{ $decl['mail_class'] ?: '-' }}</td></tr>
                                    <tr><th>Categoría envío</th><td>{{ $decl['mail_category'] ?: '-' }}</td><th>Modo transporte</th><td>{{ $decl['transport_mode'] ?: '-' }}</td></tr>
                                    <tr><th>País origen</th><td>{{ $decl['origin_country'] ?: '-' }}</td><th>País destino</th><td>{{ $decl['dest_country'] ?: '-' }}</td></tr>
                                    <tr><th>Zona/Estado remitente</th><td>{{ $decl['sender_state'] ?: '-' }}</td><th>Zona/Estado destinatario</th><td>{{ $decl['receiver_state'] ?: '-' }}</td></tr>
                                    <tr><th>ZIP remitente</th><td>{{ $decl['sender_zip'] ?: '-' }}</td><th>ZIP destinatario</th><td>{{ $decl['dest_zip'] ?: '-' }}</td></tr>
                                    <tr><th>Peso bruto</th><td>{{ $decl['gross_weight'] ?: '-' }}</td><th>Peso total neto</th><td>{{ $decl['total_weight'] ?: '-' }}</td></tr>
                                    <tr><th>Valor total</th><td>{{ ($decl['total_value'] ?: '-') . ' ' . ($decl['total_currency'] ?: '') }}</td><th>Tipo paquete</th><td>{{ ($decl['package_type'] ?: '-') . ' ' . ($decl['package_currency'] ?: '') }}</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                    <div class="row mt-3">
                        <div class="col-md-12">
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered mb-0">
                                    <thead class="thead-light"><tr><th colspan="4">Campos adicionales del XML</th></tr></thead>
                                    <tbody>
                                        <tr><th>Referencia remitente</th><td>{{ $decl['sender_id_ref'] ?: '-' }}</td><th>Referencia destinatario</th><td>{{ $decl['receiver_id_ref'] ?: '-' }}</td></tr>
                                        <tr><th>Teléfono destinatario</th><td>{{ $decl['receiver_phone'] ?: '-' }}</td><th>Observación</th><td>{{ $decl['obs'] ?: '-' }}</td></tr>
                                        <tr><th>Tipo código</th><td>{{ $decl['type_code'] ?: '-' }}</td><th>Tipo descripción</th><td>{{ $decl['type_desc'] ?: '-' }}</td></tr>
                                        <tr><th>Total piezas</th><td>{{ $decl['total_pieces'] ?: '-' }}</td><th>Tipo cambio</th><td>{{ $decl['exchange_rate'] ?: '-' }}</td></tr>
                                        <tr><th>Ruta imagen</th><td>{{ $decl['image_path'] ?: '-' }}</td><th>Origen imagen</th><td>{{ $decl['image_src'] ?: '-' }}</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                <div class="card card-outline card-success">
                    <div class="card-header"><h3 class="card-title">Ítems declarados</h3></div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-striped mb-0">
                                <thead class="thead-dark">
                                    <tr>
                                        <th>Descripción</th><th>HS</th><th>Monto</th><th>Moneda</th><th>Peso</th><th>Origen</th><th>Extra</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse(($decl['items'] ?? []) as $it)
                                        <tr>
                                            <td>{{ $it['desc'] ?: '-' }}</td>
                                            <td>{{ $it['hs'] ?: '-' }}</td>
                                            <td>{{ $it['amount'] ?: '-' }}</td>
                                            <td>{{ $it['currency'] ?: '-' }}</td>
                                            <td>{{ $it['weight'] ?: '-' }}</td>
                                            <td>{{ $it['origin'] ?: '-' }}</td>
                                            <td>{{ $it['extra'] ?: '-' }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="7" class="text-center text-muted">Sin ítems declarados.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                @endif
    @endif
@stop
