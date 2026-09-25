@extends('adminlte::page')
@section('title', 'Inicio | SITRA')
@section('content_header')
    <div class="postal-heading"><div><span class="postal-eyebrow">CENTRO DE TRABAJO</span><h1>Hola, {{ auth()->user()->name }}</h1><p>Consulta, verifica y gestiona cada envío desde un mismo lugar.</p></div></div>
@stop
@section('content')
    @include('postal.home')
    @cannot('postal.access')
        <div class="postal-panel"><h2><i class="fas fa-lock"></i> Tu cuenta está lista</h2><p>Un administrador debe asignarte los permisos de consulta para trabajar con IPS o CDS.</p></div>
    @endcannot
    @include('footer')
@stop
