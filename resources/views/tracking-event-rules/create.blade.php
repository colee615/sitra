@extends('adminlte::page')

@section('title', 'Crear Regla de Evento')

@section('content')
    <div class="container-fluid">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Crear regla de visualización</h3>
            </div>
            <form method="POST" action="{{ route('tracking-event-rules.store') }}">
                @include('tracking-event-rules._form')
            </form>
        </div>
    </div>
    @include('footer')
@endsection
