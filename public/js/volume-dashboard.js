(() => {
    'use strict';
    const root = document.getElementById('volume-dashboard');
    if (!root) return;

    const $ = id => document.getElementById(id);
    const scope = root.dataset.scope;
    const fromInput = $('report-from');
    const toInput = $('report-to');
    const form = $('volume-filters');
    const colors = ['#062e5d', '#087f8c', '#245e9a', '#8e9da8', '#54a0df', '#49a897', '#c3a64b', '#7867a7'];
    const number = value => new Intl.NumberFormat('es-BO', { maximumFractionDigits: 0 }).format(Number(value) || 0);
    const percent = value => new Intl.NumberFormat('es-BO', { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(Number(value) || 0);
    const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
    let mode = 'daily';
    let initialMode = 'daily';
    let controller = null;
    let exportRows = [];
    let exportHeaders = [];
    const chartFilters = {};
    const isDateValue = value => /^\d{4}-\d{2}-\d{2}$/.test(value || '');
    const isMonthValue = value => /^\d{4}-\d{2}$/.test(value || '');
    const todayMonth = root.dataset.today.slice(0, 7);
    const monthEnd = value => {
        const [year, month] = value.split('-').map(Number);
        const lastDay = new Date(Date.UTC(year, month, 0)).toISOString().slice(0, 10);
        return value === todayMonth ? root.dataset.today : lastDay;
    };
    try {
        const savedMode = sessionStorage.getItem('sitra.volume-dashboard.mode');
        const savedFrom = sessionStorage.getItem('sitra.volume-dashboard.from');
        const savedTo = sessionStorage.getItem('sitra.volume-dashboard.to');
        const savedDate = sessionStorage.getItem('sitra.volume-dashboard.date');
        if (savedMode === 'monthly') {
            initialMode = 'monthly';
            fromInput.type = toInput.type = 'month';
            fromInput.value = isMonthValue(savedFrom) ? savedFrom : (isMonthValue(savedDate) ? savedDate : todayMonth);
            toInput.value = isMonthValue(savedTo) ? savedTo : (isMonthValue(savedDate) ? savedDate : fromInput.value);
        } else {
            const fallbackDate = isDateValue(savedDate) ? savedDate : root.dataset.today;
            fromInput.value = isDateValue(savedFrom) ? savedFrom : fallbackDate;
            toInput.value = isDateValue(savedTo) ? savedTo : fallbackDate;
        }
    } catch (_) { /* Use today's date when browser storage is unavailable. */ }

    function setStatus(message) { $('volume-status').textContent = message; }
    function setLoading(loading) {
        $('volume-loading').hidden = !loading;
        root.classList.toggle('is-loading', loading);
    }
    function updateChartFilter(key, value) {
        if (!value) return;
        if (String(chartFilters[key] ?? '') === String(value)) delete chartFilters[key];
        else chartFilters[key] = String(value);
        updateActiveFilters();
        load();
    }
    function updateActiveFilters() {
        const labels = { origin: 'País de origen', destination: 'País de destino', mail_category: 'Categoría postal', mail_product: 'Producto S10', cds_origin_operator: 'Operador de origen CDS', cds_destination_operator: 'Operador de destino CDS', cds_state: 'Estado CDS' };
        const selected = Object.entries(chartFilters).map(([key, value]) => `${labels[key] || key}: ${value}`);
        if (form.elements.service?.value) selected.push(`Clase / servicio: ${form.elements.service.selectedOptions[0]?.textContent || form.elements.service.value}`);
        const element = $('volume-active-filters');
        if (!element) return;
        element.hidden = selected.length === 0;
        $('volume-active-filter-label').textContent = selected.length ? `Selecciones de gráficos: ${selected.join(' · ')}` : '';
    }
    function setMode(next) {
        const oldFrom = fromInput.value;
        const oldTo = toInput.value;
        mode = next;
        document.querySelectorAll('.volume-mode-tabs [data-mode]').forEach(button => {
            button.setAttribute('aria-selected', String(button.dataset.mode === mode));
        });
        if (mode === 'monthly') {
            fromInput.type = toInput.type = 'month';
            fromInput.max = toInput.max = todayMonth;
            fromInput.value = isMonthValue(oldFrom) ? oldFrom : (isDateValue(oldFrom) ? oldFrom.slice(0, 7) : todayMonth);
            toInput.value = isMonthValue(oldTo) ? oldTo : (isDateValue(oldTo) ? oldTo.slice(0, 7) : fromInput.value);
            $('report-from-label').textContent = 'Mes inicial';
            $('report-to-label').textContent = 'Mes final';
            $('trend-title').textContent = 'Tendencia mensual';
            $('trend-subtitle').textContent = 'Volumen postal agrupado por mes';
            $('trend-caption').textContent = 'Por mes';
        } else {
            fromInput.type = toInput.type = 'date';
            fromInput.max = toInput.max = root.dataset.today;
            fromInput.value = isDateValue(oldFrom) ? oldFrom : (isMonthValue(oldFrom) ? `${oldFrom}-01` : root.dataset.today);
            toInput.value = isDateValue(oldTo) ? oldTo : (isMonthValue(oldTo) ? monthEnd(oldTo) : fromInput.value);
            $('report-from-label').textContent = 'Desde';
            $('report-to-label').textContent = 'Hasta';
            $('trend-title').textContent = 'Tendencia horaria';
            $('trend-subtitle').textContent = 'Volumen por hora · hora de Bolivia';
            $('trend-caption').textContent = 'Por hora';
        }
    }

    function getPeriod() {
        if (!fromInput.value || !toInput.value) return null;
        let from;
        let to;
        if (mode === 'daily') {
            from = fromInput.value;
            to = toInput.value;
        } else {
            if (!isMonthValue(fromInput.value) || !isMonthValue(toInput.value)) return null;
            from = `${fromInput.value}-01`;
            to = monthEnd(toInput.value);
        }
        if (to < from) return { error: 'La fecha final debe ser igual o posterior a la inicial.' };
        const spanDays = (Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) / 86400000;
        if (spanDays > 365) return { error: 'El rango máximo del reporte es de 366 días.' };
        return { from, to };
    }

    function setOptions(select, rows, firstLabel, selected, useNames = true) {
        if (!select) return;
        const unique = new Map();
        rows.forEach(row => {
            const code = row.code;
            if (code === null || code === undefined || code === '') return;
            const key = String(code);
            if (!unique.has(key)) unique.set(key, row.name || row.label || key);
        });
        const current = selected ?? select.value;
        if (current && !unique.has(String(current))) unique.set(String(current), String(current));
        select.innerHTML = `<option value="">${escape(firstLabel)}</option>` + [...unique.entries()].map(([code, name]) =>
            `<option value="${escape(code)}">${escape(useNames ? name : code)}</option>`).join('');
        if (current && [...select.options].some(option => option.value === current)) select.value = current;
    }

    function svgEmpty(message) { return `<div class="chart-empty">${escape(message)}</div>`; }
    function niceMax(value) {
        if (value <= 0) return 1;
        const power = Math.pow(10, Math.floor(Math.log10(value)));
        const scaled = value / power;
        return (scaled <= 1 ? 1 : scaled <= 2 ? 2 : scaled <= 5 ? 5 : 10) * power;
    }
    function legendHtml(items) {
        return items.map(item => `<span><i style="background:${item.color}"></i>${escape(item.label)}</span>`).join('');
    }

    function renderTrend(container, legendContainer, rows, definitions, labelFn, onPointSelect = null) {
        legendContainer.innerHTML = legendHtml(definitions);
        if (!rows.length) { container.innerHTML = svgEmpty('Sin datos para el periodo seleccionado'); return; }
        const width = 800, height = 250, left = 49, right = 15, top = 13, bottom = 35;
        const plotW = width - left - right, plotH = height - top - bottom;
        const maxValue = niceMax(Math.max(0, ...rows.flatMap(row => definitions.map(def => Number(row[def.key]) || 0))));
        const x = index => left + (rows.length === 1 ? plotW / 2 : index * plotW / (rows.length - 1));
        const y = value => top + plotH - (Number(value || 0) / maxValue) * plotH;
        const step = maxValue / 4;
        let svg = `<svg viewBox="0 0 ${width} ${height}" role="img" aria-label="Tendencia de volumen postal"><title>Tendencia de volumen postal</title>`;
        for (let i = 0; i <= 4; i++) {
            const value = step * i, yy = y(value);
            svg += `<line class="chart-grid" x1="${left}" y1="${yy}" x2="${width - right}" y2="${yy}"/><text x="${left - 8}" y="${yy + 3}" text-anchor="end">${escape(number(value))}</text>`;
        }
        svg += `<text x="13" y="${top + plotH / 2}" text-anchor="middle" transform="rotate(-90 13 ${top + plotH / 2})">Volumen</text>`;
        svg += `<line class="chart-axis" x1="${left}" y1="${top + plotH}" x2="${width - right}" y2="${top + plotH}"/>`;
        const labelEvery = Math.max(1, Math.ceil(rows.length / 8));
        rows.forEach((row, index) => {
            if (index % labelEvery === 0 || index === rows.length - 1) {
                svg += `<text x="${x(index)}" y="${height - 10}" text-anchor="middle">${escape(labelFn(row))}</text>`;
            }
        });
        definitions.forEach((definition, seriesIndex) => {
            const points = rows.map((row, index) => `${x(index)},${y(row[definition.key])}`).join(' ');
            svg += `<polyline fill="none" stroke="${definition.color}" stroke-width="${seriesIndex === 0 ? 2.7 : 2.2}" ${seriesIndex === 1 ? 'stroke-dasharray="5 4"' : ''} points="${points}"/>`;
            rows.forEach((row, index) => {
                const value = Number(row[definition.key]) || 0;
                svg += `<circle ${onPointSelect ? `class="volume-chart-point" tabindex="0" role="button" data-point-index="${index}"` : ''} cx="${x(index)}" cy="${y(value)}" r="3.2" fill="${definition.color}"><title>${escape(labelFn(row))}: ${escape(definition.label)} ${escape(number(value))}</title></circle>`;
            });
        });
        container.innerHTML = svg + '</svg>';
        if (onPointSelect) container.querySelectorAll('[data-point-index]').forEach(point => {
            const select = () => onPointSelect(rows[Number(point.dataset.pointIndex)]);
            point.addEventListener('click', select);
            point.addEventListener('keydown', event => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); select(); } });
        });
    }

    function renderStacked(container, legendContainer, buckets, series, valueAt, labelFn, onSeriesSelect = null) {
        legendContainer.innerHTML = legendHtml(series);
        if (!buckets.length || !series.length) { container.innerHTML = svgEmpty('Sin datos para el periodo seleccionado'); return; }
        const width = 800, height = 250, left = 42, right = 13, top = 12, bottom = 36;
        const plotW = width - left - right, plotH = height - top - bottom;
        const totals = buckets.map(bucket => series.reduce((sum, item) => sum + (Number(valueAt(bucket, item.key)) || 0), 0));
        const maxValue = niceMax(Math.max(0, ...totals));
        const gap = buckets.length > 18 ? 5 : 13;
        const barW = Math.max(3, Math.min(42, plotW / buckets.length - gap));
        const groupW = plotW / buckets.length;
        const y = value => top + plotH - (Number(value || 0) / maxValue) * plotH;
        let svg = `<svg viewBox="0 0 ${width} ${height}" role="img" aria-label="Distribución horaria o mensual por clase"><title>Distribución del volumen postal</title>`;
        for (let i = 0; i <= 4; i++) {
            const value = maxValue * i / 4, yy = y(value);
            svg += `<line class="chart-grid" x1="${left}" y1="${yy}" x2="${width - right}" y2="${yy}"/><text x="${left - 7}" y="${yy + 3}" text-anchor="end">${escape(number(value))}</text>`;
        }
        svg += `<text x="11" y="${top + plotH / 2}" text-anchor="middle" transform="rotate(-90 11 ${top + plotH / 2})">Volumen</text>`;
        svg += `<line class="chart-axis" x1="${left}" y1="${top + plotH}" x2="${width - right}" y2="${top + plotH}"/>`;
        const labelEvery = Math.max(1, Math.ceil(buckets.length / 10));
        buckets.forEach((bucket, index) => {
            const x0 = left + index * groupW + (groupW - barW) / 2;
            let baseline = top + plotH;
            series.forEach(item => {
                const value = Number(valueAt(bucket, item.key)) || 0;
                if (!value) return;
                const barH = (value / maxValue) * plotH;
                baseline -= barH;
                svg += `<rect ${onSeriesSelect ? `class="volume-chart-segment" tabindex="0" role="button" data-series-key="${escape(item.key)}"` : ''} x="${x0}" y="${baseline}" width="${barW}" height="${barH}" fill="${item.color}"><title>${escape(labelFn(bucket))}: ${escape(item.label)} ${escape(number(value))}</title></rect>`;
            });
            if (index % labelEvery === 0 || index === buckets.length - 1) {
                svg += `<text x="${x0 + barW / 2}" y="${height - 10}" text-anchor="middle">${escape(labelFn(bucket))}</text>`;
            }
        });
        container.innerHTML = svg + '</svg>';
        if (onSeriesSelect) container.querySelectorAll('[data-series-key]').forEach(segment => {
            const select = () => onSeriesSelect(segment.dataset.seriesKey);
            segment.addEventListener('click', select);
            segment.addEventListener('keydown', event => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); select(); } });
        });
    }

    function renderRanking(container, rows, onSelect = null, selectedCode = null) {
        const top = (rows || []).slice(0, 10);
        if (!top.length) { container.innerHTML = '<div class="volume-ranking-empty">Sin datos disponibles</div>'; return; }
        const max = Math.max(...top.map(row => Number(row.total) || 0), 1);
        container.innerHTML = top.map(row => {
            const value = Number(row.total) || 0;
            const label = row.name || row.code || 'Sin clasificación';
            const code = String(row.code ?? row.name ?? '');
            const element = onSelect ? 'button' : 'div';
            return `<${element} ${onSelect ? 'type="button"' : ''} class="volume-rank-row${onSelect ? ' is-selectable' : ''}${String(selectedCode ?? '') === code ? ' is-selected' : ''}" ${onSelect ? `data-rank-code="${escape(code)}"` : ''} title="${escape(label)}: ${escape(number(value))}"><span class="volume-rank-name">${escape(label)}</span><span class="volume-rank-track"><i class="volume-rank-bar" style="width:${Math.max(1, value / max * 100)}%"></i></span><span class="volume-rank-value">${escape(number(value))}</span></${element}>`;
        }).join('');
        if (onSelect) container.querySelectorAll('[data-rank-code]').forEach(button => button.addEventListener('click', () => onSelect(button.dataset.rankCode)));
    }

    function renderDonut(container, rows, totalOverride, onSelect = null, selectedCode = null) {
        let items = (rows || []).map((row, index) => ({
            label: row.name || row.code || 'Sin clasificación',
            value: Number(row.total) || 0,
            code: row.code,
            color: colors[index % colors.length],
        })).filter(item => item.value > 0).sort((a, b) => b.value - a.value);
        if (!items.length) { container.innerHTML = '<div class="volume-donut-empty">Sin datos de clasificación disponibles</div>'; return; }
        const dataTotal = items.reduce((sum, item) => sum + item.value, 0);
        if (items.length > 8) {
            const others = items.slice(7).reduce((sum, item) => sum + item.value, 0);
            items = items.slice(0, 7).concat([{ label: 'Otros', value: others, color: colors[7] }]);
        }
        const total = Number(totalOverride) || dataTotal;
        const shownTotal = items.reduce((sum, item) => sum + item.value, 0);
        if (total > shownTotal) {
            if (items.length >= 8) items[7].value += total - shownTotal;
            else items.push({ label: 'Sin clasificación', value: total - shownTotal, color: colors[items.length % colors.length] });
        }
        let accumulated = 0;
        const gradient = items.map(item => {
            const start = accumulated;
            accumulated += item.value / total * 100;
            return `${item.color} ${start.toFixed(2)}% ${accumulated.toFixed(2)}%`;
        }).join(',');
        container.innerHTML = `<div class="volume-donut-visual" style="--donut-gradient:conic-gradient(${gradient})"><div class="volume-donut-center"><strong>${escape(number(total))}</strong><small>Total</small></div></div><ul class="volume-donut-legend">${items.map(item => `<li title="${escape(item.label)}: ${escape(number(item.value))}" class="${String(selectedCode ?? '') === String(item.code ?? '') ? 'is-selected' : ''}"><i style="background:${item.color}"></i>${onSelect && item.code ? `<button type="button" data-donut-code="${escape(item.code)}">${escape(item.label)}</button>` : `<span>${escape(item.label)}</span>`}<b>${escape(number(item.value))}</b></li>`).join('')}</ul>`;
        if (onSelect) container.querySelectorAll('[data-donut-code]').forEach(button => button.addEventListener('click', () => onSelect(button.dataset.donutCode)));
    }

    function renderTable(rows, fields) {
        const head = $('volume-table-head'), body = $('volume-table-body');
        head.innerHTML = `<tr>${fields.map(field => `<th scope="col">${escape(field.label)}</th>`).join('')}</tr>`;
        body.innerHTML = rows.length ? rows.map(row => `<tr>${fields.map(field => `<td>${escape(field.format ? field.format(row[field.key]) : number(row[field.key]))}</td>`).join('')}</tr>`).join('') : `<tr><td colspan="${fields.length}" class="text-center text-muted py-4">Sin registros para mostrar</td></tr>`;
        exportHeaders = fields.map(field => field.label);
        exportRows = rows.map(row => fields.map(field => field.format ? field.format(row[field.key]) : number(row[field.key])));
        $('volume-export').disabled = !rows.length;
    }

    function prepareCombinedExport(ips, cds, multipleDays) {
        if (scope !== 'all') return;
        const postalRows = mode === 'monthly' ? (ips?.monthly || []) : (multipleDays ? (ips?.timeline || []) : (ips?.hourly || []));
        const customsRows = mode === 'monthly' ? (cds?.monthly || []) : (multipleDays ? (cds?.timeline || []) : (cds?.hourly || []));
        const keyFor = row => mode === 'monthly'
            ? String(row.date || '').slice(0, 7)
            : (multipleDays ? String(row.date || '') : `${String(row.hour).padStart(2, '0')}:00`);
        const postal = new Map(postalRows.map(row => [keyFor(row), row]));
        const customs = new Map(customsRows.map(row => [keyFor(row), row]));
        const keys = [...new Set([...postal.keys(), ...customs.keys()])].sort();
        exportHeaders = ['Periodo', 'Envíos IPS', 'Recibidos IPS', 'Despachados IPS', 'Movimientos IPS', 'Objetos CDS', 'Declaraciones CDS', 'Respuestas CDS'];
        exportRows = keys.map(key => {
            const ipsRow = postal.get(key) || {};
            const cdsRow = customs.get(key) || {};
            return [key, number(ipsRow.packages ?? ipsRow.items), number(ipsRow.received), number(ipsRow.dispatched), number(ipsRow.movements), number(cdsRow.objects), number(cdsRow.declarations), number(cdsRow.responses)];
        });
        $('volume-export').disabled = !exportRows.length;
    }

    function formatDateLabel(value) {
        if (mode === 'daily') {
            if (fromInput.value !== toInput.value && value.date) {
                const date = new Date(`${value.date}T00:00:00Z`);
                return new Intl.DateTimeFormat('es-BO', { day: '2-digit', month: 'short', timeZone: 'UTC' }).format(date).replace('.', '');
            }
            return `${String(value.hour).padStart(2, '0')}:00`;
        }
        const date = new Date(`${value.date}T00:00:00Z`);
        return new Intl.DateTimeFormat('es-BO', { month: 'short', year: '2-digit', timeZone: 'UTC' }).format(date).replace('.', '');
    }
    function updateTrendRange(period) {
        if (mode !== 'daily') return;
        const multipleDays = period.from !== period.to;
        $('trend-title').textContent = multipleDays ? 'Tendencia diaria' : 'Tendencia horaria';
        $('trend-subtitle').textContent = multipleDays ? 'Volumen postal agrupado por día' : 'Volumen por hora · hora de Bolivia';
        $('trend-caption').textContent = multipleDays ? 'Por día' : 'Por hora';
    }
    function formattedPeriod(period) {
        return mode === 'monthly'
            ? `${period.from.slice(0, 7)} — ${period.to.slice(0, 7)} · comparación anual`
            : `${period.from} — ${period.to} · hora de Bolivia`;
    }

    function renderKpis(current, previous, data) {
        const isPostalVolume = scope !== 'cds';
        const values = isPostalVolume ? [
            ['packages', current.totals?.packages, previous.totals?.packages],
            ['dispatched', current.totals?.dispatched, previous.totals?.dispatched],
            ['received', current.totals?.received, previous.totals?.received],
            ['returns', current.returns, current.previous_returns],
            ['transit', current.states?.transit || 0, current.previous_states?.transit || 0],
            [scope === 'all' ? 'no_imtatt' : 'no_product', scope === 'all' ? null : current.no_product, scope === 'all' ? null : current.previous_no_product],
        ] : [
            ['objects', current.objects, previous.objects],
            ['declaredObjects', current.declaredObjects, previous.declaredObjects],
            ['declarations', current.declarations, previous.declarations],
            ['respondedObjects', current.respondedObjects, previous.respondedObjects],
            ['responses', current.responses, previous.responses],
            ['withoutResponse', current.withoutResponse, previous.withoutResponse],
        ];
        values.forEach(([key, value, oldValue]) => {
            const output = document.querySelector(`[data-kpi="${key}"]`);
            const change = document.querySelector(`[data-change="${key}"]`);
            if (output) output.textContent = key === 'no_imtatt' ? 'N/D' : number(value);
            if (!change) return;
            if (key === 'no_imtatt') {
                change.textContent = 'No disponible en IPS/CDS';
                change.className = '';
                return;
            }
            const now = Number(value) || 0, before = Number(oldValue) || 0;
            if (!Number.isFinite(Number(oldValue)) || Number(oldValue) === undefined || (before === 0 && now === 0)) {
                change.textContent = 'Sin actividad en la comparación';
                change.className = '';
                return;
            }
            if (before === 0) {
                change.textContent = mode === 'monthly' ? '▲ Nuevo vs mismo periodo año anterior' : '▲ Nuevo en el periodo';
                change.className = 'is-up';
                return;
            }
            const pct = (now - before) / before * 100;
            const lowerIsBetter = ['returns', 'no_product', 'withoutResponse'].includes(key);
            const favorable = lowerIsBetter ? pct < 0 : pct > 0;
            const comparisonLabel = mode === 'monthly' ? 'vs mismo periodo año anterior' : 'vs periodo anterior';
            change.className = favorable ? 'is-up' : (pct === 0 ? '' : 'is-down');
            change.textContent = `${pct > 0 ? '▲' : pct < 0 ? '▼' : '•'} ${percent(Math.abs(pct))}% ${comparisonLabel}`;
        });
        $('volume-period-label').textContent = formattedPeriod(data.filters || getPeriod());
    }

    function buildServicesData(source) {
        const rows = mode === 'daily' ? source.hourly_services || [] : source.monthly_services || [];
        const items = new Map();
        rows.forEach(row => {
            const key = String(row.code ?? row.name ?? 'unknown');
            const existing = items.get(key) || { key, label: row.name || row.code || 'Sin clase', sum: 0 };
            existing.sum += Number(row.total) || 0;
            items.set(key, existing);
        });
        return [...items.values()].sort((a, b) => b.sum - a.sum).slice(0, 5).map((item, index) => ({ ...item, color: colors[index % colors.length] }));
    }

    function renderCombinedCds(source) {
        const status = source?.status;
        const messages = { forbidden: 'Tu cuenta no tiene permiso para consultar CDS.', disabled: 'CDS no está habilitado en este entorno.', unavailable: 'CDS no está disponible en este momento.' };
        document.querySelectorAll('[data-cds-kpi]').forEach(output => {
            const key = output.dataset.cdsKpi;
            output.textContent = status === 'ok' ? number(source[key]) : 'N/D';
            const note = document.querySelector(`[data-cds-change="${key}"]`);
            if (note) note.textContent = status === 'ok' ? 'Objetos postales CDS' : (messages[status] || 'Fuente no disponible');
        });
        if (status !== 'ok') {
            ['cds-volume-trend', 'cds-volume-states', 'cds-volume-origins', 'cds-volume-destinations'].forEach(id => {
                const element = $(id);
                if (element) element.innerHTML = svgEmpty(messages[status] || 'No hay datos CDS disponibles');
            });
            return;
        }

        const multipleDays = fromInput.value !== toInput.value;
        const rows = mode === 'monthly' ? source.monthly || [] : (multipleDays ? source.timeline || [] : source.hourly || []);
        const label = row => {
            if (mode === 'daily' && !multipleDays) return `${String(row.hour).padStart(2, '0')}:00`;
            const date = new Date(`${row.date}T00:00:00Z`);
            return new Intl.DateTimeFormat('es-BO', mode === 'monthly'
                ? { month: 'short', year: '2-digit', timeZone: 'UTC' }
                : { day: '2-digit', month: 'short', timeZone: 'UTC' }).format(date).replace('.', '');
        };
        const selectPeriod = mode === 'monthly'
            ? bucket => { const month = String(bucket.date).slice(0, 7); setMode('monthly'); fromInput.value = toInput.value = month; load(); }
            : (multipleDays ? bucket => { setMode('daily'); fromInput.value = toInput.value = bucket.date; load(); } : null);
        renderTrend($('cds-volume-trend'), $('cds-volume-trend-legend'), rows, [
            { key: 'objects', label: 'Objetos postales CDS', color: colors[0] },
            { key: 'declarations', label: 'Declaraciones', color: colors[2] },
            { key: 'responses', label: 'Respuestas', color: colors[1] },
        ], label, selectPeriod);
        renderRanking($('cds-volume-states'), source.states || [], code => updateChartFilter('cds_state', code), chartFilters.cds_state);
        renderRanking($('cds-volume-origins'), source.origins || [], code => updateChartFilter('cds_origin_operator', code), chartFilters.cds_origin_operator);
        renderRanking($('cds-volume-destinations'), source.destinations || [], code => updateChartFilter('cds_destination_operator', code), chartFilters.cds_destination_operator);
    }

    function drawReport(data) {
        const isPostalVolume = scope !== 'cds';
        const current = isPostalVolume ? data.ips : data.cds;
        if (!current || current.status !== 'ok') {
            const messages = { unavailable: 'La fuente está temporalmente fuera de servicio. Intenta actualizar en unos minutos.', disabled: 'El reporte CDS no está habilitado en este entorno.', unsupported: current?.message || 'El filtro seleccionado no está disponible para esta fuente.', forbidden: 'Tu cuenta no tiene permiso para consultar este reporte.' };
            if (scope === 'all' && data.cds?.status === 'ok') {
                $('volume-primary-content').hidden = true;
                $('volume-error').textContent = `IPS: ${messages[current?.status] || 'no disponible'}`;
                $('volume-error').hidden = false;
                $('volume-results').hidden = false;
                $('volume-results').setAttribute('aria-busy', 'false');
                renderCombinedCds(data.cds);
                prepareCombinedExport(null, data.cds, mode === 'daily' && fromInput.value !== toInput.value);
                setStatus('CDS consultado · IPS no disponible');
                return;
            }
            $('volume-error').textContent = messages[current?.status] || 'No se pudo cargar el reporte.';
            $('volume-error').hidden = false;
            setStatus('Reporte no disponible');
            $('volume-results').setAttribute('aria-busy', 'false');
            return;
        }
        $('volume-primary-content').hidden = false;
        $('volume-error').hidden = true;
        $('volume-results').hidden = false;
        $('volume-results').setAttribute('aria-busy', 'false');
        const previous = isPostalVolume ? { totals: current.previous } : (current.previous || {});
        renderKpis(current, previous, data);
        const multipleDays = mode === 'daily' && fromInput.value !== toInput.value;
        const buckets = mode === 'daily'
            ? (multipleDays ? current.timeline || [] : current.hourly || [])
            : current.monthly || [];
        const metricKeys = isPostalVolume ? [
            { key: 'dispatched', label: 'Envíos despachados', color: colors[2] },
            { key: 'received', label: 'Envíos recibidos', color: colors[1] },
            { key: multipleDays ? 'packages' : 'items', label: 'Total de envíos', color: colors[0] },
        ] : [
            { key: 'declarations', label: 'Declaraciones', color: colors[2] },
            { key: 'responses', label: 'Respuestas', color: colors[1] },
            { key: 'objects', label: 'Objetos postales', color: colors[0] },
        ];
        const selectTrendPeriod = mode === 'monthly'
            ? bucket => { const month = String(bucket.date).slice(0, 7); setMode('monthly'); fromInput.value = toInput.value = month; load(); }
            : (multipleDays ? bucket => { setMode('daily'); fromInput.value = toInput.value = bucket.date; load(); } : null);
        renderTrend($('volume-trend'), $('volume-trend-legend'), buckets, metricKeys, formatDateLabel, selectTrendPeriod);

        let stackBuckets, stackSeries, stackValue;
        if (isPostalVolume) {
            stackBuckets = mode === 'daily' ? current.hourly || [] : current.monthly || [];
            stackSeries = buildServicesData(current).map(item => ({ key: item.key, label: item.label, color: item.color }));
            const serviceRows = mode === 'daily' ? current.hourly_services || [] : current.monthly_services || [];
            const lookup = new Map();
            serviceRows.forEach(row => {
                const bucket = String(mode === 'daily' ? row.hour : row.date);
                const id = String(row.code ?? row.name ?? 'unknown');
                if (!lookup.has(bucket)) lookup.set(bucket, {});
                lookup.get(bucket)[id] = (lookup.get(bucket)[id] || 0) + Number(row.total || 0);
            });
            stackValue = (bucket, key) => lookup.get(String(mode === 'daily' ? bucket.hour : bucket.date))?.[key] || 0;
            $('distribution-title').textContent = 'Volumen por clase postal';
        } else {
            stackBuckets = mode === 'daily' ? current.hourly || [] : current.monthly || [];
            const stateNames = new Map();
            stackBuckets.forEach(bucket => Object.keys(bucket.states || {}).forEach(name => stateNames.set(name, (stateNames.get(name) || 0) + Number(bucket.states[name] || 0))));
            stackSeries = [...stateNames.entries()].sort((a, b) => b[1] - a[1]).slice(0, 5).map(([name], index) => ({ key: name, label: name, color: colors[index % colors.length] }));
            stackValue = (bucket, key) => bucket.states?.[key] || 0;
            $('distribution-title').textContent = 'Volumen por estado CDS';
        }
        $('distribution-title').parentElement.querySelector('p').textContent = mode === 'daily'
            ? (multipleDays ? 'Distribución horaria acumulada del rango' : 'Distribución por hora del día')
            : 'Distribución por mes del periodo';
        const stackLabel = mode === 'daily' ? bucket => `${String(bucket.hour).padStart(2, '0')}:00` : formatDateLabel;
        const selectService = isPostalVolume ? code => {
            const serviceSelect = form.elements.service;
            if (!serviceSelect) return;
            serviceSelect.value = serviceSelect.value === code ? '' : code;
            updateActiveFilters();
            load();
        } : null;
        renderStacked($('volume-distribution'), $('distribution-legend'), stackBuckets, stackSeries, stackValue, stackLabel, selectService);

        if (isPostalVolume) {
            $('destination-title').textContent = 'Top 10 países de destino';
            $('origin-title').textContent = 'Top 10 países de origen';
            $('category-title').textContent = 'Volumen por categoría postal';
            $('product-title').textContent = 'Volumen por producto S10';
            renderRanking($('volume-destinations'), current.geography?.destination || [], row => updateChartFilter('destination', row), chartFilters.destination);
            renderRanking($('volume-origins'), current.geography?.origin || [], row => updateChartFilter('origin', row), chartFilters.origin);
            renderDonut($('volume-categories'), current.categories || [], current.totals?.packages, code => {
                if (/^[A-Za-z0-9_-]{1,5}$/.test(code)) updateChartFilter('mail_category', code);
            }, chartFilters.mail_category);
            renderDonut($('volume-products'), current.products || [], current.totals?.packages, code => {
                if (/^[A-Z]A-[A-Z]Z$/.test(code)) updateChartFilter('mail_product', code);
            }, chartFilters.mail_product);
        } else {
            $('destination-title').textContent = 'Top 10 operadores de destino';
            $('origin-title').textContent = 'Top 10 operadores de origen';
            $('category-title').textContent = 'Volumen por categoría';
            $('product-title').textContent = 'Volumen por producto';
            renderRanking($('volume-destinations'), current.destinations || []);
            renderRanking($('volume-origins'), current.origins || []);
            renderDonut($('volume-categories'), current.categories || [], current.objects);
            renderDonut($('volume-products'), current.products || [], current.objects);
        }

        if (isPostalVolume) {
            const rows = mode === 'daily' ? current.timeline || [] : current.monthly || [];
            renderTable(rows, mode === 'daily' ? [
                { key: 'date', label: 'Fecha' }, { key: 'packages', label: 'Envíos únicos' },
                { key: 'received', label: 'Recibidos' }, { key: 'dispatched', label: 'Despachados' },
                { key: 'movements', label: 'Movimientos' },
            ] : [
                { key: 'date', label: 'Mes' }, { key: 'items', label: 'Envíos únicos' },
                { key: 'received', label: 'Recibidos' }, { key: 'dispatched', label: 'Despachados' },
            ]);
        } else {
            const rows = mode === 'daily' ? current.timeline || [] : current.monthly || [];
            renderTable(rows, [
                { key: 'date', label: 'Fecha' }, { key: 'objects', label: 'Objetos postales' },
                { key: 'declarations', label: 'Declaraciones' }, { key: 'responses', label: 'Respuestas' },
            ]);
        }
        $('volume-table-title').textContent = mode === 'monthly' ? 'Meses seleccionados' : 'Detalle del periodo';
        $('volume-table-caption').textContent = mode === 'monthly'
            ? 'Serie mensual; las tarjetas resumen el rango seleccionado.'
            : 'Registros exactos que alimentan las gráficas.';
        $('volume-generated').textContent = current.generated_at ? `Datos consultados: ${new Intl.DateTimeFormat('es-BO', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'America/La_Paz' }).format(new Date(current.generated_at))} · Bolivia` : '';
        if (scope === 'all') {
            renderCombinedCds(data.cds);
            prepareCombinedExport(current, data.cds, multipleDays);
        }
        setStatus(scope === 'all' ? 'IPS + CDS · Fuentes consultadas por separado' : (scope === 'ips' ? 'IPS · Volúmenes postales consultados' : 'CDS · Objetos y declaraciones consultados'));
    }

    async function load() {
        const period = getPeriod();
        if (period?.error) {
            setLoading(false);
            $('volume-error').textContent = period.error;
            $('volume-error').hidden = false;
            $('volume-results').hidden = true;
            $('volume-results').setAttribute('aria-busy', 'false');
            setStatus('Revisa el periodo seleccionado');
            return;
        }
        if (!period?.from || !period?.to) return;
        updateTrendRange(period);
        try {
            sessionStorage.setItem('sitra.volume-dashboard.mode', mode);
            sessionStorage.setItem('sitra.volume-dashboard.from', fromInput.value);
            sessionStorage.setItem('sitra.volume-dashboard.to', toInput.value);
            sessionStorage.setItem('sitra.volume-dashboard.date', toInput.value);
        } catch (_) { /* The report works without remembering its last period. */ }
        if (controller) controller.abort();
        controller = new AbortController();
        $('volume-error').hidden = true;
        $('volume-results').hidden = true;
        $('volume-results').setAttribute('aria-busy', 'true');
        setLoading(true);
        $('volume-export').disabled = true;
        setStatus('Consultando la fuente postal…');
        const query = new URLSearchParams({ scope, from: period.from, to: period.to, comparison: mode === 'monthly' ? 'year' : 'period' });
        const operator = form.elements.operator?.value;
        const office = form.elements.office?.value;
        const cdsOffice = form.elements.cds_office?.value;
        const service = form.elements.service?.value;
        if (operator) query.set('operator', operator);
        if (office !== undefined && office !== '') query.set('office', office);
        if (cdsOffice !== undefined && cdsOffice !== '') query.set('cds_office', cdsOffice);
        if (service) query.set('service', service);
        Object.entries(chartFilters).forEach(([key, value]) => { if (value) query.set(key, value); });
        try {
            const response = await fetch(`${root.dataset.url}?${query.toString()}`, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controller.signal });
            if (!response.ok) throw new Error(response.status === 403 ? 'Tu cuenta no tiene permiso para consultar este reporte.' : 'No se pudo consultar el reporte. Verifica la conexión e intenta nuevamente.');
            const data = await response.json();
            if (scope !== 'cds') {
                setOptions(form.querySelector('[data-catalog="offices"]'), data.catalog?.offices || [], 'Todas las oficinas');
                const serviceOptions = data.catalog?.services?.length ? data.catalog.services : (data.cds?.services || []).map(row => ({ code: row.code, name: row.code }));
                setOptions(form.querySelector('[data-catalog="services"]'), serviceOptions, 'Todos los servicios');
            }
            if (scope === 'all') {
                setOptions(form.querySelector('[data-catalog="cds-offices"]'), data.cds?.offices || [], 'Todas las oficinas CDS');
            } else if (scope === 'cds') {
                setOptions(form.querySelector('[data-catalog="offices"]'), data.cds?.offices || [], 'Todas las oficinas', form.elements.office?.value);
                const serviceOptions = (data.cds?.services || []).map(row => ({ code: row.code, name: row.code }));
                setOptions(form.querySelector('[data-catalog="services"]'), serviceOptions, 'Todos los servicios', form.elements.service?.value, false);
            }
            drawReport(data);
            setLoading(false);
        } catch (error) {
            if (error.name === 'AbortError') return;
            setLoading(false);
            $('volume-error').textContent = error.message || 'Error al consultar los datos.';
            $('volume-error').hidden = false;
            $('volume-results').setAttribute('aria-busy', 'false');
            setStatus('No fue posible actualizar el reporte');
        }
    }

    function exportCsv() {
        if (!exportRows.length) return;
        const quote = value => `"${String(value ?? '').replace(/"/g, '""')}"`;
        const csv = '\uFEFF' + [exportHeaders, ...exportRows].map(row => row.map(quote).join(';')).join('\r\n');
        const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
        const link = document.createElement('a');
        link.href = url;
        link.download = `reporte-${scope === 'all' ? 'ips-cds' : scope}-${mode}-${fromInput.value}-${toInput.value}.csv`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    }

    document.querySelectorAll('.volume-mode-tabs [data-mode]').forEach(button => button.addEventListener('click', () => {
        if (button.dataset.mode === mode) return;
        setMode(button.dataset.mode);
        load();
    }));
    form.addEventListener('submit', event => { event.preventDefault(); updateActiveFilters(); load(); });
    fromInput.addEventListener('change', load);
    toInput.addEventListener('change', load);
    form.elements.operator?.addEventListener('change', load);
    form.elements.office?.addEventListener('change', load);
    form.elements.cds_office?.addEventListener('change', load);
    form.elements.service?.addEventListener('change', () => { updateActiveFilters(); load(); });
    $('volume-refresh').addEventListener('click', load);
    $('volume-export').addEventListener('click', exportCsv);
    $('volume-clear-chart-filters')?.addEventListener('click', () => {
        Object.keys(chartFilters).forEach(key => delete chartFilters[key]);
        if (form.elements.service) form.elements.service.value = '';
        updateActiveFilters();
        load();
    });
    setMode(initialMode);
    updateActiveFilters();
    load();
})();
