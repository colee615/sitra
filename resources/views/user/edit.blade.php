@extends('adminlte::page')
@section('title', 'Editar · Personal AGBC | SITRA')
@section('content_header')
<div class="postal-heading"><div><span class="postal-eyebrow">CONFIGURACIÓN Y ACCESOS</span><h1>Editar · Personal AGBC</h1><p>Administra usuarios, roles y acceso al sistema.</p></div></div>
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
                        <span class="card-title">{{ __('Actualizar') }} Usuarios</span>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('users.update', $user->id) }}"  role="form" enctype="multipart/form-data">
                            @method('PUT')
                            @csrf

                            @include('user.form')

                        </form>
                    </div>
                </div>
            </div>
        </div>
        @include('footer')
    </section>
@endsection
