@extends('adminlte::page')
@section('title', 'Detalle · Permisos del sistema | SITRA')
@section('content_header')
<div class="postal-heading"><div><span class="postal-eyebrow">CONFIGURACIÓN Y ACCESOS</span><h1>Detalle · Permisos del sistema</h1><p>Administra las capacidades disponibles para el equipo.</p></div></div>
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
                            <span class="card-title">{{ __('Show') }} Permission</span>
                        </div>
                        <div class="float-right">
                            <a class="btn btn-primary" href="{{ route('permissions.index') }}"> {{ __('Back') }}</a>
                        </div>
                    </div>

                    <div class="card-body">

                        <div class="form-group">
                            <strong>Name:</strong>
                            {{ $permission->name }}
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </section>
    @include('footer')
@endsection
