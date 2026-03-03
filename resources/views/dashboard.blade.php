@extends('adminlte::page')

@section('title', 'Dashboard')

@section('content_header')
    <h1>Dashboard</h1>
@stop

@section('content')
    <div class="row">
        <div class="col-lg-4 col-12">
            <div class="small-box bg-info">
                <div class="inner">
                    <h4>Consultas Externas</h4>
                    <p>Conexion y busqueda en SQL Server</p>
                </div>
                <div class="icon">
                    <i class="fas fa-database"></i>
                </div>
                @can('admin-only')
                    <a href="{{ route('sqlserver.datos') }}" class="small-box-footer">
                        Ingresar <i class="fas fa-arrow-circle-right"></i>
                    </a>
                @else
                    <span class="small-box-footer">Solo administradores</span>
                @endcan
            </div>
        </div>
    </div>
    @include('footer')
@stop

@section('css')
    <link rel="stylesheet" href="{{ asset('vendor/fontawesome-free/css/all.min.css') }}">
@stop

@section('js')
    <script> console.log('Dashboard cargado'); </script>
@stop
