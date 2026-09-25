<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $kind === 'marbete' ? 'Marbete interno' : 'Resumen aduanero' }} · {{ $code }}</title><link rel="stylesheet" href="{{ asset('css/postal-print.css') }}"></head><body>
<div class="print-toolbar"><button onclick="window.print()">Imprimir / guardar como PDF</button><span>Copia interna de consulta</span></div>
<main><header><strong>SITRA · CORREOS DE BOLIVIA</strong><p>{{ $kind === 'marbete' ? 'MARBETE INTERNO DE IDENTIFICACIÓN' : 'RESUMEN DE DECLARACIONES ADUANERAS' }}</p></header>
@php
    $package = collect($ips['packageRows'] ?? [])->first();
    $cdsPackage = collect($cds['packages'] ?? [])->first();
    $printedCode = trim($package->MAILITM_FID ?? $cdsPackage->MAIL_OBJECT_ID ?? $code);
@endphp
<h1>{{ $printedCode }}</h1>
@if($kind === 'marbete')
    @if(preg_match('/^[A-Z0-9 -]{1,35}$/D', $printedCode))<div class="barcode">{!! \Milon\Barcode\Facades\DNS1DFacade::getBarcodeHTML($printedCode, 'C128', 2, 65) !!}</div>@endif
    <dl><dt>Origen registrado en IPS</dt><dd>{{ $package->ORIG_COUNTRY_NM ?? 'No informado' }}</dd><dt>Destino registrado en IPS</dt><dd>{{ $package->DEST_COUNTRY_NM ?? 'No informado' }}</dd><dt>Peso registrado en IPS</dt><dd>{{ isset($package->MAILITM_WEIGHT) ? $package->MAILITM_WEIGHT.' kg' : 'No informado' }}</dd></dl>
    <p>Identifica un código existente. No acredita admisión, pago, despacho ni entrega.</p>
@else
    @foreach($cds['declarations'] as $declaration) @include('postal.declaration', ['declaration'=>$declaration]) @endforeach
    <p>Resumen de los registros CDS disponibles. No sustituye un formulario oficial CN22 o CN23 ni modifica la declaración original.</p>
@endif
<footer>Generado por {{ auth()->user()->name }} · {{ now(config('postal.timezone'))->format('d/m/Y H:i') }} ({{ config('postal.timezone') }})<br>La persona que genera esta copia no necesariamente es quien registró los datos originales.</footer></main></body></html>
