@extends('adminlte::page')
@section('title', 'Asignaciones de permisos | SITRA')
@section('content_header')
<div class="postal-heading"><div><span class="postal-eyebrow">CONFIGURACIÓN Y ACCESOS</span><h1>Asignaciones de permisos</h1><p>Relaciona los permisos con los roles del sistema.</p></div></div>
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
                                {{ __('Asignación de permisos') }}
                            </span>

                            <div class="float-right">
                                <a href="{{ route('role-has-permissions.create') }}"
                                    class="btn btn-primary btn-sm float-right" data-placement="left">
                                    {{ __('Crear asignación') }}
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

                                        <th>Permiso</th>
                                        <th>Rol</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($roleHasPermissions as $roleHasPermission)
                                        <tr>
                                            <td>{{ ++$i }}</td>

                                            <td>{{ $permissions[$roleHasPermission->permission_id] }}</td>
                                            <td>{{ $roles[$roleHasPermission->role_id] }}</td>

                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                {!! $roleHasPermissions->links() !!}
            </div>
        </div>
    </div>
    @include('footer')
@endsection
