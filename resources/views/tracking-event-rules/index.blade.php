@extends('adminlte::page')

@section('title', 'Reglas de Eventos')

@section('content_header')
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <div>
            <h1 class="mb-1">Eventos API</h1>
            <p class="text-muted mb-0">Administra como se muestran los eventos del endpoint <code>/api/tracking/eventos</code>.</p>
        </div>
        <div class="d-flex mt-2 mt-md-0">
            <form method="POST" action="{{ route('tracking-event-rules.sync') }}" class="mr-2">
                @csrf
                <button type="submit" class="btn btn-outline-primary">Sincronizar desde IPS</button>
            </form>
            <a href="{{ route('tracking-event-rules.create') }}" class="btn btn-primary">Crear regla</a>
        </div>
    </div>
@endsection

@section('css')
    <style>
        .tracking-rules-card .card-title {
            font-weight: 600;
        }

        .tracking-rules-toolbar .form-control,
        .tracking-rules-toolbar .btn {
            height: calc(2.25rem + 2px);
        }

        .tracking-rules-table td,
        .tracking-rules-table th {
            vertical-align: middle;
        }

        .tracking-rules-table .actions-cell {
            width: 180px;
            white-space: nowrap;
        }

        .tracking-rules-table .visibility-cell {
            width: 140px;
            white-space: nowrap;
        }

        .tracking-rules-toggle-form {
            margin: 0;
        }

        .tracking-rules-switch {
            align-items: center;
            background: #cfd6df;
            border: 0;
            border-radius: 999px;
            cursor: pointer;
            display: inline-flex;
            height: 28px;
            padding: 3px;
            position: relative;
            transition: background-color .2s ease;
            width: 56px;
        }

        .tracking-rules-switch.is-visible {
            background: #28a745;
        }

        .tracking-rules-switch__thumb {
            background: #fff;
            border-radius: 50%;
            box-shadow: 0 1px 3px rgba(0, 0, 0, .25);
            display: block;
            height: 22px;
            transform: translateX(0);
            transition: transform .2s ease;
            width: 22px;
        }

        .tracking-rules-switch.is-visible .tracking-rules-switch__thumb {
            transform: translateX(28px);
        }

        .tracking-rules-switch:focus {
            outline: 0;
            box-shadow: 0 0 0 .2rem rgba(0, 123, 255, .25);
        }

        .tracking-rules-empty {
            padding: 2rem 1rem;
        }
    </style>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="card tracking-rules-card">
            <div class="card-header">
                <h3 class="card-title">Reglas de visualizacion</h3>
            </div>

            @if ($message = Session::get('success'))
                <div class="alert alert-success mb-0 rounded-0">{{ $message }}</div>
            @endif

            <div class="card-body border-bottom tracking-rules-toolbar">
                <form method="GET" action="{{ route('tracking-event-rules.index') }}">
                    <div class="row">
                        <div class="col-md-5">
                            <input type="text" name="q" class="form-control" value="{{ request('q') }}" placeholder="Buscar por nombre o fuente">
                        </div>
                        <div class="col-md-3">
                            <select name="source_db" class="form-control">
                                <option value="">Todos los origenes</option>
                                @foreach($sourceOptions as $value => $label)
                                    <option value="{{ $value }}" @selected(request('source_db') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        @php($visibleFilter = (string) request()->query('visible', ''))
                        <div class="col-md-2">
                            <select name="visible" class="form-control">
                                <option value="">Todos</option>
                                <option value="1" @selected($visibleFilter === '1')>Visibles</option>
                                <option value="0" @selected($visibleFilter === '0')>Ocultos</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary btn-block">Filtrar</button>
                        </div>
                    </div>
                </form>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0 tracking-rules-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Origen</th>
                                <th>Codigo</th>
                                <th>Nombre original BD</th>
                                <th>Visible API</th>
                                <th>Mostrar como</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($rules as $rule)
                                <tr>
                                    <td>{{ $rule->id }}</td>
                                    <td>{{ $sourceOptions[$rule->source_db] ?? $rule->source_db }}</td>
                                    <td>{{ $rule->event_type_cd ?? '-' }}</td>
                                    <td>{{ $rule->raw_name !== '' ? $rule->raw_name : '-' }}</td>
                                    <td class="visibility-cell">
                                        <form method="POST" action="{{ route('tracking-event-rules.toggle-visibility', $rule) }}" class="tracking-rules-toggle-form">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="redirect_to" value="{{ request()->fullUrl() }}">
                                            <button
                                                type="submit"
                                                class="tracking-rules-switch {{ $rule->is_visible ? 'is-visible' : '' }}"
                                                role="switch"
                                                aria-checked="{{ $rule->is_visible ? 'true' : 'false' }}"
                                                title="{{ $rule->is_visible ? 'Ocultar este evento en la API' : 'Mostrar este evento en la API' }}">
                                                <span class="tracking-rules-switch__thumb"></span>
                                            </button>
                                        </form>
                                    </td>
                                    <td>{{ $rule->display_name ?: 'Usa nombre original BD' }}</td>
                                    <td class="text-right actions-cell">
                                        <form method="POST" action="{{ route('tracking-event-rules.destroy', $rule) }}">
                                            <a href="{{ route('tracking-event-rules.edit', $rule) }}" class="btn btn-sm btn-success">Editar</a>
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-danger">Eliminar</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center text-muted tracking-rules-empty">No hay reglas registradas todavia.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-center mt-3">
            {{ $rules->links('pagination::bootstrap-5') }}
        </div>
    </div>
    @include('footer')
@endsection
