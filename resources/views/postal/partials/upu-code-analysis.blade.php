@php($isS9 = ($analysis['type'] ?? null) === 's9')
<section class="postal-panel" aria-label="Componentes del identificador UPU">
    <div class="postal-section-heading">
        <h2><i class="fas fa-barcode"></i> Identificador UPU {{ $isS9 ? 'S9 · saca' : 'S8 · despacho' }}</h2>
        <span>Desglose del código; no sustituye los datos registrados en IPS</span>
    </div>
    <div class="postal-facts">
        <div><span>CTCI de origen (1–6)</span><strong>{{ $analysis['origin_ctci'] }}</strong></div>
        <div><span>CTCI de destino (7–12)</span><strong>{{ $analysis['destination_ctci'] }}</strong></div>
        <div><span>Categoría de correo (13)</span><strong>{{ $analysis['mail_category'] }}</strong></div>
        <div><span>Clase / subclase (14–15)</span><strong>{{ $analysis['mail_class'] }} / {{ $analysis['mail_subclass'] }}</strong></div>
        <div><span>Último dígito del año (16)</span><strong>{{ $analysis['year_digit'] }}</strong></div>
        <div><span>Número de despacho (17–20)</span><strong>{{ $analysis['dispatch_sequence'] }}</strong></div>
        @if($isS9)
            <div><span>Número de saca (21–23)</span><strong>{{ $analysis['receptacle_sequence'] }}</strong></div>
            <div><span>Saca de número más alto (24)</span><strong>{{ $analysis['highest_receptacle_indicator'] }} · {{ $analysis['highest_receptacle_label'] }}</strong></div>
            <div><span>Certificado / valor declarado (25)</span><strong>{{ $analysis['certified_indicator'] }} · {{ $analysis['certified_label'] }}</strong></div>
            <div><span>Peso bruto codificado (26–29)</span><strong>{{ $analysis['encoded_gross_weight_label'] }}</strong></div>
        @endif
    </div>
    @if($isS9)
        <p class="postal-note mb-0">El S9 incluye el S8 {{ $analysis['dispatch_code'] }}. El peso del código UPU es una referencia; compáralo con el peso que IPS registra para la saca.</p>
    @else
        <p class="postal-note mb-0">El S8 identifica un despacho. IPS puede relacionarlo con varias sacas S9; cada saca conserva su propio peso, indicadores y lista de paquetes.</p>
    @endif
</section>
