@extends('adminlte::page')
@section('title', 'Editar · Asignaciones de permisos | SITRA')
@section('content_header')
<div class="postal-heading"><div><span class="postal-eyebrow">CONFIGURACIÓN Y ACCESOS</span><h1>Editar · Asignaciones de permisos</h1><p>Relaciona los permisos con los roles del sistema.</p></div></div>
@stop

@section('template_title')
    Paqueteria Postal
@endsection

@section('content')
    <section class="content container-fluid">
        <div class="">
            <div class="col-md-12">

                @includeif('partials.errors')

                <div class="card card-default">
                    <div class="card-header">
                        <span class="card-title">{{ __('Editar') }} Asignación de permisos</span>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('role-has-permissions.update', $roleHasPermission->id) }}"  role="form" enctype="multipart/form-data">
                            @method('PUT')
                            @csrf

                            @include('role-has-permission.form')

                        </form>
                    </div>
                </div>
            </div>
        </div>
    </section>
    @include('footer')
@endsection
