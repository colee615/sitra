(() => {
    'use strict';
    const content = document.querySelector('.content-wrapper');
    if (content) { content.id = 'main-content'; content.setAttribute('tabindex', '-1'); }
    const nav = document.querySelector('.main-header > .navbar-nav');
    if (nav) {
        const context = document.createElement('li');
        context.className = 'sitra-top-context';
        context.innerHTML = '<strong>CORREOS DE BOLIVIA</strong><span>/</span><span>Plataforma de gestión postal</span>';
        nav.append(context);
    }
    const sidebar = document.querySelector('.main-sidebar .sidebar');
    if (sidebar) {
        const note = document.createElement('div'); note.className = 'sitra-sidebar-note';
        note.innerHTML = '<strong>SITRA · Conectamos Bolivia</strong><span>Gestión postal y aduanera</span>';
        sidebar.append(note);
    }
    document.querySelectorAll('.table-responsive').forEach(table => {
        if (table.scrollWidth > table.clientWidth) { table.tabIndex = 0; table.setAttribute('aria-label', 'Tabla con desplazamiento horizontal'); }
    });
    let pendingForm = null;
    document.querySelectorAll('form[data-confirm]').forEach(form => form.addEventListener('submit', event => {
        if (form.dataset.confirmed === 'true') return;
        event.preventDefault(); pendingForm = form;
        document.getElementById('sitra-confirm-message').textContent = form.dataset.confirm;
        window.jQuery('#sitra-confirm-action').modal('show');
    }));
    document.getElementById('sitra-confirm-submit')?.addEventListener('click', () => {
        if (!pendingForm) return;
        pendingForm.dataset.confirmed = 'true'; pendingForm.requestSubmit();
    });
    window.jQuery?.('#sitra-confirm-action').on('hidden.bs.modal', () => { pendingForm = null; });
})();
