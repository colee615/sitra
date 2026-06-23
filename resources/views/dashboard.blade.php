@extends('adminlte::page')

@section('title', 'Centro de Consultas')

@section('content_header')
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center">
        <div>
            <span class="workspace-kicker">
                <i class="fas fa-compass"></i>
                Centro de consultas
            </span>
            <h1 class="workspace-title">Una sola búsqueda para IPS y CDS</h1>
            <p class="workspace-subtitle mb-0">
                Si ambas fuentes se complementan, la búsqueda principal también debe ser única. Desde aquí consultas una
                sola vez y obtienes la lectura operativa y documental juntas.
            </p>
        </div>
    </div>
@stop

@section('content')
    <section class="workspace-hero mb-4">
        <div class="row align-items-center">
            <div class="col-lg-8">
                <span class="workspace-kicker">
                    <i class="fas fa-shield-alt"></i>
                    API externa preservada
                </span>
                <h2 class="workspace-title mb-2">La integración consumida por otros sistemas no se toca</h2>
                <p class="workspace-subtitle mb-0">
                    La ruta <code>/tracking/eventos</code> y su lógica siguen intactas. Esta capa nueva reorganiza solo la
                    experiencia interna de análisis.
                </p>
            </div>
            <div class="col-lg-4 mt-4 mt-lg-0">
                <div class="stat-grid">
                    <div class="stat-card">
                        <span class="stat-card__label">Búsqueda</span>
                        <span class="stat-card__value">Única</span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-card__label">Fuentes</span>
                        <span class="stat-card__value">IPS + CDS</span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-card__label">API</span>
                        <span class="stat-card__value">Intacta</span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-card__label">Modo</span>
                        <span class="stat-card__value">Solo lectura</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="search-panel mb-4">
        <div class="search-panel__top">
            <div>
                <h2 class="search-panel__title">Consulta unificada</h2>
                <p class="search-panel__subtitle">
                    Busca una vez por S10 o código local. El sistema consulta IPS5Db y CDSDb al mismo tiempo y te muestra
                    qué dice cada capa.
                </p>
            </div>
        </div>

        <form method="GET" action="{{ route('consultas.index') }}">
            <div class="input-group input-group-lg">
                <input
                    type="text"
                    name="codigo"
                    class="form-control"
                    placeholder="Ej: RA931985256US o código local"
                    autocomplete="off">
                <div class="input-group-append">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search mr-1"></i> Buscar en IPS y CDS
                    </button>
                </div>
            </div>
        </form>
    </section>

    <div class="two-up">
        <section class="panel-card">
            <h3>Qué aporta IPS</h3>
            <p>
                Muestra la operación real en Bolivia: eventos, oficinas, saca, despacho, entrega y trazas ligadas al
                movimiento del envío.
            </p>
        </section>

        <section class="panel-card">
            <h3>Qué aporta CDS</h3>
            <p>
                Muestra el aviso documental o electrónico: declaración, remitente, destinatario, eventos CDS y exportaciones EDI.
            </p>
        </section>
    </div>

    @include('footer')
@stop
