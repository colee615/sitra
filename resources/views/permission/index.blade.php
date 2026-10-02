@extends('adminlte::page')
@section('title', 'Permisos del sistema | SITRA')
@section('content_header')
<div class="postal-heading"><div><span class="postal-eyebrow">CONFIGURACIÓN Y ACCESOS</span><h1>Permisos del sistema</h1><p>Administra las capacidades disponibles para el equipo.</p></div></div>
@stop

@section('template_title')
    Paqueteria Postal
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-sm-12">
                <div class="card">
                    <div class="card-header">
                        <div style="display: flex; justify-content: space-between; align-items: center;">

                            <span id="card_title">
                                {{ __('Permisos registrados') }}
                            </span>

                            <div class="float-right">
                                <a href="{{ route('permissions.create') }}" class="btn btn-primary btn-sm float-right"
                                    data-placement="left">
                                    {{ __('Crear Nuevo') }}
                                </a>
                            </div>
                        </div>
                    </div>
                    @if ($message = Session::get('success'))
                        <div class="alert alert-success">
                            <p>{{ $message }}</p>
                        </div>
                    @endif

                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead class="thead">
                                    <tr>
                                        <th>No</th>

                                        <th>Ruta</th>

                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($permissions as $permission)
                                        <tr>
                                            <td>{{ ++$i }}</td>

                                            <td>{{ $permission->name }}</td>

                                            <td>
                                                <form action="{{ route('permissions.destroy', $permission->id) }}"
                                                    method="POST" data-confirm="Se eliminará este permiso y sus asignaciones. Revisa el impacto antes de continuar.">
                                                    <a class="btn btn-sm btn-success"
                                                        href="{{ route('permissions.edit', $permission->id) }}"><i
                                                            class="fa fa-fw fa-edit"></i> {{ __('Editar') }}</a>
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-danger btn-sm"><i
                                                            class="fa fa-fw fa-trash"></i> {{ __('Eliminar') }}</button>
                                                </form>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                {!! $permissions->links() !!}
            </div>
        </div>
    </div>
    @include('footer')
@endsection
