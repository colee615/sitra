@extends('adminlte::page')

@section('title', 'Rastreo IPS5')

@section('content_header')
    <div class="d-flex justify-content-between align-items-center">
        <h1 class="m-0">Rastreo IPS5 por codigo S10</h1>
        <a href="{{ route('dashboard') }}" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left mr-1"></i> Volver al Dashboard
        </a>
    </div>
@stop

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="card card-primary card-outline">
                    <div class="card-header">
                        <h3 class="card-title">Consulta de paquete</h3>
                    </div>
                    <div class="card-body">
                        @if($error)
                            <div class="alert alert-danger mb-3">
                                <strong>Error SQL Server:</strong> {{ $error }}
                            </div>
                        @else
                            <div class="alert alert-success mb-3">
                                Conexion SQL Server activa (IPS5Db).
                            </div>
                        @endif

                        <form method="GET" action="{{ route('sqlserver.datos') }}" class="form-inline mb-2">
                            <div class="form-group mr-2 mb-2">
                                <label class="mr-2">Codigo S10 / Local</label>
                                <input type="text" name="codigo" value="{{ $codigo }}" class="form-control"
                                    placeholder="Ej: EE005479036BO">
                            </div>
                            <button type="submit" class="btn btn-success mr-2 mb-2">
                                <i class="fas fa-search mr-1"></i> Rastrear
                            </button>
                            <a href="{{ route('sqlserver.datos') }}" class="btn btn-default mb-2">Limpiar</a>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        @if($codigo !== '')
            <div class="row">
                <div class="col-md-3 col-12">
                    <div class="small-box bg-info">
                        <div class="inner">
                            <h3>{{ count($packageRows) }}</h3>
                            <p>Registros base</p>
                        </div>
                        <div class="icon"><i class="fas fa-box"></i></div>
                    </div>
                </div>
                <div class="col-md-3 col-12">
                    <div class="small-box bg-success">
                        <div class="inner">
                            <h3>{{ count($trackingRows) }}</h3>
                            <p>Eventos de rastreo</p>
                        </div>
                        <div class="icon"><i class="fas fa-stream"></i></div>
                    </div>
                </div>
                <div class="col-md-3 col-12">
                    <div class="small-box bg-warning">
                        <div class="inner">
                            <h3>{{ count($logisticRows) }}</h3>
                            <p>Vinculos logisticos</p>
                        </div>
                        <div class="icon"><i class="fas fa-route"></i></div>
                    </div>
                </div>
                <div class="col-md-3 col-12">
                    <div class="small-box bg-secondary">
                        <div class="inner">
                            <h3>{{ count($ediRows) }}</h3>
                            <p>Registros EDI</p>
                        </div>
                        <div class="icon"><i class="fas fa-exchange-alt"></i></div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-12">
                    <div class="card card-outline card-primary">
                        <div class="card-header"><h3 class="card-title">Ficha del paquete (cabecera)</h3></div>
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
                        <div class="card-header"><h3 class="card-title">Clientes (remitente/destinatario)</h3></div>
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
                        <div class="card-header"><h3 class="card-title">Entrega</h3></div>
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
                        <div class="card-header"><h3 class="card-title">Logistica (saca y despacho)</h3></div>
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
                        <div class="card-header"><h3 class="card-title">Manifiestos</h3></div>
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
                        <div class="card-header"><h3 class="card-title">Eventos EDI</h3></div>
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
                        <div class="card-header"><h3 class="card-title">Timeline completa (mas reciente a mas antiguo)</h3></div>
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
                                                <td>{{ $ev->SOURCE_DB ?: '-' }}</td>
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
                            <h3 class="card-title">Tablas participantes y atributos clave</h3>
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
    </div>
@stop

@section('css')
    <link rel="stylesheet" href="{{ asset('vendor/fontawesome-free/css/all.min.css') }}">
@stop
