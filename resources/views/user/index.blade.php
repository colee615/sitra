@extends('adminlte::page')
@section('title', 'Personal AGBC | SITRA')
@section('content_header')
<div class="postal-heading"><div><span class="postal-eyebrow">CONFIGURACIÓN Y ACCESOS</span><h1>Personal AGBC</h1><p>Administra usuarios, roles y acceso al sistema.</p></div></div>
@stop

@section('template_title')
    Paqueteria Postal
@endsection

@section('content')
@livewire('users')
@endsection
