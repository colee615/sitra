document.addEventListener('DOMContentLoaded', () => {
    const panel = document.querySelector('[data-customs-bag]');
    if (!panel) return;

    const rows = [...panel.querySelectorAll('.postal-remittance-table tbody tr')];
    const search = panel.querySelector('#bag-package-search');
    const declarationFilter = panel.querySelector('#bag-declaration-filter');
    const visibleCount = panel.querySelector('#bag-visible-count');
    const selectedCount = panel.querySelector('#bag-selected-count');
    const submit = panel.querySelector('[data-build-manifest]');
    const selectionForm = panel.querySelector('[data-customs-selection]');

    const refreshRows = () => {
        const term = (search?.value || '').trim().toLocaleLowerCase();
        const declaration = declarationFilter?.value || 'all';
        let visible = 0;
        rows.forEach(row => {
            const matchesText = row.textContent.toLocaleLowerCase().includes(term);
            const matchesDeclaration = declaration === 'all' || row.dataset.cdsMatch === declaration;
            row.hidden = !(matchesText && matchesDeclaration);
            if (!row.hidden) visible++;
        });
        if (visibleCount) visibleCount.textContent = `${visible} paquete(s) visibles`;
    };

    const refreshSelection = () => {
        const checked = panel.querySelectorAll('input[name="seleccionados[]"]:checked').length;
        if (selectedCount) selectedCount.textContent = `${checked} seleccionado(s) · máximo 50`;
        if (submit) submit.disabled = checked === 0;
    };

    search?.addEventListener('input', refreshRows);
    declarationFilter?.addEventListener('change', refreshRows);
    selectionForm?.addEventListener('change', event => {
        if (event.target.matches('input[name="seleccionados[]"]')) {
            const checked = panel.querySelectorAll('input[name="seleccionados[]"]:checked');
            if (checked.length > 50) {
                event.target.checked = false;
                if (selectedCount) selectedCount.textContent = 'El máximo por remisión es 50 paquetes.';
            }
            refreshSelection();
        }
    });

    panel.querySelector('[data-select-all]')?.addEventListener('click', () => {
        const available = rows
            .filter(row => !row.hidden && row.dataset.hasDeclaration === 'yes')
            .map(row => row.querySelector('input[name="seleccionados[]"]'))
            .filter(Boolean);
        const currentlySelected = panel.querySelectorAll('input[name="seleccionados[]"]:checked');
        const clear = currentlySelected.length > 0 && currentlySelected.length === available.length;
        currentlySelected.forEach(input => { input.checked = false; });
        if (!clear) available.slice(0, 50).forEach(input => { input.checked = true; });
        if (selectedCount && available.length > 50 && !clear) {
            selectedCount.textContent = 'Se seleccionaron 50. El lote admite máximo 50 paquetes.';
        }
        refreshSelection();
    });

    refreshRows();
    refreshSelection();
});
