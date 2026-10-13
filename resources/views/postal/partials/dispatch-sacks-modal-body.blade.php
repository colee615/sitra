@if($error)
    <div class="alert alert-danger mb-0" role="alert">{{ $error }}</div>
@elseif(!$result || count($result['receptacles'] ?? []) === 0)
    <div class="postal-empty postal-empty-compact"><i class="fas fa-inbox"></i><h3>No hay sacas vinculadas</h3><p>IPS no encontró sacas S9 asociadas a este despacho.</p></div>
@else
    @if($result['truncated'] ?? false)
        <div class="alert alert-warning" role="alert">Hay más resultados que los mostrados. Consulta el despacho completo para revisar el registro.</div>
    @endif
    <div class="table-responsive">
        <table class="table postal-dispatch-modal-table">
            <thead><tr><th>Saca S9 / marbete</th><th>Registro y seguro</th><th>Peso IPS</th><th>Paquetes con evento IPS / cantidad indicada en saca</th><th></th></tr></thead>
            <tbody>
            @foreach($result['receptacles'] as $bag)
                <tr>
                    <td><strong>{{ $bag->RECPTCL_FID ?: 'Código no informado' }}</strong></td>
                    <td>{{ $bag->RECPTCL_REG_NO ?: 'Sin registro' }}<small class="d-block text-muted">Seguro: {{ $bag->RECPTCL_INS_NO ?: $bag->RECPTCL_SEAL_NUMBER ?: 'No informado' }}</small></td>
                    <td>{{ is_numeric($bag->RECPTCL_WEIGHT ?? null) ? number_format((float) $bag->RECPTCL_WEIGHT, 3, ',', '.').' kg' : '—' }}</td>
                    <td class="postal-dispatch-count">
                        <strong>{{ $bag->LINKED_MAILITMS_NO ?? 0 }}</strong> paquetes con evento en esta saca
                        <small>{{ is_numeric($bag->RECPTCL_MAILITMS_NO ?? null) ? 'La saca indica: '.$bag->RECPTCL_MAILITMS_NO.' paquetes' : 'La saca no indica cantidad' }}</small>
                        @if(!is_numeric($bag->RECPTCL_MAILITMS_NO ?? null))
                            <span class="postal-dispatch-status is-neutral">Sin dato de comparación</span>
                        @elseif((int) $bag->RECPTCL_MAILITMS_NO === (int) ($bag->LINKED_MAILITMS_NO ?? 0))
                            <span class="postal-dispatch-status is-ok">Coincide</span>
                        @else
                            <span class="postal-dispatch-status is-warning">Revisar diferencia</span>
                        @endif
                    </td>
                    <td>@if($bag->RECPTCL_FID)<a class="btn btn-sm btn-outline-primary" href="{{ route('postal.receptacles', ['marbete' => $bag->RECPTCL_FID]) }}"><i class="fas fa-folder-open"></i> Abrir</a>@endif</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif
