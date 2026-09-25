<nav class="postal-tabs postal-section-nav" aria-label="Consultas postales">
@can('postal.ips')<a href="{{ route('postal.ips') }}"><i class="fas fa-globe-americas"></i> Paquetes IPS</a><a @if(request()->routeIs('postal.receptacles'))aria-current="page"@endif href="{{ route('postal.receptacles') }}"><i class="fas fa-barcode"></i> Marbetes y sacas</a>@endcan
@can('postal.ips')<a @if(request()->routeIs('postal.operations*'))aria-current="page"@endif href="{{ route('postal.operations') }}"><i class="fas fa-clipboard-check"></i> Actividad por oficina</a>@endcan
@can('postal.cds')<a href="{{ route('postal.cds') }}"><i class="fas fa-clipboard-list"></i> Declaraciones CDS</a>@endcan
@can('postal.access')<a href="{{ route('postal.combined') }}"><i class="fas fa-project-diagram"></i> Búsqueda conjunta</a>@endcan
</nav>
