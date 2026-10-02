<nav class="postal-logistics-flow" aria-label="Cadena logística IPS">
    <a class="postal-flow-step {{ $active === 'dispatch' ? 'is-active' : '' }}" href="{{ $active === 'receptacle' && !empty($dispatchCode) ? route('postal.receptacles',['marbete'=>$dispatchCode]) : route('postal.dispatches') }}" @if($active === 'dispatch') aria-current="step" @endif>
        <span class="postal-flow-number">1</span><span><strong>Despacho <small>S8</small></strong><em>Agrupa sacas</em></span>
    </a>
    <i class="fas fa-chevron-right postal-flow-arrow" aria-hidden="true"></i>
    <a class="postal-flow-step {{ $active === 'receptacle' ? 'is-active' : '' }}" href="{{ $active === 'dispatch' && !empty($dispatchCode) ? '#dispatch-sacks' : route('postal.receptacles') }}" @if($active === 'receptacle') aria-current="step" @endif>
        <span class="postal-flow-number">2</span><span><strong>Saca <small>S9</small></strong><em>Agrupa paquetes</em></span>
    </a>
    <i class="fas fa-chevron-right postal-flow-arrow" aria-hidden="true"></i>
    <a class="postal-flow-step {{ $active === 'package' ? 'is-active' : '' }}" href="{{ $active === 'receptacle' ? '#bag-items' : route('postal.ips') }}" @if($active === 'package') aria-current="step" @endif>
        <span class="postal-flow-number">3</span><span><strong>Paquetes</strong><em>Eventos e historial IPS</em></span>
    </a>
</nav>
