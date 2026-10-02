@extends('adminlte::page')
@section('title', 'Panorama operativo | SITRA')
@section('content_header')
    <div class="postal-heading dashboard-heading"><div><span class="postal-eyebrow">INTELIGENCIA POSTAL</span><h1>Panorama operativo<span class="heading-dot">.</span></h1><p>La operación de Correos de Bolivia, en perspectiva.</p></div><a class="btn btn-primary" href="{{ route('postal.workbench') }}"><i class="fas fa-arrow-right mr-2"></i> Ir a gestión diaria</a></div>
@stop
@section('css')
    <link rel="stylesheet" href="{{ asset('css/operational-dashboard.css') }}?v=1">
@stop
@section('content')
    @can('postal.access') @include('postal.dashboard-content') @endcan
    @cannot('postal.access')
        <div class="postal-panel"><h2><i class="fas fa-lock"></i> Tu cuenta está lista</h2><p>Un administrador debe asignarte los permisos de consulta para trabajar con IPS o CDS.</p></div>
    @endcannot
    @include('footer')
@stop
@section('js')<script src="{{ asset('js/operational-dashboard.js') }}?v=1" defer></script>@stop
