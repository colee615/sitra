(() => {
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
