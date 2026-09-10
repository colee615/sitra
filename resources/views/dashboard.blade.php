@extends('adminlte::page')
@section('title', 'Operaciones postales')
@section('content_header')
    <h1>Operaciones postales</h1>
@stop
@section('content')
    <div class="card"><div class="card-body">
        <h2 class="h4">Seguimiento de paquetes</h2>
        <p>Consulta movimientos, oficinas, despachos y entregas de IPS.</p>
        <form method="GET" action="{{ route('consultas.index') }}">
            <div class="input-group">
                <input name="codigo" maxlength="35" class="form-control" placeholder="Código postal o identificador local" required aria-label="Código del paquete">
                <div class="input-group-append"><button class="btn btn-primary">Consultar</button></div>
            </div>
        </form>
    </div></div>
    @can('admin-only')
        <a class="btn btn-primary mb-3" href="{{ route('operaciones.index') }}">Gestionar paquetes y entregas</a>
    @endcan
    @include('footer')
@stop
