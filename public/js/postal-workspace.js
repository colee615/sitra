(() => {
    // Preserve links to delivery, EDI and CDS audit sections inside details.
    const revealSection = () => {
        let id;
        try { id = decodeURIComponent(window.location.hash.slice(1)); } catch { return; }
        const target = document.getElementById(id);
        if (!target) return;
        let details = target.closest('details');
        while (details) {
            details.open = true;
            details = details.parentElement?.closest('details');
        }
    };
    revealSection();
    window.addEventListener('hashchange', revealSection);

    let printSections = [];
    window.addEventListener('beforeprint', () => {
        printSections = Array.from(document.querySelectorAll('details.postal-subsection'), node => ({node, open: node.open}));
        printSections.forEach(section => { section.node.open = true; });
    });
    window.addEventListener('afterprint', () => {
        printSections.forEach(section => { section.node.open = section.open; });
        printSections = [];
    });

    const input = document.getElementById('event-filter');
    if (!input) return;
    const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('es');
    const events = Array.from(document.querySelectorAll('#postal-timeline .postal-event'), node => ({node, text: normalize(node.textContent)}));
    const count = document.getElementById('event-filter-count');
    input.addEventListener('input', () => {
        const query = normalize(input.value.trim());
        let visible = 0;
        events.forEach(event => {
            const matches = event.text.includes(query);
            event.node.classList.toggle('d-none', !matches);
            if (matches) visible++;
        });
        count.textContent = `${visible} de ${events.length} movimientos visibles`;
    });
})();
