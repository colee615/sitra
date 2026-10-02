<article class="postal-response">
    <span class="postal-eyebrow">RESPUESTA DE ADUANA</span>
    <h3>{{ ($response['decision'] ?? '') ?: 'Decisión sin descripción en el catálogo' }}</h3>
    <p>{{ $response['data']['fields']['DecisReasNm'] ?? 'Motivo no informado' }}</p>
    <p>Estado del registro: {{ $response['state'] ?? 'No registrado' }}</p>
    <small>Respuesta {{ $response['id'] }} · Paquete interno {{ $response['package_id'] }}</small>
    @if(($response['data']['status'] ?? null) === 'invalid')
        <p class="text-warning">El contenido no pudo interpretarse.</p>
    @endif
</article>
