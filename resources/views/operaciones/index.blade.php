@extends('adminlte::page')
@section('title', 'Paquetes y entregas')
@section('content_header')
    <div class="postal-heading"><div><span class="postal-eyebrow">OPERACIONES · IPS</span><h1>Paquetes y entregas</h1><p>Encuentra el paquete, verifica su último movimiento y registra la operación.</p></div><a class="btn btn-outline-primary" href="{{ route('consultas.index') }}"><i class="fas fa-search"></i> Consultar expediente</a></div>
@stop
@section('content')
    @if($error)<div class="alert alert-danger" role="alert">{{ $error }}</div>@endif
    @if($errors->any())
        <div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>
    @endif
    @if(config('ips.writes_enabled') && config('ips.connection') !== config('postal.ips_read_connection'))
        <div class="alert alert-warning"><i class="fas fa-database"></i> Esta bandeja registra movimientos en la conexi?n de pruebas IPS. Los expedientes consultados muestran el cat?logo postal de solo lectura.</div>
    @endif
    @if(session('operation_result'))
        @php($operation = session('operation_result'))
        <div class="alert {{ $operation['status'] === 'succeeded' ? 'alert-success' : 'alert-warning' }}" role="status">
            {{ $operation['status'] === 'succeeded' ? 'Operación confirmada por IPS.' : ($operation['message'] ?? 'Verifique el resultado en la bitácora.') }}
            Referencia: <code>{{ $operation['operation_id'] }}</code>
        </div>
    @endif
    @unless(config('ips.writes_enabled'))
        <div class="alert alert-info">Las consultas están disponibles. Las altas y los movimientos se habilitarán cuando finalice la configuración de la integración IPS.</div>
    @endunless
    <div class="card"><div class="card-body">
        <form method="GET" action="{{ route('operaciones.index') }}" class="row">
            <div class="col-md-3"><label for="q">Código exacto</label><input id="q" name="q" maxlength="35" class="form-control" value="{{ $filters['q'] ?? '' }}"></div>
            <div class="col-md-3"><label for="status">Estado</label><select id="status" name="status" class="form-control">
                @foreach(['pending' => 'Pendientes de entrega', 'all' => 'Todos', 'delivered' => 'Entregados'] as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['status'] ?? 'pending') === $value)>{{ $label }}</option>
                @endforeach
            </select></div>
            <div class="col-md-4"><label for="office">Oficina</label><select id="office" name="office_cd" class="form-control"><option value="">Todas</option>
                @foreach($catalog['offices'] as $office)<option value="{{ $office->OWN_OFFICE_CD }}" @selected(($filters['office_cd'] ?? '') == $office->OWN_OFFICE_CD)>{{ $office->OFFICE_NM }}</option>@endforeach
            </select></div>
            <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary mt-3">Consultar</button></div>
        </form>
    </div></div>
    <div class="card"><div class="card-header">Paquetes</div><div class="table-responsive">
        <table class="table table-striped mb-0"><thead><tr><th>Código</th><th>Último evento</th><th>Oficina</th><th>Hora local del evento</th><th></th></tr></thead><tbody>
        @forelse($result['data'] as $item)
            <tr><td><a href="{{ route('consultas.index', ['codigo'=>$item['codigo']]) }}"><i class="fas fa-box"></i> {{ $item['codigo'] }}</a></td><td>{{ $item['event_name'] }} ({{ $item['event_cd'] }})</td><td>{{ $item['office_name'] }}</td><td>{{ $item['event_at_local'] ?? $item['event_at'] }}<small class="d-block text-muted">{{ $item['event_timezone_label'] ?? 'UTC' }}</small></td>
                <td><a href="{{ route('operaciones.index', ['q' => $item['codigo'], 'status' => 'all']) }}">Abrir</a></td></tr>
        @empty<tr><td colspan="5">No hay paquetes para estos filtros.</td></tr>@endforelse
        </tbody></table>
    </div><div class="card-footer">
        @if(($result['meta']['page'] ?? 1) > 1)<a class="btn btn-outline-primary" href="{{ route('operaciones.index', array_merge($filters, ['page' => $result['meta']['page'] - 1])) }}">Anterior</a>@endif
        @if($result['meta']['has_more'])<a class="btn btn-outline-primary" href="{{ route('operaciones.index', array_merge($filters, ['page' => $result['meta']['page'] + 1])) }}">Siguiente</a>@endif
    </div></div>

    @if($detail)
        @php($package = $detail['package'])
        <div class="card"><div class="card-header"><h2 class="h5 mb-0">{{ $package['codigo'] }} · Registrar movimiento</h2></div><div class="card-body">
            <p>Último evento: {{ $package['event_cd'] }} · {{ $package['event_at_local'] ?? $package['event_at'] }} ({{ $package['event_timezone_label'] ?? 'UTC' }}). La hora se conserva en UTC y con el offset de la oficina.</p>
            @canany(['ips.events', 'ips.deliver'])
            @php($movementUrl = auth()->user()->can('ips.events')
                ? route('operaciones.event', $package['codigo'])
                : route('operaciones.deliver', $package['codigo']))
            <form id="ips-movement-form"
                  method="POST"
                  action="{{ $movementUrl }}"
                  data-event-url="{{ route('operaciones.event', $package['codigo']) }}"
                  data-delivery-url="{{ route('operaciones.deliver', $package['codigo']) }}">
                @csrf
                <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', (string) \Illuminate\Support\Str::uuid()) }}">
                <input type="hidden" name="expected_event_cd" value="{{ $package['event_cd'] }}">
                <input type="hidden" name="expected_event_at" value="{{ $package['event_at'] }}">
                <div class="row">
                    <div class="col-md-4 form-group"><label for="event">Movimiento</label><select id="event" name="event" class="form-control" required>
                        @foreach(config('ips.events') as $code => $definition)
                            @unless($definition['create'])
                                @if($code === 'EMI')
                                    @can('ips.deliver')<option value="{{ $code }}" @selected(old('event') === $code)>{{ $code }} · {{ $definition['name'] }}</option>@endcan
                                @else
                                    @can('ips.events')<option value="{{ $code }}" @selected(old('event') === $code)>{{ $code }} · {{ $definition['name'] }}</option>@endcan
                                @endif
                            @endunless
                        @endforeach
                    </select></div>
                    <div class="col-md-4 form-group"><label for="event-office">Oficina</label><select id="event-office" name="office_cd" class="form-control" required>
                        @foreach($catalog['offices'] as $office)<option value="{{ $office->OWN_OFFICE_CD }}" @selected(old('office_cd', $package['office_cd']) == $office->OWN_OFFICE_CD)>{{ $office->OFFICE_NM }}</option>@endforeach
                    </select></div>
                    <div class="col-md-4 form-group"><label for="event-at">Fecha y hora efectiva (La Paz)</label><input id="event-at" name="occurred_at" type="datetime-local" step="60" class="form-control" required value="{{ old('occurred_at') ? \Illuminate\Support\Carbon::parse(old('occurred_at'))->timezone('America/La_Paz')->format('Y-m-d\\TH:i') : now('America/La_Paz')->format('Y-m-d\\TH:i') }}"><small>Hora local de la oficina · UTC−04:00. Si la registras con retraso, indica el motivo.</small></div>
                    <div class="col-md-6 form-group"><label for="delivery-mode">Modalidad de entrega (EMI)</label><select id="delivery-mode" name="delivery_mode" class="form-control"><option value="">Seleccionar</option><option value="NO_DOMICILIARIA" @selected(old('delivery_mode') === 'NO_DOMICILIARIA')>Ventanilla · no domiciliaria</option><option value="DOMICILIARIA" @selected(old('delivery_mode') === 'DOMICILIARIA')>Cartero · domiciliaria</option></select></div>
                    <div class="col-md-6 form-group"><label for="signatory">Nombre de quien recibe (entrega EMI)</label><input id="signatory" name="signatory" maxlength="64" class="form-control" value="{{ old('signatory') }}"></div>
                    <div class="col-md-6 form-group"><label for="delivery-location">Lugar de entrega</label><input id="delivery-location" name="delivery_location" maxlength="25" class="form-control" value="{{ old('delivery_location') }}"></div>
                    <div class="col-md-6 form-group"><label for="reason">Motivo de intento fallido (EMH)</label><select id="reason" name="non_delivery_reason" class="form-control"><option value="">Seleccionar</option>
                        @foreach($catalog['non_delivery_reasons'] as $reason)<option value="{{ $reason->NON_DELIVERY_REASON_CD }}">{{ $reason->NON_DELIVERY_REASON_NM }}</option>@endforeach
                    </select></div>
                    <div class="col-md-6 form-group"><label for="measure">Medida aplicada (EMH)</label><select id="measure" name="non_delivery_measure" class="form-control"><option value="">Seleccionar</option>
                        @foreach($catalog['non_delivery_measures'] as $measure)<option value="{{ $measure->NON_DELIVERY_MEASURE_CD }}">{{ $measure->NON_DELIVERY_MEASURE_NM }}</option>@endforeach
                    </select></div>
                    <div class="col-md-12 form-group"><label for="time-reason">Motivo si registra el evento con retraso</label><input id="time-reason" name="time_reason" maxlength="255" class="form-control" value="{{ old('time_reason') }}"><small class="text-muted">Se conserva aparte la hora efectiva y la hora de registro en SITRA.</small></div>
                </div>
                <button class="btn btn-primary" @disabled(!config('ips.writes_enabled') || $package['state_cd'] === 5)>Registrar movimiento</button>
            </form>
            <script>
                (() => {
                    const form = document.getElementById('ips-movement-form');
                    const eventSelect = document.getElementById('event');
                    if (!form || !eventSelect) return;

                    const updateAction = () => {
                        form.action = eventSelect.value === 'EMI'
                            ? form.dataset.deliveryUrl
                            : form.dataset.eventUrl;
                    };

                    eventSelect.addEventListener('change', updateAction);
                    form.addEventListener('submit', updateAction);
                    updateAction();
                })();
            </script>
            @endcanany
        </div></div>
        <div class="card"><div class="card-header">Historial IPS</div><div class="table-responsive"><table class="table"><thead><tr><th>Evento</th><th>Hora local del evento</th><th>Oficina</th></tr></thead><tbody>
            @foreach($detail['events'] as $event)<tr><td>{{ $event->EVENT_TYPE_NM }} ({{ $event->EVENT_TYPE_CD }})</td><td>{{ $event->event_at_local ?? $event->EVENT_GMT_DT }}<small class="d-block text-muted">{{ $event->event_timezone_label ?? 'UTC' }}</small></td><td>{{ $event->EVENT_OFFICE_CD }}</td></tr>@endforeach
        </tbody></table></div></div>
    @endif

    @can('ips.create')
    <details class="card"><summary class="card-header">Crear paquete en IPS</summary><div class="card-body">
        <form method="POST" action="{{ route('operaciones.create') }}">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', (string) \Illuminate\Support\Str::uuid()) }}">
            <div class="row">
                <div class="col-md-4 form-group"><label for="create-code">Código asignado al paquete</label><input id="create-code" name="codigo" maxlength="35" class="form-control" required value="{{ old('codigo') }}"></div>
                <div class="col-md-4 form-group"><label for="create-event">Operación inicial</label><select id="create-event" name="event" class="form-control"><option value="EMA">EMA · Admisión</option><option value="EMD" @selected(old('event') === 'EMD')>EMD · Recepción internacional</option></select></div>
                <div class="col-md-4 form-group"><label for="create-at">Fecha y hora efectiva (La Paz)</label><input id="create-at" name="occurred_at" type="datetime-local" step="60" class="form-control" required value="{{ old('occurred_at') ? \Illuminate\Support\Carbon::parse(old('occurred_at'))->timezone('America/La_Paz')->format('Y-m-d\\TH:i') : now('America/La_Paz')->format('Y-m-d\\TH:i') }}"><small>Hora local de la oficina · UTC−04:00.</small></div>
                <div class="col-md-4 form-group"><label for="create-office">Oficina</label><select id="create-office" name="office_cd" class="form-control" required>@foreach($catalog['offices'] as $office)<option value="{{ $office->OWN_OFFICE_CD }}" @selected(old('office_cd') == $office->OWN_OFFICE_CD)>{{ $office->OFFICE_NM }}</option>@endforeach</select></div>
                <div class="col-md-4 form-group"><label for="mail-class">Clase postal</label><select id="mail-class" name="mail_class" class="form-control" required>@foreach($catalog['mail_classes'] as $class)<option value="{{ $class->MAIL_CLASS_CD }}" @selected(old('mail_class') === trim($class->MAIL_CLASS_CD))>{{ $class->MAIL_CLASS_NM }}</option>@endforeach</select></div>
                <div class="col-md-4 form-group"><label for="weight">Peso (kg)</label><input id="weight" name="weight_kg" type="number" min="0.001" max="999.999" step="0.001" class="form-control" required value="{{ old('weight_kg') }}"></div>
                @foreach(['origin_country' => 'País de origen', 'destination_country' => 'País de destino'] as $field => $label)
                    <div class="col-md-6 form-group"><label for="{{ $field }}">{{ $label }}</label><select id="{{ $field }}" name="{{ $field }}" class="form-control" required>
                        @foreach($catalog['countries'] as $country)<option value="{{ trim($country->COUNTRY_CD) }}" @selected(old($field, 'BO') === trim($country->COUNTRY_CD))>{{ $country->COUNTRY_NM }}</option>@endforeach
                    </select></div>
                @endforeach
            </div>
            @foreach(['sender' => 'Remitente', 'recipient' => 'Destinatario'] as $party => $label)
                <fieldset><legend class="h5">{{ $label }}</legend><div class="row">
                    @foreach(['name' => ['Nombre', 64], 'address' => ['Dirección', 105], 'city' => ['Ciudad', 32], 'country' => ['País (código de dos letras)', 2], 'postcode' => ['Código postal', 20], 'phone' => ['Teléfono', 64], 'email' => ['Correo', 64]] as $field => [$caption, $length])
                        <div class="col-md-4 form-group"><label for="{{ $party }}-{{ $field }}">{{ $caption }}</label><input id="{{ $party }}-{{ $field }}" name="{{ $party }}[{{ $field }}]" maxlength="{{ $length }}" class="form-control" value="{{ old($party.'.'.$field, $field === 'country' ? 'BO' : '') }}" @required(in_array($field, ['name', 'address', 'city', 'country']))></div>
                    @endforeach
                </div></fieldset>
            @endforeach
            <button class="btn btn-primary" @disabled(!config('ips.writes_enabled'))>Crear paquete</button>
        </form>
    </div></details>
    @endcan

    @can('ips.operations')
    <div class="card"><div class="card-header">Mis últimas operaciones</div><div class="table-responsive"><table class="table"><thead><tr><th>Referencia</th><th>Paquete</th><th>Evento</th><th>Resultado</th><th>Hora efectiva local</th><th>Instante enviado a IPS (UTC)</th><th>Registrado en SITRA (UTC)</th></tr></thead><tbody>
        @forelse($operations as $operation)<tr><td><code>{{ $operation->id }}</code></td><td>{{ $operation->codigo }}</td><td>{{ $operation->event }} @if($operation->delivery_mode)<small class="d-block">{{ $operation->delivery_mode === 'DOMICILIARIA' ? 'Cartero · domiciliaria' : 'Ventanilla · no domiciliaria' }}</small>@endif</td><td>{{ $operation->status }}</td><td>{{ $operation->event_at_local ?? "-" }}<small class="d-block">{{ $operation->event_timezone_label ?? "Zona no informada" }}</small></td><td>{{ $operation->event_at_utc ?? "-" }}</td><td>{{ $operation->registered_at_utc ?? $operation->created_at }}</td></tr>
        @empty<tr><td colspan="7">Todavía no hay operaciones registradas.</td></tr>@endforelse
    </tbody></table></div></div>
    @endcan
@stop
