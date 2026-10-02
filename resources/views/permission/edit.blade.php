@extends('adminlte::page')
@section('title', 'Editar · Permisos del sistema | SITRA')
@section('content_header')
<div class="postal-heading"><div><span class="postal-eyebrow">CONFIGURACIÓN Y ACCESOS</span><h1>Editar · Permisos del sistema</h1><p>Administra las capacidades disponibles para el equipo.</p></div></div>
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
                        <span class="card-title">{{ __('Editar') }} Permisos</span>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('permissions.update', $permission->id) }}" role="form"
                            enctype="multipart/form-data">
                            @method('PUT')
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
