@extends('adminlte::page')
@section('title', 'Detalle · Asignaciones de permisos | SITRA')
@section('content_header')
<div class="postal-heading"><div><span class="postal-eyebrow">CONFIGURACIÓN Y ACCESOS</span><h1>Detalle · Asignaciones de permisos</h1><p>Relaciona los permisos con los roles del sistema.</p></div></div>
@stop

@section('template_title')
    Paqueteria Postal
@endsection

@section('content')
    <section class="content container-fluid">
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <div class="float-left">
                            <span class="card-title">{{ __('Show') }} Asignación de permisos</span>
                        </div>
                        <div class="float-right">
                            <a class="btn btn-primary" href="{{ route('role-has-permissions.index') }}"> {{ __('Back') }}</a>
                        </div>
                    </div>

                    <div class="card-body">
                        
                        <div class="form-group">
                            <strong>Permiso:</strong>
                            {{ $roleHasPermission->permission_id }}
                        </div>
                        <div class="form-group">
                            <strong>Rol:</strong>
                            {{ $roleHasPermission->role_id }}
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </section>
    @include('footer')
@endsection
