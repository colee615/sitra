@extends('adminlte::page')

@section('title', 'Editar Regla de Evento')

@section('content')
    <div class="container-fluid">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Editar regla de visualización</h3>
            </div>
            <form method="POST" action="{{ route('tracking-event-rules.update', $rule) }}">
                @php($method = 'PUT')
                @include('tracking-event-rules._form')
            </form>
        </div>
    </div>
    @include('footer')
@endsection
