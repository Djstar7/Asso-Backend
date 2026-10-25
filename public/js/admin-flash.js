(function () {
    'use strict';
    const banner = document.getElementById('adminFlash');
    if (!banner) return;
    const message = banner.querySelector('[data-flash-message]');
    const icon = banner.querySelector('[data-flash-icon]');
    let timer = null;
    let generation = 0;

    function dismiss() {
        clearTimeout(timer);
        generation++;
        banner.hidden = true;
        message.replaceChildren();
    }
    function schedule() {
        clearTimeout(timer);
        const current = ++generation;
        if (!banner.hidden && banner.dataset.flashType === 'success') {
            timer = setTimeout(() => { if (current === generation) dismiss(); }, 5000);
        }
    }
    function show(text, type) {
        // One reusable slot: a new message replaces the previous one and its timer.
        message.textContent = String(text ?? '');
        banner.dataset.flashType = type;
        banner.setAttribute('role', type === 'error' ? 'alert' : 'status');
        banner.setAttribute('aria-live', type === 'error' ? 'assertive' : 'polite');
        icon.className = 'fas ' + (type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle');
        banner.hidden = false;
        schedule();
    }
    banner.querySelector('[data-flash-dismiss]').addEventListener('click', dismiss);
    window.adminFlash = { success: text => show(text, 'success'), error: text => show(text, 'error'), dismiss };
    if (document.readyState === 'complete') schedule();
    else window.addEventListener('load', schedule, { once: true });
    window.addEventListener('pageshow', event => {
        if (event.persisted && banner.dataset.flashType === 'success') dismiss();
    });
})();
