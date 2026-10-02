@extends('adminlte::page')
@section('title', 'Crear · Roles del equipo | SITRA')
@section('content_header')
<div class="postal-heading"><div><span class="postal-eyebrow">CONFIGURACIÓN Y ACCESOS</span><h1>Crear · Roles del equipo</h1><p>Organiza las responsabilidades y niveles de acceso.</p></div></div>
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
                        <span class="card-title">{{ __('Crear un nuevo Rol') }}</span>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('roles.store') }}"  role="form" enctype="multipart/form-data">
                            @csrf

                            @include('role.form')

                        </form>
                    </div>
                </div>
            </div>
        </div>
    </section>
    @include('footer')
    @endsection