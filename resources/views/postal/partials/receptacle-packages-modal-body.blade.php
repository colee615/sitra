@if($error)
    <div class="alert alert-danger mb-0" role="alert">{{ $error }}</div>
@elseif(!$bag)
    <div class="postal-empty postal-empty-compact mb-0">
        <i class="fas fa-search" aria-hidden="true"></i>
        <h3>{{ count($result['receptacles'] ?? []) > 1 ? 'Hay varias sacas con este identificador' : 'No encontramos la saca en IPS' }}</h3>
        <p>{{ count($result['receptacles'] ?? []) > 1 ? 'Usa el código S9 completo para consultar sus paquetes.' : 'No hay un registro IPS para este identificador.' }}</p>
        <code>{{ $identifier }}</code>
    </div>
@else
    @php($items = collect($result['items'] ?? []))
    <div class="postal-receptacle-packages-summary">
        <div><span class="postal-eyebrow">PAQUETES VINCULADOS EN IPS</span><strong>{{ $bag->RECPTCL_FID ?: $identifier }}</strong></div>
        <span>{{ $items->count() }} {{ $items->count() === 1 ? 'paquete' : 'paquetes' }}</span>
    </div>

    @if($result['truncated'] ?? false)
        <div class="alert alert-warning" role="alert">La consulta tiene más registros que el límite visible. Revisa la ficha de la saca para continuar.</div>
    @endif

    <div class="table-responsive">
        <table class="table postal-receptacle-packages-table">
            <thead><tr><th>Identificador S10</th><th>Clase</th><th>Origen → destino</th><th>Último movimiento</th><th>Fecha IPS</th><th></th></tr></thead>
            <tbody>
            @forelse($items->take(50) as $item)
                <tr>
                    <td><strong class="postal-s10-code">{{ $item->MAILITM_FID ?: $item->MAILITM_LOCAL_ID ?: 'Identificador local' }}</strong>@if(!$item->MAILITM_FID && $item->MAILITM_LOCAL_ID)<small class="d-block text-muted">Identificador local</small>@endif</td>
                    <td>{{ $item->MAIL_CLASS_NAME ?: $item->MAIL_CLASS_CD ?: '—' }}</td>
                    <td>{{ $item->ORIGIN_COUNTRY ?: '—' }} → {{ $item->DEST_COUNTRY ?: '—' }}</td>
                    <td>{{ $item->EVENT_NAME_ES ?: $item->EVENT_NAME ?: 'Sin movimiento descrito' }}</td>
                    <td>{{ $item->EVT_GMT_DT ?: '—' }}</td>
                    <td>@if($item->MAILITM_FID)<a class="btn btn-sm btn-outline-primary" href="{{ route('postal.combined', ['codigo' => $item->MAILITM_FID]) }}"><i class="fas fa-eye"></i> Ver paquete</a>@endif</td>
                </tr>
            @empty
                <tr><td colspan="6">No hay paquetes vinculados en los eventos IPS de esta saca.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($items->count() > 50)<p class="postal-note mb-0">Se muestran los primeros 50 de {{ $items->count() }} paquetes.</p>@endif
@endif
