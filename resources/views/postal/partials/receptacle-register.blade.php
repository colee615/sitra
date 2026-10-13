<form class="postal-search postal-dispatch-filters" action="{{ route('postal.receptacles') }}" method="get">
    <div class="postal-dispatch-filter-grid">
        <div class="postal-dispatch-filter-wide">
            <label for="receptacle-search">Buscar marbete, saca, despacho, registro o seguro</label>
            <div class="postal-dispatch-input"><i class="fas fa-search" aria-hidden="true"></i><input id="receptacle-search" name="buscar" value="{{ $filters['buscar'] }}" maxlength="80" placeholder="Código S9 o S8, número de registro o seguro"></div>
        </div>
        <div><label for="receptacle-from">Fecha desde</label><input id="receptacle-from" type="date" name="desde" value="{{ $filters['desde'] }}"></div>
        <div><label for="receptacle-to">Fecha hasta</label><input id="receptacle-to" type="date" name="hasta" value="{{ $filters['hasta'] }}"></div>
        <div><label for="receptacle-page-size">Filas por página</label><select id="receptacle-page-size" name="por_pagina"><option value="25" @selected($filters['por_pagina'] === 25)>25</option><option value="50" @selected($filters['por_pagina'] === 50)>50</option><option value="100" @selected($filters['por_pagina'] === 100)>100</option></select></div>
    </div>
    <div class="postal-dispatch-filter-actions">
        <div><a class="btn btn-light" href="{{ route('postal.receptacles') }}"><i class="fas fa-undo"></i> Limpiar</a><button class="btn btn-primary"><i class="fas fa-filter"></i> Buscar sacas</button></div>
    </div>
</form>

@if($error)<div class="alert alert-danger" role="alert">{{ $error }}</div>@endif
@if($receptacleRows)
    <section class="postal-dispatch-summary postal-panel">
        <div><span class="postal-task-icon"><i class="fas fa-boxes"></i></span><strong>{{ number_format($receptacleRows->total()) }}</strong><span>sacas y marbetes encontrados</span></div>
        <div><i class="fas fa-sort-amount-down"></i><span>Más recientes primero</span></div>
    </section>

    <section class="postal-panel postal-dispatch-results">
        <div class="postal-section-heading">
            <h2><i class="fas fa-barcode"></i> Marbetes y sacas IPS</h2>
            <span>Mostrando {{ $receptacleRows->firstItem() ?? 0 }}–{{ $receptacleRows->lastItem() ?? 0 }} de {{ number_format($receptacleRows->total()) }}</span>
        </div>
        @if($receptacleRows->count() === 0)
            <div class="postal-empty postal-empty-compact"><i class="fas fa-search"></i><h2>No encontramos sacas</h2><p>Prueba con otro código S9, despacho S8, registro, seguro o fecha.</p></div>
        @else
            <div class="table-responsive">
                <table class="table postal-receptacle-register-table">
                    <thead><tr><th>Fecha</th><th>Marbete / saca S9</th><th>Despacho S8</th><th>Registro y seguro</th><th>Paquetes con evento IPS / cantidad indicada en saca</th><th>Peso</th><th></th></tr></thead>
                    <tbody>
                    @foreach($receptacleRows as $bag)
                        @php($bagIdentifier = $bag->RECPTCL_FID ?: $bag->RECPTCL_REG_NO ?: $bag->RECPTCL_INS_NO)
                        @php($bagCode = \App\Services\Postal\UpuDispatchIdentifier::parse((string) ($bag->RECPTCL_FID ?? '')))
                        @php($linkedPackages = (int) ($bag->LINKED_MAILITMS_NO ?? 0))
                        @php($declaredPackages = is_numeric($bag->RECPTCL_MAILITMS_NO ?? null) ? (int) $bag->RECPTCL_MAILITMS_NO : null)
                        <tr>
                            <td>{{ $bag->EVT_GMT_DT ?: 'Sin fecha' }}</td>
                            <td><strong class="postal-dispatch-code">@if($bagIdentifier)<a href="{{ route('postal.receptacles', ['marbete'=>$bagIdentifier]) }}">{{ $bag->RECPTCL_FID ?: $bagIdentifier }}</a>@else Marbete no informado @endif</strong>@if($bagCode)<small class="d-block text-muted">S9 {{ $bagCode['receptacle_sequence'] }} · {{ $bagCode['encoded_gross_weight_label'] }}</small>@endif</td>
                            <td>@if($bag->DESPTCH_FID)<a href="{{ route('postal.dispatches', ['buscar'=>$bag->DESPTCH_FID]) }}">{{ $bag->DESPTCH_FID }}</a><small class="d-block text-muted">{{ $bag->ORIG_OFFICE_FCD ?: 'Origen no informado' }} → {{ $bag->DEST_OFFICE_FCD ?: 'Destino no informado' }}</small>@else Sin despacho vinculado @endif</td>
                            <td>{{ $bag->RECPTCL_REG_NO ?: 'Sin registro' }}<small class="d-block text-muted">Seguro: {{ $bag->RECPTCL_INS_NO ?: $bag->RECPTCL_SEAL_NUMBER ?: 'No informado' }}</small></td>
                            <td class="postal-dispatch-count"><strong>{{ $linkedPackages }}</strong> paquetes con evento en esta saca<small>{{ $declaredPackages === null ? 'La saca no indica cantidad' : 'La saca indica: '.$declaredPackages.' paquetes' }}</small>@if($declaredPackages === null)<span class="postal-dispatch-status is-neutral">Sin dato de comparación</span>@elseif($declaredPackages === $linkedPackages)<span class="postal-dispatch-status is-ok">Coincide</span>@else<span class="postal-dispatch-status is-warning">Revisar diferencia</span>@endif</td>
                            <td>{{ is_numeric($bag->RECPTCL_WEIGHT ?? null) ? number_format((float)$bag->RECPTCL_WEIGHT,3,',','.').' kg' : '—' }}</td>
                            <td><div class="postal-dispatch-row-actions">
                                @if($bagIdentifier)<a class="btn btn-sm btn-outline-secondary" href="{{ route('postal.receptacles', ['marbete' => $bagIdentifier]) }}"><i class="fas fa-eye"></i> Ver saca</a>
                                <button type="button" class="btn btn-sm btn-outline-primary" data-toggle="modal" data-target="#receptacle-packages-modal" data-url="{{ route('postal.receptacles.packages', ['codigo' => $bagIdentifier]) }}" data-code="{{ $bag->RECPTCL_FID ?: $bagIdentifier }}"><i class="fas fa-boxes"></i> Ver paquetes</button>@endif
                            </div></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="postal-dispatch-pagination">{{ $receptacleRows->links() }}</div>
        @endif
    </section>
@endif

<div class="modal fade" id="receptacle-packages-modal" tabindex="-1" role="dialog" aria-labelledby="receptacle-packages-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="receptacle-packages-modal-title">Paquetes de la saca</h2>
                <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body" id="receptacle-packages-modal-body" aria-live="polite"><p class="postal-note mb-0">Selecciona una saca para consultar sus paquetes.</p></div>
        </div>
    </div>
</div>
