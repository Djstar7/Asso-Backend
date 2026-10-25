(() => {
    const widgets = new WeakMap();
    const pending = new WeakSet();
    const submissions = new WeakMap();
    const active = new Map();
    const canAssignFiles = typeof DataTransfer !== 'undefined';
    let sequence = 0;
    const filesOf = input => Array.from(input.files || []);
    const key = file => `${file.name}\u0000${file.size}\u0000${file.lastModified}\u0000${file.type}`;
    const formatSize = size => size >= 1048576 ? `${(size / 1048576).toFixed(1)} Mo` : `${Math.ceil(size / 1024)} Ko`;

    function assign(input, files) {
        const transfer = new DataTransfer();
        files.forEach(file => transfer.items.add(file));
        input.files = transfer.files;
    }

    function accepts(input, file) {
        const rules = input.accept.toLowerCase().split(',').map(value => value.trim()).filter(Boolean);
        return !rules.length || rules.some(rule => rule.startsWith('.')
            ? file.name.toLowerCase().endsWith(rule)
            : rule.endsWith('/*') ? file.type.toLowerCase().startsWith(rule.slice(0, -1)) : file.type.toLowerCase() === rule);
    }

    function enhance(input) {
        if (!canAssignFiles || widgets.has(input) || input.hasAttribute('data-custom-file-upload')) return;
        const wrapper = document.createElement('div');
        wrapper.className = 'admin-file-upload';
        const zone = document.createElement('div');
        zone.className = 'admin-file-upload__zone';
        const icon = document.createElement('i');
        icon.className = 'fas fa-cloud-arrow-up admin-file-upload__icon';
        icon.setAttribute('aria-hidden', 'true');
        const title = document.createElement('span');
        title.className = 'admin-file-upload__title';
        const browse = document.createElement('span');
        browse.className = 'admin-file-upload__browse';
        browse.textContent = 'parcourez vos fichiers';
        title.append('Glissez-déposez ici ou ', browse);
        const hint = document.createElement('span');
        hint.className = 'admin-file-upload__hint';
        hint.id = `admin-file-help-${++sequence}`;
        const list = document.createElement('ul');
        list.className = 'admin-file-upload__files';
        list.setAttribute('aria-label', 'Fichiers sélectionnés');
        const error = document.createElement('p');
        error.className = 'admin-file-upload__error';
        error.id = `admin-file-error-${sequence}`;
        error.setAttribute('role', 'alert');
        error.hidden = true;
        input.setAttribute('aria-describedby', [input.getAttribute('aria-describedby'), hint.id, error.id].filter(Boolean).join(' '));
        if (!input.labels?.length && !input.hasAttribute('aria-label')) input.setAttribute('aria-label', 'Choisir un fichier');
        input.before(wrapper);
        zone.append(icon, title, hint, input);
        wrapper.append(zone, list, error);
        input.classList.add('admin-file-upload__input');
        let selected = filesOf(input);
        let internalChange = false;
        function notifyChange() {
            internalChange = true;
            try { input.dispatchEvent(new Event('change', { bubbles: true })); }
            finally { internalChange = false; }
        }

        function render() {
            selected = filesOf(input);
            wrapper.classList.toggle('is-disabled', input.matches(':disabled'));
            hint.textContent = `${input.multiple ? 'Plusieurs fichiers possibles' : 'Un fichier'}${input.accept ? ' · ' + input.accept : ' · Tous formats'}`;
            list.replaceChildren();
            selected.forEach((file, index) => {
                const item = document.createElement('li');
                item.className = 'admin-file-upload__file';
                const name = document.createElement('span');
                name.className = 'admin-file-upload__name';
                name.textContent = file.name;
                const size = document.createElement('span');
                size.className = 'admin-file-upload__size';
                size.textContent = formatSize(file.size);
                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'admin-file-upload__remove';
                remove.textContent = '✕';
                remove.setAttribute('aria-label', `Retirer ${file.name}`);
                remove.disabled = input.matches(':disabled');
                remove.addEventListener('click', event => {
                    event.preventDefault();
                    if (input.matches(':disabled')) return;
                    assign(input, filesOf(input).filter((_, current) => current !== index));
                    notifyChange();
                    input.focus();
                });
                item.append(name, size, remove);
                list.append(item);
            });
        }

        function normalize(incoming, previous) {
            const rejected = incoming.filter(file => !accepts(input, file));
            let message = rejected.length ? 'Format non accepté : ' + rejected.map(file => file.name).join(', ') : '';
            if (!input.multiple && incoming.length > 1) message = 'Déposez un seul fichier dans ce champ.';
            if (message) {
                assign(input, previous);
                error.textContent = message;
                error.hidden = false;
                return false;
            }
            const values = input.multiple ? [...previous, ...incoming] : incoming;
            const unique = new Map(values.map(file => [key(file), file]));
            assign(input, Array.from(unique.values()));
            error.hidden = true;
            return true;
        }

        // Capture runs before existing inline previews and automatic video uploads.
        input.addEventListener('change', event => {
            if (internalChange) { queueMicrotask(render); return; }
            const previous = selected;
            if (!normalize(filesOf(input), input.multiple ? previous : [])) {
                // Keep a previous valid choice even when an invalid picker selection is made.
                assign(input, previous);
                event.stopImmediatePropagation();
            }
            queueMicrotask(render);
        }, true);
        ['dragenter', 'dragover'].forEach(type => zone.addEventListener(type, event => {
            event.preventDefault();
            if (!input.matches(':disabled')) wrapper.classList.add('is-dragging');
        }));
        zone.addEventListener('dragleave', event => {
            if (!zone.contains(event.relatedTarget)) wrapper.classList.remove('is-dragging');
        });
        zone.addEventListener('drop', event => {
            event.preventDefault();
            wrapper.classList.remove('is-dragging');
            if (input.matches(':disabled') || !event.dataTransfer?.files.length) return;
            if (normalize(Array.from(event.dataTransfer.files), selected)) notifyChange();
            render();
        });
        widgets.set(input, { render, reset() { error.hidden = true; render(); } });
        render();
    }

    function scan(root) {
        if (root.matches?.('input[type="file"]')) enhance(root);
        root.querySelectorAll?.('input[type="file"]').forEach(enhance);
    }

    scan(document);
    new MutationObserver(records => {
        records.forEach(record => {
            if (record.type === 'childList') record.addedNodes.forEach(scan);
            else if (record.target.matches('input[type="file"]')) widgets.get(record.target)?.render();
            else if (record.target.matches('fieldset')) record.target.querySelectorAll('input[type="file"]').forEach(input => widgets.get(input)?.render());
        });
    }).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled', 'accept', 'multiple'] });
    document.addEventListener('reset', event => setTimeout(() => {
        if (event.defaultPrevented) return;
        Array.from(event.target.elements).forEach(input => widgets.get(input)?.reset());
        event.target.querySelector('.admin-upload-status')?.setAttribute('hidden', '');
        submissions.delete(event.target);
    }, 0));
    document.addEventListener('reset', event => { if (pending.has(event.target)) event.preventDefault(); }, true);
    ['input', 'change'].forEach(type => document.addEventListener(type, event => {
        if (event.target.form && !pending.has(event.target.form)) submissions.delete(event.target.form);
    }));
    window.addEventListener('pageshow', event => {
        if (event.persisted) active.forEach(restore => restore());
    });

    function statusFor(form) {
        let root = form.querySelector('.admin-upload-status');
        if (!root) {
            root = document.createElement('div');
            root.className = 'admin-upload-status';
            root.tabIndex = -1;
            root.setAttribute('role', 'status');
            root.setAttribute('aria-live', 'polite');
            root.append(document.createElement('span'), document.createElement('progress'));
            root.lastChild.max = 100;
            root.lastChild.setAttribute('aria-label', 'Progression de l’envoi des fichiers');
            form.append(root);
        }
        root.hidden = false;
        root.classList.remove('is-error');
        root.lastChild.hidden = false;
        return root;
    }

    document.addEventListener('submit', event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || event.defaultPrevented) return;
        if (pending.has(form)) { event.preventDefault(); return; }
        const button = event.submitter;
        const method = (button?.getAttribute('formmethod') || form.method).toLowerCase();
        const target = button?.getAttribute('formtarget') || form.target;
        const enctype = button?.getAttribute('formenctype') || form.enctype;
        const action = new URL(button?.getAttribute('formaction') || form.action, location.href);
        const inputs = Array.from(form.elements).filter(input => input.type === 'file' && input.name && !input.matches(':disabled') && input.files.length);
        if (!inputs.length || method !== 'post' || enctype !== 'multipart/form-data' || (target && target !== '_self') || action.origin !== location.origin || !['http:', 'https:'].includes(action.protocol) || form.hasAttribute('data-native-upload')) return;
        let data;
        try {
            data = new FormData(form);
            if (button?.name) data.append(button.name, button.value);
            if (!submissions.has(form) && globalThis.crypto?.randomUUID) submissions.set(form, crypto.randomUUID());
            if (submissions.has(form)) data.set('_submission_id', submissions.get(form));
        } catch (_) { return; }
        event.preventDefault();
        pending.add(form);
        const status = statusFor(form);
        const text = status.firstChild;
        const progress = status.lastChild;
        progress.value = 0;
        text.textContent = 'Envoi des fichiers… 0 %';
        status.scrollIntoView({ block: 'nearest', behavior: 'auto' });
        const locked = Array.from(form.elements).filter(control => 'disabled' in control && !control.disabled);
        locked.forEach(control => { control.disabled = true; });
        form.setAttribute('aria-busy', 'true');
        function restore() {
            pending.delete(form);
            active.delete(form);
            locked.forEach(control => { control.disabled = false; });
            form.removeAttribute('aria-busy');
        }
        const xhr = new XMLHttpRequest();
        active.set(form, () => { xhr.abort(); restore(); });
        let navigating = false;
        function fail(message) {
            status.classList.add('is-error');
            progress.hidden = true;
            text.textContent = message;
            status.focus();
        }
        try {
            xhr.open('POST', action.href);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('X-Admin-Upload', '1');
            xhr.timeout = 600000;
        } catch (_) {
            fail('L’envoi n’a pas pu démarrer. Vos fichiers sont conservés ; vous pouvez réessayer.');
            restore();
            return;
        }
        xhr.upload.onprogress = update => {
            if (!update.lengthComputable) {
                progress.removeAttribute('value');
                text.textContent = 'Envoi des fichiers en cours…';
                return;
            }
            const percentage = Math.round(update.loaded / update.total * 100);
            progress.value = percentage;
            text.textContent = percentage === 100 ? 'Fichiers envoyés · Traitement par le serveur…' : `Envoi des fichiers… ${percentage} %`;
        };
        xhr.upload.onload = () => {
            progress.value = 100;
            text.textContent = 'Fichiers envoyés · Traitement par le serveur…';
        };
        xhr.onload = () => {
            let result;
            try { result = JSON.parse(xhr.responseText); } catch (_) { result = null; }
            if (xhr.status >= 200 && xhr.status < 300 && result?.redirect) {
                let destination;
                try { destination = new URL(result.redirect, location.href); }
                catch (_) { return fail('La destination de retour est invalide. Rechargez la page pour vérifier l’enregistrement.'); }
                if (destination.origin !== location.origin || !['http:', 'https:'].includes(destination.protocol)) return fail('La destination de retour est invalide. Rechargez la page pour vérifier l’enregistrement.');
                try {
                    location.assign(destination.href);
                    navigating = true;
                    text.textContent = 'Envoi terminé · Ouverture de la page…';
                } catch (_) {
                    fail('L’enregistrement est terminé, mais la page suivante n’a pas pu s’ouvrir. Actualisez pour vérifier.');
                }
                return;
            }
            const messages = {
                401: 'Votre session a expiré. Reconnectez-vous avant de réessayer.',
                403: 'Vous n’avez pas les droits nécessaires pour cet enregistrement.',
                404: 'Cet élément n’existe plus. Actualisez la page.',
                409: 'Un enregistrement est en cours ou cet élément a changé. Vérifiez son état avant de réessayer.',
                413: 'L’envoi dépasse la taille autorisée par le serveur. Choisissez des fichiers plus légers.',
                419: 'Votre session a expiré. Rechargez la page avant de réessayer.',
                422: 'Vérifiez les informations du formulaire.',
                429: 'Trop de tentatives. Patientez avant de réessayer.',
                503: 'Le service est temporairement indisponible. Réessayez plus tard.',
            };
            const errors = Object.values(result?.errors || {}).flat().filter(value => typeof value === 'string');
            fail(errors.length ? errors.join(' ') : (typeof result?.message === 'string' ? result.message : messages[xhr.status]) || (xhr.status >= 500 ? 'Le serveur n’a pas pu terminer l’enregistrement. Vérifiez son état avant de réessayer.' : 'Réponse inattendue du serveur. Vérifiez l’enregistrement avant de réessayer.'));
        };
        xhr.onerror = () => fail('Connexion interrompue. L’enregistrement peut avoir abouti : vérifiez son état avant de réessayer.');
        xhr.ontimeout = () => fail('Le délai d’attente a été dépassé. Vérifiez l’enregistrement avant de réessayer.');
        xhr.onabort = () => fail('L’envoi a été interrompu. Vous pouvez réessayer.');
        xhr.onloadend = () => {
            if (navigating) return;
            restore();
        };
        try { xhr.send(data); }
        catch (_) {
            fail('L’envoi n’a pas pu démarrer. Vos fichiers sont conservés ; vous pouvez réessayer.');
            restore();
        }
    });
})();
