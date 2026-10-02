<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Relación para Aduana · SITRA</title>
    <link rel="stylesheet" href="{{ asset('css/postal-print.css') }}">
    <style>
        body{font-family:Arial,sans-serif;color:#18334e}main{max-width:1000px}.manifest-heading{border-bottom:2px solid #174977;padding-bottom:14px;margin-bottom:20px}.manifest-heading h1{margin:10px 0}.manifest-summary{display:flex;gap:20px;flex-wrap:wrap;margin:16px 0}.manifest-summary span{padding:8px 12px;background:#f1f5f9;border-radius:6px}.manifest-package{border:1px solid #cbd8e5;border-radius:8px;padding:16px;margin:20px 0;break-inside:avoid}.manifest-package>header{display:flex;justify-content:space-between;gap:12px;align-items:center}.manifest-package h2{margin:4px 0}.manifest-meta{font-size:12px;color:#52677c;line-height:1.7}.manifest-missing{border:1px solid #e5b94b;background:#fff8e6;padding:14px;margin-top:20px}.manifest-warning{background:#fff8e6;padding:10px}@media print{body{margin:0}.manifest-package{break-inside:auto}.postal-declaration{break-inside:avoid}}
    </style>
</head>
<body>
<div class="print-toolbar"><button onclick="window.print()">Imprimir / guardar como PDF</button><span>Relación de trabajo · No es una transmisión electrónica a Aduana</span></div>
<main>
    <div class="manifest-heading"><strong>SITRA · CORREOS DE BOLIVIA</strong><h1>Relación de paquetes y declaraciones CDS</h1><p>Preparada {{ $manifest['generated_at']->format('d/m/Y H:i') }} (hora de Bolivia) por {{ auth()->user()->name }}</p></div>
    <p>@if($marbete)Marbete / saca de origen: <strong>{{ $marbete }}</strong>@else Lote ingresado por códigos @endif</p>
    <div class="manifest-summary"><span>{{ count($manifest['packages']) }} paquete(s) encontrado(s)</span><span>{{ $manifest['declaration_count'] }} declaración(es) activas</span><span>{{ count($manifest['without_declaration_codes'] ?? []) }} con registro y sin declaración</span><span>{{ count($manifest['deleted_declaration_codes'] ?? []) }} con declaración eliminada</span><span>{{ count($manifest['missing_codes']) }} no encontrado(s) en CDS</span><span>{{ count($manifest['unconfirmed_codes'] ?? []) }} no confirmado(s) por límite</span></div>
    <p class="manifest-warning"><strong>Alcance:</strong> copia de consulta con los registros disponibles en CDS. No sustituye el formulario oficial CN22/CN23 ni el formato de presentación que Aduana requiera. Los datos se reproducen sin completar ni modificar.</p>
    @if($manifest['truncated'])<p class="manifest-warning"><strong>Atención:</strong> CDS alcanzó el límite de registros configurado; este resultado puede estar incompleto.</p>@endif

    @foreach($manifest['packages'] as $group)
        <section class="manifest-package">
            <header><div><small>PAQUETE {{ $loop->iteration }}</small><h2>{{ $group['matched_codes'][0] }}</h2></div><strong>{{ $group['status'] }}</strong></header>
            <p class="manifest-meta">Coincidencias: {{ implode(', ', $group['matched_codes']) }} · Se consolidan {{ count($group['records']) }} registro(s) CDS del mismo identificador.</p>
            @foreach($group['records'] as $record)
                @php($package = $record['package'])
                <div class="manifest-meta"><strong>ID de objeto CDS:</strong> {{ $package->MAIL_OBJECT_PID }} · <strong>Código postal:</strong> {{ $package->MAIL_OBJECT_ID ?: 'No informado' }} · <strong>ID local:</strong> {{ $package->MAIL_OBJECT_LOCAL_ID ?: $package->MAIL_OBJECT_LOCAL_ID2 ?: 'No informado' }} · <strong>Estado:</strong> {{ $package->MAIL_STATE_NM ?: 'No informado' }} · <strong>Fecha:</strong> {{ $package->POSTING_DATE ?: 'No informada' }}</div>
                @forelse($record['declarations'] as $declaration)
                    @include('postal.declaration', ['declaration'=>$declaration])
                @empty
                    <p>No se encontró una declaración CDS vinculada a este registro.</p>
                @endforelse
                @if($record['responses'])<p><strong>Respuestas de Aduana de este registro:</strong> @foreach($record['responses'] as $response){{ $response['decision'] ?? 'Respuesta CDS' }} ({{ $response['state'] ?? 'estado no informado' }})@if(!$loop->last); @endif @endforeach</p>@endif
            @endforeach
        </section>
    @endforeach

    @if($manifest['missing_codes'])
        <section class="manifest-missing"><h2>Códigos no encontrados en CDS</h2><p>@foreach($manifest['missing_codes'] as $code)<code>{{ $code }}</code>@if(!$loop->last), @endif @endforeach</p></section>
    @endif
    @if($manifest['unconfirmed_codes'] ?? [])
        <section class="manifest-missing"><h2>Códigos no confirmados por límite de resultados</h2><p>CDS no devolvió suficientes filas para determinar si estos identificadores existen. Vuelve a consultar un lote menor.</p><p>@foreach($manifest['unconfirmed_codes'] as $code)<code>{{ $code }}</code>@if(!$loop->last), @endif @endforeach</p></section>
    @endif
    <footer>Generado por SITRA · Este reporte no registra una presentación ni recepción oficial por parte de Aduana.</footer>
</main>
</body>
</html>
