@if(!$showIps)
    <div class="alert alert-warning" role="alert">Tu cuenta no tiene acceso al registro de paquetes IPS.</div>
@elseif($packageListError)
    <div class="alert alert-danger" role="alert"><i class="fas fa-exclamation-circle"></i> {{ $packageListError }}</div>
@elseif($packageRowsPage)
    <form class="postal-search postal-dispatch-filters" action="{{ route('postal.combined') }}" method="get">
        <div class="postal-dispatch-filter-grid">
            <div class="postal-dispatch-filter-wide">
                <label for="package-register-search">Buscar paquete S10 o identificador local</label>
                <div class="postal-dispatch-input"><i class="fas fa-search" aria-hidden="true"></i><input id="package-register-search" name="buscar" value="{{ $packageListFilters['buscar'] }}" maxlength="80" placeholder="Código S10 o identificador local"></div>
            </div>
            <div><label for="package-register-from">Último evento desde</label><input id="package-register-from" type="date" name="desde" value="{{ $packageListFilters['desde'] }}"></div>
            <div><label for="package-register-to">Último evento hasta</label><input id="package-register-to" type="date" name="hasta" value="{{ $packageListFilters['hasta'] }}"></div>
            <div><label for="package-register-page-size">Filas por página</label><select id="package-register-page-size" name="por_pagina"><option value="25" @selected($packageListFilters['por_pagina'] === 25)>25</option><option value="50" @selected($packageListFilters['por_pagina'] === 50)>50</option><option value="100" @selected($packageListFilters['por_pagina'] === 100)>100</option></select></div>
        </div>
        <div class="postal-dispatch-filter-actions">
            <div><a class="btn btn-light" href="{{ route('postal.combined') }}"><i class="fas fa-undo"></i> Limpiar</a><button class="btn btn-primary"><i class="fas fa-filter"></i> Buscar paquetes</button></div>
        </div>
    </form>

    <section class="postal-dispatch-summary postal-panel">
        <div><span class="postal-task-icon"><i class="fas fa-box"></i></span><strong>{{ number_format($packageRowsPage->total()) }}</strong><span>paquetes encontrados</span></div>
        <div><i class="fas fa-sort-amount-down"></i><span>Más recientes primero</span></div>
    </section>

    <section class="postal-panel postal-dispatch-results">
        <div class="postal-section-heading">
            <h2><i class="fas fa-boxes"></i> Paquetes IPS</h2>
            <span>Mostrando {{ $packageRowsPage->firstItem() ?? 0 }}–{{ $packageRowsPage->lastItem() ?? 0 }} de {{ number_format($packageRowsPage->total()) }}</span>
        </div>
        @if($packageRowsPage->isEmpty())
            <div class="postal-empty postal-empty-compact"><i class="fas fa-search"></i><h2>No encontramos paquetes</h2><p>Prueba con otro código o ajusta las fechas.</p></div>
        @else
            <div class="table-responsive">
                <table class="table postal-package-register-table">
                    <thead><tr><th>Último evento</th><th>Paquete S10 / ID local</th><th>Movimiento y estado</th><th>Origen → destino</th><th>Saca / despacho</th><th>Peso</th><th></th></tr></thead>
                    <tbody>
                    @foreach($packageRowsPage as $package)
                        @php($packageCode = trim((string) ($package->MAILITM_FID ?: $package->MAILITM_LOCAL_ID)))
                        <tr>
                            <td>{{ $package->EVT_GMT_DT ?: 'Sin fecha' }}</td>
                            <td><strong class="postal-dispatch-code">@if($packageCode)<a href="{{ route('postal.combined', ['codigo' => $packageCode]) }}">{{ $package->MAILITM_FID ?: $packageCode }}</a>@else Sin código @endif</strong>@if($package->MAILITM_LOCAL_ID)<small class="d-block text-muted">ID local: {{ $package->MAILITM_LOCAL_ID }}</small>@endif</td>
                            <td>{{ $package->EVT_TYPE_NM_ES ?: 'Movimiento no informado' }}<small class="d-block text-muted">{{ $package->POSTAL_STATUS_NM ?: 'Estado no informado' }}</small></td>
                            <td>{{ $package->ORIG_COUNTRY_NM ?: $package->ORIG_COUNTRY_CD ?: '—' }} <span class="postal-route-arrow">→</span> {{ $package->DEST_COUNTRY_NM ?: $package->DEST_COUNTRY_CD ?: '—' }}</td>
                            <td>@if($package->RECPTCL_FID)<a href="{{ route('postal.receptacles', ['marbete' => $package->RECPTCL_FID]) }}">{{ $package->RECPTCL_FID }}</a>@else — @endif @if($package->DESPTCH_FID)<small class="d-block"><a href="{{ route('postal.dispatches', ['buscar' => $package->DESPTCH_FID]) }}">{{ $package->DESPTCH_FID }}</a></small>@endif</td>
                            <td>{{ is_numeric($package->MAILITM_WEIGHT ?? null) ? number_format((float) $package->MAILITM_WEIGHT, 3, ',', '.').' kg' : '—' }}</td>
                            <td>@if($packageCode)<a class="btn btn-sm btn-outline-primary" href="{{ route('postal.combined', ['codigo' => $packageCode]) }}"><i class="fas fa-folder-open"></i> Abrir</a>@endif</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="postal-dispatch-pagination">{{ $packageRowsPage->links() }}</div>
        @endif
    </section>
@endif
