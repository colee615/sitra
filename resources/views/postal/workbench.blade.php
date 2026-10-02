@extends('adminlte::page')
@section('title', 'Gestión diaria | SITRA')
@section('content_header')
<div class="postal-heading"><div><span class="postal-eyebrow">CENTRO DE TRABAJO</span><h1>Gestión diaria</h1><p>Hola, {{ auth()->user()->name }}. Encuentra un envío o elige tu próxima tarea.</p></div></div>
@stop
@section('content')
    @include('postal.home')
    @cannot('postal.access')<div class="postal-panel"><h2>Tu cuenta está lista</h2><p>Solicita a un administrador los permisos de consulta para trabajar con IPS o CDS.</p></div>@endcannot
    @include('footer')
@stop
@section('css')<link rel="stylesheet" href="{{ asset('css/postal-navigation.css') }}">@stop
