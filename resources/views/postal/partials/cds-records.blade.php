<section id="customs" class="postal-panel" aria-label="Registros CDS por objeto">
    <div class="postal-section-heading">
        <div><h2><i class="fas fa-clipboard-list"></i> Declaraciones y respuestas de Aduana</h2><span>{{ $cdsPackageGroups->count() }} objeto(s) postal(es) · CDS</span></div>
        <a class="btn btn-outline-primary" href="{{ route('postal.customs.remittance', ['codigos'=>$code]) }}"><i class="fas fa-file-export"></i> Preparar remisión</a>
    </div>
    <p class="postal-note">Cada registro CDS reúne aquí sus declaraciones y respuestas. El estado aduanero no confirma una entrega al destinatario.</p>
    @if($cdsPackageGroups->count() > 1)
        <div class="alert alert-info">Un código S10 puede tener varios objetos CDS de entrada o salida. Los documentos se agrupan por el ID interno del objeto; revisa su flujo antes de utilizarlos.</div>
    @endif
    @if($cds['truncated'] ?? false)
        <div class="alert alert-warning">El resultado supera el límite de consulta. Se muestra una parte de los registros.</div>
    @endif

    @forelse($cdsPackageGroups as $group)
        @php($package = $group['package'])
        <article class="postal-cds-record">
            <div class="postal-section-heading">
                <div><h3>{{ $package->MAIL_OBJECT_ID ?? $package->MAIL_OBJECT_LOCAL_ID ?? $package->MAIL_OBJECT_LOCAL_ID2 ?? 'Objeto CDS '.($package->MAIL_OBJECT_PID ?? 'sin ID') }}</h3><span>ID objeto {{ $package->MAIL_OBJECT_PID ?? 'No informado' }}</span></div>
                <span class="postal-source">{{ $package->MAIL_FLOW_CD ?? 'Flujo no informado' }} · {{ $package->MAIL_STATE_NM ?? 'Estado no informado' }}</span>
            </div>
            <div class="postal-remittance-meta">
                <span><strong>Identificador local:</strong> {{ (($package->MAIL_OBJECT_LOCAL_ID ?? '') ?: ($package->MAIL_OBJECT_LOCAL_ID2 ?? '')) ?: 'No informado' }}</span>
                <span><strong>Clase / tipo:</strong> {{ $package->MAIL_CLASS_CD ?? '—' }} / {{ $package->MAIL_OBJECT_TYPE_CD ?? '—' }}</span>
                <span><strong>Fecha postal CDS:</strong> {{ $package->POSTING_DATE_LOCAL_DISPLAY ?? 'No informada' }}</span>
            </div>
            @forelse($group['declarations'] as $declaration)
                @include('postal.declaration', ['declaration'=>$declaration, 'cn23Code'=>$code])
            @empty
                <p class="postal-note">{{ ($cds['truncated'] ?? false) ? 'No se recibió una declaración de este objeto en la parte consultada. No se puede confirmar que falte.' : 'No hay una declaración vinculada a este objeto en los resultados consultados.' }}</p>
            @endforelse
            @foreach($group['responses'] as $response)
                @include('postal.partials.customs-response', ['response'=>$response])
            @endforeach
        </article>
    @empty
        <p class="postal-note">
            @if(($sources['CDS'] ?? null) === 'empty')
                No se encontró un objeto postal para este identificador en CDS. La ausencia del objeto no permite confirmar si el sistema tiene una declaración asociada.
            @elseif(($sources['CDS'] ?? null) === 'unavailable')
                No se pudo consultar CDS; vuelve a intentarlo. No se puede concluir que falte la declaración.
            @else
                No hay declaraciones disponibles en esta consulta. Revisa el estado de la conexión CDS.
            @endif
        </p>
    @endforelse

    <details id="responsibles" class="postal-subsection">
        <summary><i class="fas fa-user-check"></i> Eventos de Aduana <small>· quién, cuándo y dónde registró cada cambio</small></summary>
        <div class="table-responsive"><table class="table">
            <thead><tr><th>Fecha y hora</th><th>Acción registrada</th><th>Responsable</th><th>Oficina</th><th>Documento vinculado</th></tr></thead>
            <tbody>
                @forelse(collect($cds['events'] ?? [])->sortByDesc('occurred_at') as $event)
                    <tr><td>{{ $event['occurred_at_local_display'] ?? 'Fecha no informada' }}</td><td>{{ $event['name'] ?? $event['code'] }}</td><td>{{ $event['user_name'] ?? $event['user_code'] ?? 'No informado' }}</td><td>{{ $event['office'] ?? 'No informada' }}</td><td><small>{{ $event['kind'] === 'declarations' ? 'Declaración' : 'Respuesta' }} {{ $event['record_id'] }}</small></td></tr>
                @empty
                    <tr><td colspan="5">Sin eventos CDS disponibles.</td></tr>
                @endforelse
            </tbody>
        </table></div>
    </details>

    @if($unassignedDeclarations->isNotEmpty() || $unassignedResponses->isNotEmpty())
        <div class="postal-cds-record">
            <h3>Otros documentos sin un objeto coincidente</h3>
            <p class="postal-note">Se conserva el ID de origen. Estos documentos no se atribuyen a otro objeto CDS.</p>
            @foreach($unassignedDeclarations as $declaration)
                @include('postal.declaration', ['declaration'=>$declaration, 'cn23Code'=>$code])
            @endforeach
            @foreach($unassignedResponses as $response)
                @include('postal.partials.customs-response', ['response'=>$response])
            @endforeach
        </div>
    @endif
</section>
