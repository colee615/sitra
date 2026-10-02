<div class="postal-workspace">
    <section class="postal-hero">
        <div><span class="postal-eyebrow">CENTRO DE TRABAJO POSTAL</span><h2>La información del envío,<br>en un solo expediente.</h2><p>Consulta el recorrido en IPS y la declaración en CDS.<br>Elige una tarea para trabajar con despachos, sacas o Aduana.</p></div>
        <i class="fas fa-box-open postal-hero-icon" aria-hidden="true"></i>
    </section>

    @can('postal.access')
        <form class="postal-search" method="get" action="{{ route('postal.combined') }}">
            <label for="home-code">Buscar un envío · expediente IPS + CDS</label>
            <div class="postal-search-row"><i class="fas fa-barcode" aria-hidden="true"></i><input id="home-code" name="codigo" maxlength="35" placeholder="Código S10 o identificador local" required><button class="btn btn-primary">Abrir expediente <i class="fas fa-arrow-right"></i></button></div>
        </form>
    @endcan

    @can('postal.ips')
        <section class="postal-home-group" aria-labelledby="home-logistics">
            <div class="postal-section-heading"><div><h2 id="home-logistics"><i class="fas fa-boxes"></i> Envíos y logística</h2><p>Un despacho agrupa sacas; cada saca relaciona sus paquetes.</p></div></div>
            <div class="postal-task-grid">
                <a class="postal-task" href="{{ route('postal.dispatches') }}"><span class="postal-task-icon"><i class="fas fa-plane-departure"></i></span><h3>1. Despachos (S8)</h3><p>Lista y busca despachos. Compara las sacas registradas con las vinculadas en IPS.</p><strong>Ver despachos <i class="fas fa-arrow-right"></i></strong></a>
                <a class="postal-task" href="{{ route('postal.receptacles') }}"><span class="postal-task-icon gold"><i class="fas fa-barcode"></i></span><h3>2. Sacas y marbetes (S9)</h3><p>Busca un S9, marbete, registro o seguro y abre los paquetes asociados a la saca.</p><strong>Buscar saca <i class="fas fa-arrow-right"></i></strong></a>
                <a class="postal-task" href="{{ route('postal.ips') }}"><span class="postal-task-icon"><i class="fas fa-globe-americas"></i></span><h3>3. Paquetes IPS</h3><p>Consulta movimientos, entregas, operadores, mensajes y documentos del paquete.</p><strong>Buscar paquete <i class="fas fa-arrow-right"></i></strong></a>
            </div>
        </section>
    @endcan

    @can('postal.cds')
        <section class="postal-home-group" aria-labelledby="home-customs">
            <div class="postal-section-heading"><div><h2 id="home-customs"><i class="fas fa-clipboard-list"></i> Gestión aduanera</h2><p>Revisa una declaración o reúne las de varios paquetes para preparar la remisión.</p></div></div>
            <div class="postal-task-grid">
                <a class="postal-task" href="{{ route('postal.cds') }}"><span class="postal-task-icon teal"><i class="fas fa-clipboard-list"></i></span><h3>Declaraciones CDS</h3><p>Consulta artículos, decisiones y responsables de cada registro. Imprime la reconstrucción CN23.</p><strong>Buscar declaración <i class="fas fa-arrow-right"></i></strong></a>
                <a class="postal-task" href="{{ route('postal.customs.remittance') }}"><span class="postal-task-icon gold"><i class="fas fa-file-export"></i></span><h3>Remisión a Aduana</h3><p>Busca por saca o por códigos de paquetes, revisa las declaraciones disponibles y prepara el listado.</p><strong>Preparar listado <i class="fas fa-arrow-right"></i></strong></a>
            </div>
        </section>
    @endcan

    @can('postal.ips')
        <section class="postal-home-group" aria-labelledby="home-office">
            <div class="postal-section-heading"><div><h2 id="home-office"><i class="fas fa-building"></i> Operación en oficina</h2><p>Consulta la actividad del periodo o registra un movimiento con los permisos de tu cuenta.</p></div></div>
            <div class="postal-task-grid">
                <a class="postal-task" href="{{ route('postal.operations') }}"><span class="postal-task-icon teal"><i class="fas fa-clipboard-check"></i></span><h3>Actividad por oficina</h3><p>Filtra movimientos por fecha, oficina, evento o paquete y descarga el reporte CSV.</p><strong>Abrir reporte <i class="fas fa-arrow-right"></i></strong></a>
                @can('ips.read')
                    <a class="postal-task" href="{{ route('operaciones.index') }}"><span class="postal-task-icon gold"><i class="fas fa-truck"></i></span><h3>Movimientos y entregas</h3><p>Encuentra paquetes y gestiona sus movimientos o la entrega según tus permisos.</p><strong>Abrir operaciones <i class="fas fa-arrow-right"></i></strong></a>
                @endcan
            </div>
        </section>
    @endcan

    @can('admin-only')
        <div class="postal-home-admin"><span><i class="fas fa-user-shield"></i> Accesos del equipo · controla quién puede consultar y registrar operaciones.</span><a class="btn btn-outline-primary" href="{{ route('postal.access.index') }}">Administrar permisos <i class="fas fa-arrow-right"></i></a></div>
    @endcan
</div>
