@extends('adminlte::page')

@section('title', 'Credenciales API | SITRA')

@section('content_header')
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <div>
            <h1 class="mb-1">Credenciales API</h1>
            <p class="text-muted mb-0">Consulta las APIs de SITRA y administra los tokens de esta cuenta administradora.</p>
        </div>
        <div class="mt-2 mt-md-0">
            <button class="btn btn-warning mr-2" type="button" data-toggle="modal" data-target="#createApiCredentialModal">
                <i class="fas fa-plus mr-1"></i> Nueva API
            </button>
            <a class="btn btn-outline-success" href="{{ route('api-credentials.manual') }}">
                <i class="fas fa-file-download mr-1"></i> Descargar manual
            </a>
        </div>
    </div>
@stop

@section('css')
    <style>
        .api-credentials-hero {
            align-items: center;
            background: linear-gradient(110deg, #173e6b, #285da0);
            border-radius: 14px;
            box-shadow: 0 10px 24px rgba(23, 62, 107, .18);
            color: #fff;
            display: flex;
            gap: 18px;
            margin-bottom: 22px;
            padding: 24px;
        }
        .api-credentials-hero .hero-icon {
            align-items: center;
            background: rgba(255, 255, 255, .16);
            border-radius: 12px;
            display: flex;
            flex: 0 0 52px;
            height: 52px;
            justify-content: center;
        }
        .api-credentials-hero h2 { font-size: 22px; font-weight: 700; margin: 0 0 4px; }
        .api-credentials-hero p { color: rgba(255, 255, 255, .78); margin: 0; }
        .api-credentials-card { border: 0; border-radius: 12px; box-shadow: 0 3px 14px rgba(25, 45, 70, .07); margin-bottom: 22px; }
        .api-credentials-card .card-title { color: #18334e; font-weight: 700; }
        .api-credentials-table td, .api-credentials-table th { vertical-align: middle; }
        .api-credentials-table code { overflow-wrap: anywhere; white-space: normal; }
        .api-credential-badge { background: #edf4ff; border-radius: 999px; color: #254a75; display: inline-block; font-size: 12px; font-weight: 600; margin: 2px; padding: 5px 9px; }
        .api-token-output { font-family: monospace; min-height: 66px; min-width: 240px; }
        .api-url-cell { min-width: 280px; }
        .api-url-row { align-items: flex-start; display: flex; gap: 6px; margin-bottom: 8px; }
        .api-url-row code { color: #df3477; flex: 1; }
        .api-endpoint-method { display: inline-block; font-size: 11px; font-weight: 700; min-width: 54px; text-align: center; }
        .api-group-heading { background: #f4f7fa; color: #18334e; font-weight: 700; }
        @media (max-width: 767px) {
            .api-credentials-hero { align-items: flex-start; padding: 18px; }
            .api-credentials-hero h2 { font-size: 18px; }
            .api-credentials-table { min-width: 760px; }
        }
    </style>
@stop

@section('content')
    <div class="container-fluid">
        @if (session('success'))
            <div class="alert alert-success" role="status">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
        @endif

        <section class="api-credentials-hero">
            <span class="hero-icon"><i class="fas fa-plug fa-lg" aria-hidden="true"></i></span>
            <div>
                <h2>Integraciones seguras y fáciles de entender</h2>
                <p>Genera un token independiente para un permiso API y úsalo como Bearer en Postman u otro sistema.</p>
            </div>
        </section>

        <div class="row">
            <div class="col-12">
                <section class="card api-credentials-card">
                    <div class="card-header bg-white border-0 pt-4 px-4">
                        <h2 class="card-title h5 mb-1">APIs disponibles</h2>
                        <p class="text-muted mb-0">Rutas incluidas en SITRA y el permiso requerido por cada una. Una credencial nueva autoriza un permiso; varias rutas pueden compartir ese permiso.</p>
                    </div>
                    <div class="card-body pt-3">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover api-credentials-table mb-0">
                                <thead class="thead-light">
                                    <tr>
                                        <th>Método</th>
                                        <th>URL para probar</th>
                                        <th>Descripción</th>
                                        <th>Permiso</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($apis as $api)
                                        @if ($loop->first || $apis[$loop->index - 1]['group'] !== $api['group'])
                                            <tr class="api-group-heading"><td colspan="4">{{ $api['group'] }}</td></tr>
                                        @endif
                                        <tr>
                                            <td>
                                                <span class="badge api-endpoint-method {{ $api['method'] === 'GET' ? 'badge-success' : 'badge-warning' }}">
                                                    {{ $api['method'] }}
                                                </span>
                                            </td>
                                            <td>
                                                <code>{{ url($api['path']) }}</code>
                                                <button class="btn btn-xs btn-outline-primary js-copy-value mt-1" type="button" data-copy-value="{{ url($api['path']) }}">
                                                    <i class="far fa-copy mr-1"></i> Copiar URL
                                                </button>
                                            </td>
                                            <td>{{ $api['description'] }}</td>
                                            <td><span class="api-credential-badge">{{ $api['ability'] }}</span></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
            </div>
        </div>

        <section class="card api-credentials-card">
            <div class="card-header bg-white border-0 pt-4 px-4">
                <h2 class="card-title h5 mb-1">Credenciales creadas</h2>
                <p class="text-muted mb-0">URLs y tokens de esta cuenta administradora. Los tokens emitidos desde esta pantalla se guardan cifrados; los tokens anteriores siguen ocultos si no tienen secreto recuperable.</p>
            </div>
            <div class="card-body pt-3">
                @if ($tokens->isEmpty())
                    <div class="alert alert-light border mb-0">Todavía no hay credenciales API para esta cuenta.</div>
                @else
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover api-credentials-table mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th>Integración</th>
                                    <th>APIs autorizadas</th>
                                    <th>URLs para probar</th>
                                    <th>Estado y uso</th>
                                    <th>Token Bearer</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($tokens as $token)
                                    <tr>
                                        <td>
                                            <strong>{{ $token->name }}</strong>
                                            <div><small class="text-muted">Creado: {{ $token->created_at?->format('d/m/Y H:i') }}</small></div>
                                            <div><small class="text-muted">Vence: {{ $token->expires_at?->format('d/m/Y H:i') ?? 'Sin vencimiento' }}</small></div>
                                        </td>
                                        <td>
                                            @foreach ($token->abilities ?? [] as $ability)
                                                <span class="api-credential-badge"><i class="fas fa-check-circle mr-1"></i>{{ $ability }}</span>
                                            @endforeach
                                        </td>
                                        <td class="api-url-cell">
                                            @php
                                                $tokenApis = collect($apis)->filter(fn ($api) => in_array('*', $token->abilities ?? [], true)
                                                    || in_array($api['ability'], $token->abilities ?? [], true));
                                            @endphp
                                            @forelse ($tokenApis as $api)
                                                <div class="api-url-row">
                                                    <span class="badge api-endpoint-method {{ $api['method'] === 'GET' ? 'badge-success' : 'badge-warning' }}">{{ $api['method'] }}</span>
                                                    <code>{{ url($api['path']) }}</code>
                                                    <button class="btn btn-xs btn-outline-primary js-copy-value" type="button" data-copy-value="{{ url($api['path']) }}" aria-label="Copiar URL">
                                                        <i class="far fa-copy"></i>
                                                    </button>
                                                </div>
                                            @empty
                                                <small class="text-muted">No hay rutas catalogadas para este permiso.</small>
                                            @endforelse
                                        </td>
                                        <td>
                                            @if ($token->expires_at?->isPast())
                                                <span class="badge badge-secondary">Vencido</span>
                                            @else
                                                <span class="badge badge-success">Activo</span>
                                            @endif
                                            <div class="mt-1"><small class="text-muted">Último uso:</small></div>
                                            <small class="text-muted">{{ $token->last_used_at?->format('d/m/Y H:i') ?? 'Sin uso registrado' }}</small>
                                        </td>
                                        <td>
                                            @if ($token->plain_text_token)
                                                <textarea id="api-token-{{ $token->id }}" class="form-control api-token-output" readonly rows="3">{{ $token->plain_text_token }}</textarea>
                                                <button class="btn btn-sm btn-outline-primary js-copy-value mt-1" type="button" data-copy-target="api-token-{{ $token->id }}">
                                                    <i class="far fa-copy mr-1"></i> Copiar token
                                                </button>
                                            @else
                                                <small class="text-muted">Token anterior no recuperable. Para obtener uno nuevo, usa Rotar.</small>
                                            @endif
                                        </td>
                                        <td>
                                            <div class="d-flex flex-column">
                                                @unless (in_array('cds.read', $token->abilities ?? [], true))
                                                    <form method="post" action="{{ route('api-credentials.grant-cds-read', $token->id) }}" onsubmit="return confirm('Se agregará cds.read y se conservarán los permisos actuales. ¿Continuar?')">
                                                        @csrf
                                                        <button class="btn btn-sm btn-outline-primary mb-1" type="submit">
                                                            <i class="fas fa-unlock-alt mr-1"></i> Autorizar CDS
                                                        </button>
                                                    </form>
                                                @endunless
                                                <form method="post" action="{{ route('api-credentials.rotate', $token->id) }}" onsubmit="return confirm('Al rotar se revocará el token anterior. ¿Continuar?')">
                                                    @csrf
                                                    <button class="btn btn-sm btn-outline-warning mb-1" type="submit">
                                                        <i class="fas fa-sync-alt mr-1"></i> Rotar
                                                    </button>
                                                </form>
                                                <form method="post" action="{{ route('api-credentials.revoke', $token->id) }}" onsubmit="return confirm('¿Revocar esta credencial? La integración dejará de tener acceso.')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button class="btn btn-sm btn-outline-danger" type="submit">
                                                        <i class="fas fa-ban mr-1"></i> Revocar
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </section>
    </div>

    <div class="modal fade" id="createApiCredentialModal" tabindex="-1" role="dialog" aria-labelledby="createApiCredentialTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <form method="post" action="{{ route('api-credentials.store') }}">
                    @csrf
                    <div class="modal-header">
                        <h2 class="modal-title h5" id="createApiCredentialTitle">Crear credencial API</h2>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted">Cada credencial nueva tendrá un solo permiso. Su URL y el token recién emitido aparecerán en su fila de la tabla.</p>
                        <div class="form-group">
                            <label for="api-token-name">Nombre de la integración</label>
                            <input id="api-token-name" name="name" class="form-control" maxlength="100" required value="{{ old('name') }}" placeholder="Ej.: CRM CDS">
                        </div>
                        <fieldset class="form-group">
                            <legend class="col-form-label font-weight-bold">API autorizada</legend>
                            @foreach ($abilities as $ability => $label)
                                <div class="custom-control custom-radio mb-2">
                                    <input class="custom-control-input" type="radio" id="ability-{{ $loop->index }}" name="ability" value="{{ $ability }}" required @checked(old('ability') === $ability)>
                                    <label class="custom-control-label" for="ability-{{ $loop->index }}">
                                        {{ $label }} <small class="text-muted">({{ $ability }})</small>
                                    </label>
                                </div>
                            @endforeach
                        </fieldset>
                        <div class="form-group mb-0">
                            <label for="api-token-expiry">Vigencia</label>
                            <select id="api-token-expiry" name="expires_in" class="form-control" required>
                                <option value="90" @selected(old('expires_in', '90') === '90')>90 días</option>
                                <option value="30" @selected(old('expires_in') === '30')>30 días</option>
                                <option value="365" @selected(old('expires_in') === '365')>1 año</option>
                                <option value="never" @selected(old('expires_in') === 'never')>Sin vencimiento</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
                        <button class="btn btn-warning" type="submit"><i class="fas fa-plus mr-1"></i> Crear y mostrar token</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@stop

@section('js')
    <script>
        @if ($errors->any())
            $('#createApiCredentialModal').modal('show');
        @endif

        document.querySelectorAll('.js-copy-value').forEach((button) => {
            button.addEventListener('click', async () => {
                let field = button.dataset.copyTarget
                    ? document.getElementById(button.dataset.copyTarget)
                    : null;
                const value = button.dataset.copyValue || field?.value;
                if (!value) {
                    return;
                }
                if (!field) {
                    field = document.createElement('textarea');
                    field.value = value;
                    field.setAttribute('readonly', '');
                    field.style.position = 'fixed';
                    field.style.opacity = '0';
                    document.body.appendChild(field);
                }
                field.focus();
                field.select();
                let copied = false;
                if (navigator.clipboard && window.isSecureContext) {
                    try {
                        await navigator.clipboard.writeText(value);
                        copied = true;
                    } catch (error) {
                        copied = document.execCommand('copy');
                    }
                } else {
                    copied = document.execCommand('copy');
                }
                if (!button.dataset.copyTarget) {
                    field.remove();
                }
                if (!copied) {
                    button.textContent = 'Copia manualmente el texto seleccionado';
                    return;
                }
                button.innerHTML = '<i class="fas fa-check mr-1"></i> Copiado';
            });
        });
    </script>
@stop
