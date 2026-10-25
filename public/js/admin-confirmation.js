(function () {
    'use strict';

    const modal = document.getElementById('confirmModal');
    if (!modal) return;
    const title = document.getElementById('confirmModalTitle');
    const message = document.getElementById('confirmModalMessage');
    const accept = document.getElementById('confirmModalOk');
    const cancel = document.getElementById('confirmModalCancel');
    const queue = [];
    const pendingForms = new WeakSet();
    const pendingElements = new WeakSet();
    const approvedSubmissions = new WeakMap();
    const replayClicks = new WeakSet();
    let busySources = new WeakSet();
    let committedForms = new WeakSet();
    let current = null;

    function release(entry) {
        if (entry.source) busySources.delete(entry.source);
    }

    function pump() {
        if (current || !queue.length) return;
        current = queue.shift();
        current.previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        title.textContent = current.options.title || modal.dataset.defaultTitle;
        message.textContent = current.message || modal.dataset.defaultMessage;
        accept.textContent = current.options.accept || modal.dataset.defaultAccept;
        try {
            modal.showModal();
            cancel.focus({ preventScroll: true });
        } catch (error) {
            // An unavailable dialog must never authorize an action.
            console.error('Unable to display confirmation dialog', error);
            finish(false);
        }
    }

    function finish(accepted) {
        if (!current) return;
        const entry = current;
        current = null;
        if (modal.open) modal.close();
        document.body.style.overflow = entry.previousOverflow;
        if (entry.opener?.isConnected) entry.opener.focus({ preventScroll: true });
        let action;
        try {
            if (accepted && entry.callback) action = entry.callback();
        } catch (error) {
            console.error('Confirmed action failed', error);
        }
        // Keep a callback's source locked until its asynchronous action completes.
        Promise.resolve(action).catch(error => console.error('Confirmed action failed', error))
            .finally(() => release(entry));
        entry.resolve(accepted);
        queueMicrotask(pump);
    }

    // Existing callbacks remain supported; callers may also await a boolean.
    window.customConfirm = function (text, onAccepted, options = {}) {
        const source = options.source instanceof Element ? options.source : null;
        if (source && busySources.has(source)) return Promise.resolve(false);
        if (source) busySources.add(source);
        return new Promise(resolve => {
            queue.push({ message: text, callback: typeof onAccepted === 'function' ? onAccepted : null,
                options, source, opener: source || document.activeElement, resolve });
            pump();
        });
    };

    accept.addEventListener('click', event => {
        if (event.detail > 1) return;
        finish(true);
    });
    cancel.addEventListener('click', () => finish(false));
    modal.addEventListener('cancel', event => {
        event.preventDefault();
        finish(false);
    });
    modal.addEventListener('close', () => { if (current && !modal.open) finish(false); });
    modal.addEventListener('click', event => {
        if (event.target !== modal) return;
        const bounds = modal.getBoundingClientRect();
        if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) finish(false);
    });

    const optionsFor = source => ({ title: source.dataset.confirmTitle, accept: source.dataset.confirmAccept });
    const stop = event => { event.preventDefault(); event.stopImmediatePropagation(); };

    function rememberSubmission(event, form) {
        queueMicrotask(() => {
            const target = event.submitter?.getAttribute('formtarget') || form.target;
            if (!event.defaultPrevented && form.method.toLowerCase() === 'post' && (!target || target === '_self')) committedForms.add(form);
        });
    }

    function resumeSubmission(form, submitter) {
        if (!form.isConnected || (submitter && (!submitter.isConnected || submitter.disabled || submitter.form !== form))) return;
        let proxy = null;
        let effectiveSubmitter = submitter;
        if (typeof form.requestSubmit !== 'function') {
            // Clicking a temporary submitter retains validation and the chosen button's payload.
            proxy = document.createElement('button');
            proxy.type = 'submit';
            proxy.hidden = true;
            if (submitter) {
                for (const name of ['name', 'value', 'formaction', 'formmethod', 'formenctype', 'formtarget', 'formnovalidate']) {
                    if (submitter.hasAttribute(name)) proxy.setAttribute(name, submitter.getAttribute(name));
                }
            }
            form.append(proxy);
            effectiveSubmitter = proxy;
        }
        const permission = { submitter: effectiveSubmitter };
        approvedSubmissions.set(form, permission);
        try {
            if (proxy) proxy.click();
            else if (submitter) form.requestSubmit(submitter);
            else form.requestSubmit();
        } finally {
            // No persistent flag: validation failures and prevented submissions must ask again.
            if (approvedSubmissions.get(form) === permission) approvedSubmissions.delete(form);
            proxy?.remove();
        }
    }

    document.addEventListener('submit', event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        if (committedForms.has(form)) { stop(event); return; }
        const submitter = event.submitter || null;
        const permission = approvedSubmissions.get(form);
        if (permission && permission.submitter === submitter) {
            approvedSubmissions.delete(form);
            rememberSubmission(event, form);
            return;
        }
        const source = submitter?.hasAttribute('data-confirm') ? submitter : form.hasAttribute('data-confirm') ? form : null;
        if (!source || event.defaultPrevented) return;
        stop(event);
        if (pendingForms.has(form)) return;
        pendingForms.add(form);
        window.customConfirm(source.dataset.confirm, null, { ...optionsFor(source), source: form }).then(accepted => {
            if (accepted) resumeSubmission(form, submitter);
        }).finally(() => pendingForms.delete(form));
    }, true);

    document.addEventListener('click', event => {
        if (!(event.target instanceof Element)) return;
        const element = event.target.closest('[data-confirm]');
        if (!element || element instanceof HTMLFormElement) return;
        if (replayClicks.has(element)) { replayClicks.delete(element); return; }
        // Submit buttons are handled at submission, after native field validation.
        if (element.form && ['submit', 'image'].includes(element.type)) return;
        if (event.defaultPrevented || element.disabled) return;
        stop(event);
        if (pendingElements.has(element)) return;
        pendingElements.add(element);
        window.customConfirm(element.dataset.confirm, null, { ...optionsFor(element), source: element }).then(accepted => {
            if (!accepted || !element.isConnected || element.disabled) return;
            replayClicks.add(element);
            try { element.click(); } finally { replayClicks.delete(element); }
        }).finally(() => pendingElements.delete(element));
    }, true);

    window.addEventListener('pagehide', () => {
        for (const entry of queue.splice(0)) { release(entry); entry.resolve(false); }
        finish(false);
    });
    window.addEventListener('pageshow', event => {
        if (!event.persisted) return;
        committedForms = new WeakSet();
        busySources = new WeakSet();
    });
})();
