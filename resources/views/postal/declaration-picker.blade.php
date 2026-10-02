@extends('adminlte::page')

@section('title', 'Elegir declaración · SITRA')

@section('content_header')
    <div class="postal-heading">
        <div>
            <span class="postal-eyebrow">SITRA · GESTIÓN ADUANERA</span>
            <h1>Elige la declaración que deseas imprimir</h1>
            <p>El paquete {{ $code }} tiene varias declaraciones registradas en CDS.</p>
        </div>
        <a class="btn btn-outline-primary" href="{{ route('consultas.index', ['codigo' => $code]) }}">Volver al expediente</a>
    </div>
@stop

@section('content')
    <div class="postal-workspace">
        <section class="postal-panel">
            @foreach($declarations as $declaration)
                <article class="postal-declaration mb-3">
                    <div class="postal-section-heading">
                        <div>
                            <h2>{{ $declaration['nature'] ?? 'Declaración aduanera' }}</h2>
                            <p class="postal-note mb-0">Estado: {{ $declaration['state'] ?? 'No informado' }} · ID CDS {{ $declaration['id'] }}</p>
                        </div>
                        <a class="btn btn-primary" target="_blank" rel="noopener"
                           href="{{ route('postal.cds.declaration.print', ['codigo' => $code, 'declaracion' => $declaration['id']]) }}">
                            <i class="fas fa-print" aria-hidden="true"></i> Imprimir CN23
                        </a>
                    </div>
                </article>
            @endforeach
        </section>
    </div>
@stop
