(() => {
    'use strict';
    const root = document.getElementById('operational-dashboard');
    if (!root) return;
    const $ = id => document.getElementById(id);
    const form = $('dashboard-filters');
    const number = (value, digits = 0) => new Intl.NumberFormat('es-BO', { maximumFractionDigits: digits }).format(Number(value) || 0);
    const escape = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const states = { delivered: 'Entregado', transit: 'En tránsito / reparto', customs: 'En aduana', observed: 'Retenido / intento fallido', pending: 'En oficina / pendiente', closed: 'Operación cerrada', other: 'Otros movimientos' };
    const today = root.dataset.today;
    const scope = root.dataset.scope || 'all';
    const initialQuery = new URLSearchParams(location.search);
    let result = null, controller = null, requestId = 0;
    const text = (id, value) => { if ($(id)) $(id).textContent = value; };
    const dateLabel = date => new Intl.DateTimeFormat('es-BO', { day: '2-digit', month: 'short', timeZone: 'UTC' }).format(new Date(date + 'T12:00:00Z'));
    const localDate = date => new Intl.DateTimeFormat('es-BO', { dateStyle: 'short', timeStyle: 'short', timeZone: 'America/La_Paz' }).format(new Date(date.replace(' ', 'T') + 'Z'));
    const empty = 'Sin actividad para los filtros seleccionados.';

    function fillCatalog(catalog) {
        form.querySelectorAll('[data-catalog]').forEach(select => {
            const rows = catalog[select.dataset.catalog];
            if (!rows) return;
            const selected = select.value;
            const placeholder = select.options[0].text;
            select.replaceChildren(new Option(placeholder, ''));
            rows.forEach(row => select.add(new Option(`${String(row.name).trim()}${select.name === 'service' ? ` · ${row.code}` : ''}`, String(row.code).trim())));
            if (selected && !Array.from(select.options).some(option => option.value === selected)) select.add(new Option(selected, selected));
            select.value = selected;
        });
    }

    function fillCdsServices(rows) {
        const select = $('cds-service');
        if (!select) return;
        const selected = select.value;
        const placeholder = select.options[0]?.text || 'Todos los servicios';
        select.replaceChildren(new Option(placeholder, ''));
        rows.forEach(row => {
            if (row.code == null || String(row.code).trim() === '') return;
            const code = String(row.code).trim();
            select.add(new Option(code, code));
        });
        if (selected && !Array.from(select.options).some(option => option.value === selected)) select.add(new Option(selected, selected));
        select.value = selected;
    }

    function ranking(id, rows, filter = null) {
        const container = $(id); if (!container) return;
        const total = rows.reduce((sum, row) => sum + Number(row.total), 0);
        if (!total) { container.innerHTML = `<p class="dashboard-empty">${empty}</p>`; return; }
        container.innerHTML = rows.filter(row => Number(row.total) > 0).map(row => {
            const percent = Number(row.total) / total * 100;
            const label = row.name || row.code || 'Sin dato registrado';
            const actionable = filter && row.code != null && row.code !== '';
            const tag = actionable ? 'button' : 'div';
            return `<${tag} class="dashboard-ranking" ${actionable ? `type="button" data-filter="${filter}" data-value="${escape(row.code)}" title="Filtrar: ${escape(label)}"` : ''}><span><span>${escape(label)}</span><strong>${number(row.total)} <small>${number(percent, 1)}%</small></strong></span><span class="dashboard-bar"><i style="width:${percent.toFixed(2)}%"></i></span></${tag}>`;
        }).join('');
    }

    function trend() {
        if (!result || result.ips.status !== 'ok' || !$('trend-chart')) return;
        const group = $('trend-group').value;
        const buckets = new Map();
        result.ips.timeline.forEach(row => {
            let key = row.date;
            if (group === 'week') { const d = new Date(key + 'T12:00:00Z'); d.setUTCDate(d.getUTCDate() - (d.getUTCDay() + 6) % 7); key = d.toISOString().slice(0, 10); }
            if (group === 'month') key = key.slice(0, 7) + '-01';
            if (!buckets.has(key)) buckets.set(key, { date: key, entries: 0, exits: 0 });
            const bucket = buckets.get(key); bucket.entries += Number(row.entries); bucket.exits += Number(row.exits);
        });
        const rows = [...buckets.values()];
        const max = Math.max(1, ...rows.flatMap(row => [row.entries, row.exits]));
        const w = 640, h = 215, left = 42, right = 12, top = 16, bottom = 29;
        const x = i => left + (rows.length === 1 ? .5 : i / (rows.length - 1)) * (w - left - right);
        const y = n => h - bottom - n / max * (h - top - bottom);
        let svg = `<svg viewBox="0 0 ${w} ${h}" role="img" aria-label="Movimientos de entrada y salida por ${group === 'day' ? 'día' : group === 'week' ? 'semana' : 'mes'}. Detalle numérico disponible en la vista operativa.">`;
        for (let i = 0; i < 5; i++) { const n = max * i / 4; svg += `<line x1="${left}" y1="${y(n)}" x2="${w - right}" y2="${y(n)}" stroke="#edf0e5" stroke-dasharray="3 4"/><text x="${left - 10}" y="${y(n) + 3}" text-anchor="end">${number(n)}</text>`; }
        const area = rows.map((row, i) => `${x(i)},${y(row.entries)}`).join(' ');
        svg += `<polygon points="${x(0)},${h - bottom} ${area} ${x(rows.length - 1)},${h - bottom}" fill="#fff6d5"/>`;
        for (const [key, color] of [['entries', '#dfb425'], ['exits', '#667650']]) {
            svg += `<polyline points="${rows.map((row, i) => `${x(i)},${y(row[key])}`).join(' ')}" fill="none" stroke="${color}" stroke-width="2.4" stroke-linejoin="round"/>`;
            rows.forEach((row, i) => {
                const label = `${group === 'week' ? 'Semana del ' : ''}${dateLabel(row.date)} · Entradas: ${number(row.entries)} · Salidas: ${number(row.exits)}`;
                svg += `<circle class="dashboard-plot-point" tabindex="0" role="img" aria-label="${escape(label)}" data-tooltip="${escape(label)}" cx="${x(i)}" cy="${y(row[key])}" r="${rows.length > 40 ? 2 : 3.6}" fill="${color}" stroke="white" stroke-width="1.5"><title>${escape(label)}</title></circle>`;
            });
        }
        const step = Math.max(1, Math.ceil(rows.length / 6));
        rows.forEach((row, i) => { if (i % step === 0 || i === rows.length - 1 && rows.length % step > 1) svg += `<text x="${x(i)}" y="${h - 5}" text-anchor="middle">${escape(dateLabel(row.date))}</text>`; });
        svg += '</svg><div class="dashboard-tooltip" role="status">Pasa sobre un punto o selecciónalo con el teclado.</div>';
        $('trend-chart').innerHTML = svg;
        $('trend-chart').querySelectorAll('[data-tooltip]').forEach(point => {
            const show = () => { $('trend-chart').querySelector('.dashboard-tooltip').textContent = point.dataset.tooltip; };
            point.addEventListener('focus', show); point.addEventListener('mouseenter', show); point.addEventListener('click', show);
        });
        text('trend-total', `${number(result.ips.totals.entries)} entradas · ${number(result.ips.totals.exits)} salidas`);
    }

    function cdsTrend() {
        if (!result || result.cds.status !== 'ok' || !$('cds-trend-chart')) return;
        const group = $('cds-trend-group').value;
        const axisDate = date => group === 'month'
            ? new Intl.DateTimeFormat('es-BO', { month: 'short', year: '2-digit', timeZone: 'UTC' }).format(new Date(date + 'T12:00:00Z'))
            : dateLabel(date);
        const buckets = new Map();
        (result.cds.timeline || []).forEach(row => {
            const key = group === 'month' ? row.date.slice(0, 7) + '-01' : row.date;
            if (!buckets.has(key)) buckets.set(key, { date: key, objects: 0, declarations: 0, responses: 0 });
            const bucket = buckets.get(key);
            for (const metric of ['objects', 'declarations', 'responses']) bucket[metric] += Number(row[metric]);
        });
        const rows = [...buckets.values()];
        const max = Math.max(1, ...rows.flatMap(row => [row.objects, row.declarations, row.responses]));
        const w = 640, h = 230, left = 46, right = 14, top = 18, bottom = 34;
        const x = i => left + (rows.length === 1 ? .5 : i / (rows.length - 1)) * (w - left - right);
        const y = n => h - bottom - n / max * (h - top - bottom);
        let svg = `<svg viewBox="0 0 ${w} ${h}" role="img" aria-label="Objetos, declaraciones y respuestas CDS por ${group === 'day' ? 'día' : 'mes'} de fecha postal.">`;
        for (let i = 0; i < 5; i++) { const n = max * i / 4; svg += `<line x1="${left}" y1="${y(n)}" x2="${w - right}" y2="${y(n)}" stroke="#e8edf2" stroke-dasharray="3 4"/><text x="${left - 10}" y="${y(n) + 3}" text-anchor="end">${number(n)}</text>`; }
        for (const [key, color] of [['objects', '#0a3766'], ['declarations', '#dfb425'], ['responses', '#16878f']]) {
            svg += `<polyline points="${rows.map((row, i) => `${x(i)},${y(row[key])}`).join(' ')}" fill="none" stroke="${color}" stroke-width="2.7" stroke-linecap="round" stroke-linejoin="round"/>`;
            rows.forEach((row, i) => {
                const label = `${dateLabel(row.date)} · Objetos postales: ${number(row.objects)} · Declaraciones: ${number(row.declarations)} · Respuestas asociadas: ${number(row.responses)}`;
                svg += `<circle class="dashboard-plot-point" tabindex="0" role="img" aria-label="${escape(label)}" data-tooltip="${escape(label)}" cx="${x(i)}" cy="${y(row[key])}" r="${rows.length > 40 ? 2.2 : 3.8}" fill="${color}" stroke="white" stroke-width="1.5"><title>${escape(label)}</title></circle>`;
            });
        }
        const step = Math.max(1, Math.ceil(rows.length / 7));
        rows.forEach((row, i) => { if (i % step === 0 || i === rows.length - 1) svg += `<text x="${x(i)}" y="${h - 8}" text-anchor="middle">${escape(axisDate(row.date))}</text>`; });
        svg += '</svg><div class="dashboard-tooltip" role="status">Pasa sobre un punto para ver el detalle del periodo.</div>';
        $('cds-trend-chart').innerHTML = svg;
        $('cds-trend-chart').querySelectorAll('[data-tooltip]').forEach(point => {
            const show = () => { $('cds-trend-chart').querySelector('.dashboard-tooltip').textContent = point.dataset.tooltip; };
            point.addEventListener('focus', show); point.addEventListener('mouseenter', show); point.addEventListener('click', show);
        });
    }

    function table(id, rows, columns) {
        if (!$(id)) return;
        $(id).innerHTML = rows.length ? rows.map(row => `<tr>${columns.map(column => `<td>${column(row)}</td>`).join('')}</tr>`).join('') : `<tr><td colspan="${columns.length}">${empty}</td></tr>`;
    }

    function render(data) {
        const ips = data.ips;
        fillCatalog(data.catalog);
        if ($('ips-content')) {
            $('ips-content').hidden = ips.status !== 'ok'; $('ips-unavailable').hidden = ips.status === 'ok';
            text('ips-unavailable', ips.message || 'Los indicadores IPS no están disponibles.');
        }
        if (ips.status === 'ok') {
            root.querySelectorAll('[data-metric]').forEach(node => node.textContent = number(ips.totals[node.dataset.metric]));
            root.querySelectorAll('[data-change]').forEach(node => {
                const key = node.dataset.change, before = ips.previous[key], current = ips.totals[key];
                const change = before ? (current - before) / before * 100 : null;
                node.innerHTML = change === null ? 'Sin base de comparación' : `<span class="delta">${change >= 0 ? '↗ +' : '↘ '}${number(change, 1)}%</span> vs. periodo anterior`;
                node.title = `${ips.previousFilters.from} — ${ips.previousFilters.to}: ${number(before)} envíos`;
            });
            root.querySelectorAll('[data-stage]').forEach(node => node.textContent = number(ips.states[node.dataset.stage]));
            text('weight-total', Number(ips.weight.known) > 0 ? `${number(ips.weight.total, 1)} kg` : 'Sin peso');
            text('weight-coverage', `${number(ips.weight.known)} de ${number(ips.totals.packages)} envíos con peso · promedio ${number(ips.weight.average, 2)} kg`);
            text('returns-total', number(ips.returns));
            text('inventory-total', `${number(ips.inventory)} envíos registrados en IPS en todo el histórico. Esta cifra no cambia con los filtros.`);
            ranking('state-chart', Object.entries(states).map(([code, name]) => ({ code, name, total: Number(ips.states[code] || 0) })).sort((a, b) => b.total - a.total), 'state');
            ranking('service-chart', ips.services, 'service'); ranking('office-chart', ips.offices, 'office');
            ranking('type-chart', ips.types.map(row => ({ ...row, code: { Nacional: 'national', Internacional: 'international' }[row.name] || 'unknown' })), 'type');
            ranking('origin-chart', ips.geography.origin, 'origin'); ranking('destination-chart', ips.geography.destination, 'destination'); ranking('event-chart', ips.frequent);
            table('service-flow', ips.serviceFlow, [row => escape(row.name || row.code || 'Sin clase'), ...['packages', 'entries', 'exits', 'movements'].map(key => row => number(row[key]))]);
            table('daily-table', ips.timeline, [row => escape(row.date), ...['packages', 'entries', 'exits', 'deliveries', 'movements'].map(key => row => number(row[key]))]);
            table('recent-table', ips.recent, [row => `<a href="${escape(root.dataset.packageUrl)}?codigo=${encodeURIComponent(row.code.trim())}">${escape(row.code)}</a>`, row => escape(row.service || 'Sin dato'), row => `<span class="badge badge-${row.stage === 'delivered' ? 'success' : row.stage === 'observed' ? 'warning' : 'light'}">${escape(states[row.stage])}</span>`, row => escape(localDate(row.date))]);
            trend();
        }
        const cds = data.cds;
        fillCdsServices(cds.services || []);
        if ($('cds-metrics')) {
            $('cds-metrics').hidden = cds.status !== 'ok'; $('cds-states').hidden = cds.status !== 'ok';
            ['cds-chart-grid', 'cds-breakdowns', 'cds-daily-panel'].forEach(id => { if ($(id)) $(id).hidden = cds.status !== 'ok'; });
            text('cds-status', cds.status === 'ok' ? `CDS consultado · ${new Date(cds.generated_at).toLocaleTimeString('es-BO', { timeZone: 'America/La_Paz' })}` : cds.message || (cds.status === 'disabled' ? 'La conexión CDS está deshabilitada en la configuración.' : 'CDS no disponible.'));
            if (cds.status === 'ok') {
                root.querySelectorAll('[data-cds]').forEach(node => node.textContent = number(cds[node.dataset.cds]));
                ranking('cds-states', cds.states);
                ranking('cds-services', cds.services || []);
                ranking('cds-origins', cds.origins || []);
                ranking('cds-destinations', cds.destinations || []);
                cdsTrend();
                table('cds-daily-table', cds.timeline || [], [row => escape(row.date), ...['objects', 'declarations', 'responses'].map(key => row => number(row[key]))]);
            }
        }
        const ready = [['IPS', ips], ['CDS', cds]].filter(([, source]) => source.status === 'ok');
        const timestamp = ready.length ? new Date(ready[0][1].generated_at).toLocaleTimeString('es-BO', { hour: '2-digit', minute: '2-digit', timeZone: 'America/La_Paz' }) : '';
        text('dashboard-status', ready.length ? `${ready.map(([name]) => name).join(' + ')} · Datos consultados a las ${timestamp} · Bolivia` : 'No hay fuentes disponibles para estos filtros.');
        text('filter-summary', `${data.filters.from} — ${data.filters.to} · ${scope === 'cds' ? 'Fecha postal CDS · zona horaria no informada' : 'Hora de Bolivia'}`);
        $('dashboard-export').disabled = !ready.length;
    }

    async function load() {
        if (!form.reportValidity()) return;
        controller?.abort(); controller = new AbortController(); const currentId = ++requestId;
        $('dashboard-results').setAttribute('aria-busy', 'true'); $('dashboard-error').hidden = true; $('dashboard-export').disabled = true;
        text('dashboard-status', scope === 'cds' ? 'Consultando los indicadores de CDS…' : scope === 'ips' ? 'Consultando los indicadores de IPS…' : 'Consultando los indicadores de IPS y CDS…');
        const params = new URLSearchParams();
        params.set('scope', scope);
        new FormData(form).forEach((value, key) => { if (value) params.set(key, value); });
        try {
            const response = await fetch(`${root.dataset.url}?${params}`, { headers: { Accept: 'application/json' }, signal: controller.signal, credentials: 'same-origin' });
            const data = await response.json();
            if (!response.ok) throw new Error(response.status === 422 ? Object.values(data.errors || {}).flat().join(' ') : response.status === 401 || response.status === 403 ? 'Tu sesión o tus permisos han cambiado. Vuelve a iniciar sesión.' : 'No se pudo cargar el dashboard. Intenta nuevamente.');
            if (currentId !== requestId) return;
            result = data; render(data);
            history.replaceState(null, '', `${location.pathname}?${params}`);
        } catch (error) {
            if (error.name === 'AbortError') return;
            result = null;
            $('dashboard-error').hidden = false;
            text('dashboard-error', error.message.includes('JSON') || error instanceof TypeError ? 'No se pudo completar la consulta. Revisa la conexión e intenta nuevamente.' : error.message);
            text('dashboard-status', 'Consulta no completada. Las cifras anteriores no corresponden a los filtros actuales.');
        } finally {
            if (currentId === requestId) $('dashboard-results').setAttribute('aria-busy', result ? 'false' : 'true');
        }
    }

    function preset(period) {
        let from = today;
        if (period === 'month') from = today.slice(0, 7) + '-01';
        if (period === 'year') from = today.slice(0, 4) + '-01-01';
        if (period === 'week') { const date = new Date(today + 'T12:00:00Z'); date.setUTCDate(date.getUTCDate() - (date.getUTCDay() + 6) % 7); from = date.toISOString().slice(0, 10); }
        form.elements.from.value = from; form.elements.to.value = today;
        root.querySelectorAll('[data-period]').forEach(button => { const active = button.dataset.period === period; button.classList.toggle('is-active', active); button.setAttribute('aria-pressed', String(active)); });
    }
    const tabs = [...root.querySelectorAll('[data-view]')];
    function changeView(button) {
        tabs.forEach(tab => {
            const active = tab === button; tab.classList.toggle('is-active', active); tab.setAttribute('aria-selected', String(active)); tab.tabIndex = active ? 0 : -1;
            if ($(tab.dataset.view + '-view')) $(tab.dataset.view + '-view').hidden = !active;
        });
    }
    tabs.forEach((button, index) => {
        button.addEventListener('click', () => changeView(button));
        button.addEventListener('keydown', event => {
            if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            const next = event.key === 'Home' ? tabs[0] : event.key === 'End' ? tabs.at(-1) : tabs[(index + (event.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length];
            changeView(next); next.focus();
        });
    });
    form.addEventListener('submit', event => { event.preventDefault(); load(); });
    root.querySelectorAll('[data-period]').forEach(button => button.addEventListener('click', () => { preset(button.dataset.period); load(); }));
    form.addEventListener('change', event => {
        if (['from', 'to'].includes(event.target.name)) root.querySelectorAll('[data-period]').forEach(button => { button.classList.remove('is-active'); button.setAttribute('aria-pressed', 'false'); });
    });
    $('filters-reset').addEventListener('click', () => { form.reset(); preset(scope === 'cds' ? 'year' : 'month'); form.querySelectorAll('select').forEach(select => select.value = ''); load(); });
    $('dashboard-refresh').addEventListener('click', load);
    $('trend-group')?.addEventListener('change', trend);
    $('cds-trend-group')?.addEventListener('change', cdsTrend);
    $('advanced-toggle')?.addEventListener('click', () => { const panel = $('advanced-filters'); panel.hidden = !panel.hidden; $('advanced-toggle').setAttribute('aria-expanded', String(!panel.hidden)); });
    root.addEventListener('click', event => {
        const button = event.target.closest('[data-filter]'); if (!button) return;
        form.elements[button.dataset.filter].value = button.dataset.value;
        if (['state', 'origin', 'destination', 'type'].includes(button.dataset.filter)) { $('advanced-filters').hidden = false; $('advanced-toggle').setAttribute('aria-expanded', 'true'); }
        load();
    });
    $('dashboard-export').addEventListener('click', () => {
        if (!result) return;
        const rows = [['SITRA · Resumen operativo'], ['Desde', result.filters.from, 'Hasta', result.filters.to], ['Zona horaria IPS', 'America/La_Paz'], ['Filtros', JSON.stringify(result.filters)]];
        const ips = result.ips;
        if (ips.status === 'ok') {
            rows.push(['Fuente', 'IPS', 'Consultado', ips.generated_at], ['Indicador', 'Valor', 'Periodo anterior']);
            const metricLabels = { packages: 'Envíos con actividad', received: 'Envíos recibidos', dispatched: 'Envíos despachados', delivered: 'Envíos entregados', movements: 'Movimientos', entries: 'Entradas', exits: 'Salidas', offices: 'Oficinas', operators: 'Operadores' };
            Object.entries(ips.totals).forEach(([key, value]) => rows.push([metricLabels[key] || key, value, ips.previous[key]]));
            rows.push(['Peso total registrado (kg)', ips.weight.total], ['Peso promedio (kg)', ips.weight.average], ['Envíos con peso registrado', ips.weight.known], ['Devolución en curso (estado postal actual)', ips.returns], ['Envíos del registro histórico (sin filtros)', ips.inventory]);
            rows.push([], ['Situación', 'Envíos']); Object.entries(ips.states).forEach(([key, value]) => rows.push([states[key], value]));
            rows.push([], ['Fecha', 'Envíos únicos del día', 'Entradas', 'Salidas', 'Eventos de entrega', 'Movimientos']);
            ips.timeline.forEach(row => rows.push([row.date, row.packages, row.entries, row.exits, row.deliveries, row.movements]));
            for (const [label, data] of [['Servicios', ips.services], ['Oficinas (movimientos)', ips.offices], ['Origen', ips.geography.origin], ['Destino', ips.geography.destination], ['Tipo de envío', ips.types], ['Eventos', ips.frequent]]) { rows.push([], [label, 'Total']); data.forEach(row => rows.push([row.name || row.code || 'Sin dato', row.total])); }
        }
        if (result.cds.status === 'ok') { rows.push([], ['Fuente', 'CDS', 'Consultado', result.cds.generated_at], ['Fecha', 'Fecha postal CDS; zona horaria no informada']); for (const [key, label] of Object.entries({ objects: 'Objetos', declarations: 'Declaraciones', responses: 'Respuestas', withoutResponse: 'Objetos sin respuesta' })) rows.push([label, result.cds[key]]); rows.push([], ['Fecha postal', 'Objetos', 'Declaraciones', 'Respuestas']); result.cds.timeline.forEach(row => rows.push([row.date, row.objects, row.declarations, row.responses])); }
        rows.push([], ['Notas', 'Recepciones, despachos y entregas no se suman. Los envíos únicos diarios no se suman para obtener los del periodo. El estado es el último evento operativo del periodo y oficina.']);
        const csv = '\uFEFF' + rows.map(row => row.map(value => { let v = String(value ?? ''); if (/^[=+\-@\t\r\n]/.test(v)) v = "'" + v; return '"' + v.replace(/"/g, '""') + '"'; }).join(';')).join('\r\n');
        const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8;' })); const a = document.createElement('a'); a.href = url; a.download = scope === 'all'
            ? `sitra-resumen-${result.filters.from}-${result.filters.to}.csv`
            : `sitra-dashboard-${scope}-${result.filters.from}-${result.filters.to}.csv`; a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
    });
    initialQuery.forEach((value, key) => {
        const input = form.elements.namedItem(key); if (!input) return;
        if (input.tagName === 'SELECT' && !Array.from(input.options).some(option => option.value === value)) input.add(new Option(value, value));
        input.value = value;
    });
    if (['state', 'origin', 'destination', 'type'].some(key => initialQuery.get(key)) && $('advanced-filters')) { $('advanced-filters').hidden = false; $('advanced-toggle').setAttribute('aria-expanded', 'true'); }
    if (initialQuery.has('from') || initialQuery.has('to')) root.querySelectorAll('[data-period]').forEach(button => { button.classList.remove('is-active'); button.setAttribute('aria-pressed', 'false'); });
    if (scope === 'cds' && !initialQuery.has('from') && !initialQuery.has('to')) preset('year');
    load();
})();
