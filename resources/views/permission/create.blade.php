@extends('adminlte::page')
@section('title', 'Crear · Permisos del sistema | SITRA')
@section('content_header')
<div class="postal-heading"><div><span class="postal-eyebrow">CONFIGURACIÓN Y ACCESOS</span><h1>Crear · Permisos del sistema</h1><p>Administra las capacidades disponibles para el equipo.</p></div></div>
@stop

@section('template_title')
    Paqueteria Postal
@endsection

@section('content')
    <section class="content container-fluid">
        <div class="row">
            <div class="col-md-12">

                @includeif('partials.errors')

                <div class="card card-default">
                    <div class="card-header">
                        <span class="card-title">{{ __('Crear Nuevo') }} Permisos</span>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('permissions.store') }}" role="form"
                            enctype="multipart/form-data">
                            @csrf

                            @include('permission.form')

                        </form>
                    </div>
                </div>
            </div>
        </div>
    </section>
    @include('footer')
@endsection
