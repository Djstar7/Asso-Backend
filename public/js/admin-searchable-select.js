(() => {
    const enhanced = new WeakSet();
    const layoutClassPattern =
        /^(?:(?:sm|md|lg|xl|2xl):)?(?:w-|min-w-|max-w-|h-|min-h-|max-h-|col-span-|row-span-|flex-|basis-|order-|self-|mt-|mb-|ml-|mr-|mx-|my-|grow$|shrink$|block$|inline$|inline-block$|inline-flex$)/;

    function normalize(value) {
        return value
            .toLocaleLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .trim();
    }

    function getAccessibleLabel(select) {
        const label = select.labels?.[0]?.textContent.trim();
        return label || select.getAttribute('aria-label') || 'Sélectionner une option';
    }

    function enhance(select) {
        if (enhanced.has(select) || select.closest('.admin-searchable-select')) return;
        enhanced.add(select);

        const wrapper = document.createElement('div');
        wrapper.className = 'admin-searchable-select';
        const layoutClasses = [];
        select.classList.forEach((className) => {
            if (layoutClassPattern.test(className)) layoutClasses.push(className);
        });
        if (layoutClasses.length) wrapper.classList.add(...layoutClasses);

        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = `admin-searchable-select__trigger ${Array.from(
            select.classList,
        )
            .filter((className) => !layoutClassPattern.test(className))
            .join(' ')}`;
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');
        trigger.setAttribute('aria-label', getAccessibleLabel(select));

        const selectedLabel = document.createElement('span');
        selectedLabel.className = 'admin-searchable-select__trigger-label';
        const arrow = document.createElement('span');
        arrow.setAttribute('aria-hidden', 'true');
        arrow.textContent = '▾';
        trigger.append(selectedLabel, arrow);

        const panel = document.createElement('div');
        panel.className = 'admin-searchable-select__panel';
        panel.hidden = true;

        const search = document.createElement('input');
        search.type = 'search';
        search.className = 'admin-searchable-select__search';
        search.placeholder = 'Rechercher…';
        search.autocomplete = 'off';
        search.setAttribute('aria-label', 'Rechercher dans la liste');

        const optionsList = document.createElement('div');
        optionsList.className = 'admin-searchable-select__options';
        optionsList.setAttribute('role', 'listbox');
        optionsList.setAttribute('aria-multiselectable', String(select.multiple));
        panel.append(search, optionsList);

        select.insertAdjacentElement('beforebegin', wrapper);
        wrapper.append(select, trigger, panel);
        select.classList.add('admin-searchable-select__native');
        select.tabIndex = -1;
        select.setAttribute('aria-hidden', 'true');

        function updateLabel() {
            const selected = Array.from(select.selectedOptions);
            if (select.multiple) {
                selectedLabel.textContent = selected.length
                    ? selected.map((option) => option.textContent.trim()).join(', ')
                    : 'Sélectionner…';
            } else {
                selectedLabel.textContent =
                    selected[0]?.textContent.trim() ||
                    select.getAttribute('placeholder') ||
                    'Sélectionner…';
            }
            trigger.disabled = select.disabled;
            trigger.setAttribute('aria-required', String(select.required));
            trigger.setAttribute(
                'aria-label',
                `${getAccessibleLabel(select)}: ${selectedLabel.textContent}`,
            );
        }

        function renderOptions() {
            // Closed dropdowns need only their selected label, not a copy of every option.
            if (panel.hidden) {
                optionsList.replaceChildren();
                return;
            }
            const query = normalize(search.value);
            const fragment = document.createDocumentFragment();
            let visibleCount = 0;

            Array.from(select.options).forEach((option) => {
                const text = option.textContent.trim();
                const group = option.parentElement instanceof HTMLOptGroupElement
                    ? option.parentElement.label
                    : '';
                if (query && !normalize(`${group} ${text}`).includes(query)) return;
                visibleCount++;

                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'admin-searchable-select__option';
                item.setAttribute('role', 'option');
                item.setAttribute('aria-selected', String(option.selected));
                item.textContent = text || '—';
                item.disabled =
                    option.disabled ||
                    (option.parentElement instanceof HTMLOptGroupElement &&
                        option.parentElement.disabled);
                item.addEventListener('click', () => {
                    if (select.multiple) option.selected = !option.selected;
                    else option.selected = true;
                    select.dispatchEvent(new Event('input', { bubbles: true }));
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                    updateLabel();
                    if (select.multiple) renderOptions();
                    else close();
                });
                fragment.append(item);
            });

            if (visibleCount === 0) {
                const empty = document.createElement('p');
                empty.className = 'admin-searchable-select__empty';
                empty.textContent = 'Aucun résultat';
                fragment.append(empty);
            }

            optionsList.replaceChildren(fragment);
        }

        function open() {
            if (trigger.disabled) return;
            panel.hidden = false;
            trigger.setAttribute('aria-expanded', 'true');
            positionPanel();
            window.addEventListener('resize', positionPanel);
            window.addEventListener('scroll', positionPanel, true);
            renderOptions();
            search.focus();
        }

        function positionPanel() {
            const rect = trigger.getBoundingClientRect();
            const margin = 16;
            const width = Math.min(
                Math.max(rect.width, 224),
                window.innerWidth - margin * 2,
            );
            const left = Math.min(
                Math.max(rect.left, margin),
                window.innerWidth - width - margin,
            );
            const below = window.innerHeight - rect.bottom;
            const placeAbove = below < 280 && rect.top > below;
            const top = placeAbove
                ? Math.max(margin, rect.top - Math.min(280, rect.top - margin))
                : Math.min(rect.bottom + 6, window.innerHeight - margin);

            panel.style.left = `${left}px`;
            panel.style.top = `${top}px`;
            panel.style.width = `${width}px`;
        }

        function close() {
            panel.hidden = true;
            trigger.setAttribute('aria-expanded', 'false');
            window.removeEventListener('resize', positionPanel);
            window.removeEventListener('scroll', positionPanel, true);
            search.value = '';
            renderOptions();
        }

        wrapper.closeDropdown = close;
        trigger.addEventListener('click', () => {
            if (panel.hidden) open();
            else close();
        });
        trigger.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowDown' || event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                open();
            }
        });
        search.addEventListener('input', renderOptions);
        search.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                close();
                trigger.focus();
            }
        });
        select.addEventListener('change', () => {
            updateLabel();
            renderOptions();
        });
        select.addEventListener('invalid', () => {
            trigger.focus();
            open();
        });
        select.form?.addEventListener('reset', () => {
            requestAnimationFrame(() => {
                updateLabel();
                renderOptions();
            });
        });

        const optionsObserver = new MutationObserver(() => {
            updateLabel();
            renderOptions();
        });
        optionsObserver.observe(select, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['disabled', 'label', 'selected', 'value'],
        });

        updateLabel();
        renderOptions();
    }

    const dateEnhanced = new WeakSet();
    const dateLayoutClassPattern =
        /^(?:(?:sm|md|lg|xl|2xl):)?(?:w-|min-w-|max-w-|h-|min-h-|max-h-|col-span-|row-span-|flex-|self-|mt-|mb-|ml-|mr-|mx-|my-|block$|inline$|inline-block$|inline-flex$)/;
    const weekdayNames = ['Lu', 'Ma', 'Me', 'Je', 'Ve', 'Sa', 'Di'];
    const dateFormatter = new Intl.DateTimeFormat('fr-FR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    });
    const monthFormatter = new Intl.DateTimeFormat('fr-FR', {
        month: 'long',
        year: 'numeric',
    });

    function dateString(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    function dateFromIso(value) {
        const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
        if (!match) return null;
        const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
        return dateString(date) === value ? date : null;
    }

    function parseDate(value) {
        const trimmed = value.trim();
        if (!trimmed) return null;
        const iso = dateFromIso(trimmed);
        if (iso) return iso;
        const localized = /^(\d{1,2})[/. -](\d{1,2})[/. -](\d{4})$/.exec(trimmed);
        if (!localized) return null;
        const date = new Date(
            Number(localized[3]),
            Number(localized[2]) - 1,
            Number(localized[1]),
        );
        if (
            date.getFullYear() !== Number(localized[3]) ||
            date.getMonth() !== Number(localized[2]) - 1 ||
            date.getDate() !== Number(localized[1])
        ) {
            return null;
        }
        return date;
    }

    function formatDate(value) {
        const date = dateFromIso(value);
        return date ? dateFormatter.format(date) : '';
    }

    function enhanceDate(input) {
        if (
            dateEnhanced.has(input) ||
            input.closest('.admin-date-picker') ||
            !['date', 'datetime-local'].includes(input.type)
        ) {
            return;
        }
        dateEnhanced.add(input);

        const isDateTime = input.type === 'datetime-local';
        const classes = Array.from(input.classList);
        const layoutClasses = classes.filter((name) => dateLayoutClassPattern.test(name));
        const fieldClasses = classes.filter((name) => !dateLayoutClassPattern.test(name));
        const wrapper = document.createElement('div');
        wrapper.className = 'admin-date-picker';
        if (layoutClasses.length) wrapper.classList.add(...layoutClasses);

        const dateInput = document.createElement('input');
        dateInput.type = 'text';
        dateInput.inputMode = 'numeric';
        dateInput.autocomplete = 'off';
        dateInput.placeholder = 'JJ/MM/AAAA';
        dateInput.className = `admin-date-picker__date ${fieldClasses.join(' ')}`;
        if (input.id) dateInput.id = `${input.id}-display`;
        dateInput.setAttribute('aria-label', getAccessibleLabel(input));
        dateInput.setAttribute('aria-haspopup', 'dialog');
        dateInput.setAttribute('aria-expanded', 'false');

        let timeInput = null;
        if (isDateTime) {
            timeInput = document.createElement('input');
            timeInput.type = 'time';
            timeInput.className = `admin-date-picker__time ${fieldClasses.join(' ')}`;
            timeInput.setAttribute('aria-label', `${getAccessibleLabel(input)} — heure`);
            timeInput.required = input.required;
            if (input.step) timeInput.step = input.step;
        }

        const calendar = document.createElement('div');
        calendar.className = 'admin-date-picker__calendar';
        calendar.hidden = true;
        calendar.setAttribute('role', 'dialog');
        calendar.setAttribute('aria-label', 'Choisir une date');

        const header = document.createElement('div');
        header.className = 'admin-date-picker__header';
        const previous = document.createElement('button');
        previous.type = 'button';
        previous.className = 'admin-date-picker__nav';
        previous.setAttribute('aria-label', 'Mois précédent');
        previous.textContent = '‹';
        const monthTitle = document.createElement('div');
        monthTitle.className = 'admin-date-picker__month';
        const next = document.createElement('button');
        next.type = 'button';
        next.className = 'admin-date-picker__nav';
        next.setAttribute('aria-label', 'Mois suivant');
        next.textContent = '›';
        header.append(previous, monthTitle, next);

        const week = document.createElement('div');
        week.className = 'admin-date-picker__week';
        weekdayNames.forEach((name) => {
            const day = document.createElement('span');
            day.textContent = name;
            week.append(day);
        });

        const days = document.createElement('div');
        days.className = 'admin-date-picker__days';
        const actions = document.createElement('div');
        actions.className = 'admin-date-picker__actions';
        const clear = document.createElement('button');
        clear.type = 'button';
        clear.className = 'admin-date-picker__action';
        clear.textContent = 'Effacer';
        const today = document.createElement('button');
        today.type = 'button';
        today.className = 'admin-date-picker__action';
        today.textContent = 'Aujourd’hui';
        actions.append(clear, today);
        calendar.append(header, week, days, actions);

        input.insertAdjacentElement('afterend', wrapper);
        wrapper.append(input, dateInput);
        if (timeInput) wrapper.append(timeInput);
        wrapper.append(calendar);
        input.classList.add('admin-date-picker__native');
        input.tabIndex = -1;
        input.setAttribute('aria-hidden', 'true');

        let displayedMonth = dateFromIso(input.value) || new Date();
        const isRequired = input.required;
        let syncingFromCustom = false;
        dateInput.required = isRequired;
        input.labels?.forEach((label) => {
            if (label.htmlFor === input.id && dateInput.id) {
                label.htmlFor = dateInput.id;
            }
        });
        displayedMonth = new Date(
            displayedMonth.getFullYear(),
            displayedMonth.getMonth(),
            1,
        );

        function isAllowed(date, time = timeInput?.value || '00:00') {
            const value = dateString(date);
            if (input.min && value < input.min.slice(0, 10)) return false;
            if (input.max && value > input.max.slice(0, 10)) return false;
            if (isDateTime && input.min?.slice(0, 10) === value && time < input.min.slice(11, 16)) {
                return false;
            }
            if (isDateTime && input.max?.slice(0, 10) === value && time > input.max.slice(11, 16)) {
                return false;
            }
            return true;
        }

        function defaultTimeFor(date) {
            const value = dateString(date);
            let time = timeInput?.value || '00:00';
            if (isDateTime && input.min?.slice(0, 10) === value) {
                const minTime = input.min.slice(11, 16) || '00:00';
                if (time < minTime) time = minTime;
            }
            if (isDateTime && input.max?.slice(0, 10) === value) {
                const maxTime = input.max.slice(11, 16) || '23:59';
                if (time > maxTime) time = maxTime;
            }
            return time;
        }

        function updateValidity() {
            const parsed = parseDate(dateInput.value);
            dateInput.required = isRequired;
            if (timeInput) {
                const value = parsed ? dateString(parsed) : '';
                timeInput.min =
                    input.min?.slice(0, 10) === value
                        ? input.min.slice(11, 16)
                        : '';
                timeInput.max =
                    input.max?.slice(0, 10) === value
                        ? input.max.slice(11, 16)
                        : '';
            }
            dateInput.setCustomValidity(
                !dateInput.value.trim() && isRequired
                    ? 'Choisissez une date.'
                    : dateInput.value.trim() &&
                          (!parsed || !isAllowed(parsed, defaultTimeFor(parsed)))
                    ? 'Saisissez une date valide et comprise dans les limites autorisées.'
                    : '',
            );
            if (timeInput) {
                timeInput.required = isRequired && Boolean(parsed);
                timeInput.setCustomValidity(
                    isRequired && parsed && !timeInput.value
                        ? 'Choisissez une heure.'
                        : parsed &&
                              timeInput.value &&
                              !isAllowed(parsed, timeInput.value)
                        ? 'Choisissez une heure comprise dans les limites autorisées.'
                        : '',
                );
            }
            return parsed;
        }

        function validateAndSync() {
            const selectedDate = parseDate(dateInput.value);
            if (selectedDate && timeInput && !timeInput.value) {
                timeInput.value = defaultTimeFor(selectedDate);
            }
            const parsed = updateValidity();
            if (!dateInput.value.trim()) {
                input.value = '';
            } else if (parsed && isAllowed(parsed, defaultTimeFor(parsed))) {
                const time = defaultTimeFor(parsed);
                input.value = isDateTime
                    ? (timeInput?.value ? `${dateString(parsed)}T${time}` : '')
                    : dateString(parsed);
            } else {
                input.value = '';
            }
            syncingFromCustom = true;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
            syncingFromCustom = false;
        }

        function renderCalendar() {
            monthTitle.textContent = monthFormatter.format(displayedMonth);
            const firstDay = new Date(
                displayedMonth.getFullYear(),
                displayedMonth.getMonth(),
                1,
            );
            const offset = (firstDay.getDay() + 6) % 7;
            const daysInMonth = new Date(
                displayedMonth.getFullYear(),
                displayedMonth.getMonth() + 1,
                0,
            ).getDate();
            const cells = [];

            for (let i = 0; i < offset; i++) {
                const blank = document.createElement('span');
                blank.setAttribute('aria-hidden', 'true');
                cells.push(blank);
            }
            for (let number = 1; number <= daysInMonth; number++) {
                const date = new Date(
                    displayedMonth.getFullYear(),
                    displayedMonth.getMonth(),
                    number,
                );
                const value = dateString(date);
                const time = defaultTimeFor(date);
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'admin-date-picker__day';
                button.textContent = String(number);
                button.disabled = !isAllowed(date, time);
                button.setAttribute('aria-pressed', String(input.value.slice(0, 10) === value));
                button.setAttribute('aria-label', dateFormatter.format(date));
                button.addEventListener('click', () => {
                    dateInput.value = dateFormatter.format(date);
                    if (timeInput) timeInput.value = defaultTimeFor(date);
                    validateAndSync();
                    closeCalendar();
                });
                cells.push(button);
            }
            days.replaceChildren(...cells);
        }

        function positionCalendar() {
            const rect = dateInput.getBoundingClientRect();
            const margin = 16;
            const width = Math.min(320, window.innerWidth - margin * 2);
            const left = Math.min(
                Math.max(rect.left, margin),
                window.innerWidth - width - margin,
            );
            const below = window.innerHeight - rect.bottom;
            const height = calendar.offsetHeight;
            const top = below >= height + 8 || rect.top < height + margin
                ? Math.min(rect.bottom + 6, window.innerHeight - height - margin)
                : rect.top - height - 6;
            calendar.style.left = `${left}px`;
            calendar.style.top = `${Math.max(margin, top)}px`;
            calendar.style.width = `${width}px`;
        }

        function openCalendar() {
            if (input.disabled || input.readOnly) return;
            calendar.hidden = false;
            dateInput.setAttribute('aria-expanded', 'true');
            renderCalendar();
            positionCalendar();
            window.addEventListener('resize', positionCalendar);
            window.addEventListener('scroll', positionCalendar, true);
        }

        function closeCalendar() {
            calendar.hidden = true;
            dateInput.setAttribute('aria-expanded', 'false');
            window.removeEventListener('resize', positionCalendar);
            window.removeEventListener('scroll', positionCalendar, true);
        }

        dateInput.addEventListener('focus', openCalendar);
        dateInput.addEventListener('click', openCalendar);
        dateInput.addEventListener('input', () => {
            const parsed = parseDate(dateInput.value);
            if (parsed) {
                displayedMonth = new Date(parsed.getFullYear(), parsed.getMonth(), 1);
            }
            validateAndSync();
            if (!calendar.hidden) renderCalendar();
        });
        dateInput.addEventListener('blur', () => {
            const parsed = parseDate(dateInput.value);
            if (parsed && isAllowed(parsed, defaultTimeFor(parsed))) {
                dateInput.value = dateFormatter.format(parsed);
            }
            updateValidity();
        });
        dateInput.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeCalendar();
                dateInput.blur();
            }
        });
        timeInput?.addEventListener('input', validateAndSync);
        previous.addEventListener('click', () => {
            displayedMonth = new Date(
                displayedMonth.getFullYear(),
                displayedMonth.getMonth() - 1,
                1,
            );
            renderCalendar();
        });
        next.addEventListener('click', () => {
            displayedMonth = new Date(
                displayedMonth.getFullYear(),
                displayedMonth.getMonth() + 1,
                1,
            );
            renderCalendar();
        });
        clear.addEventListener('click', () => {
            dateInput.value = '';
            validateAndSync();
            if (timeInput) timeInput.value = '';
            closeCalendar();
        });
        today.addEventListener('click', () => {
            const now = new Date();
            if (!isAllowed(now)) return;
            dateInput.value = dateFormatter.format(now);
            displayedMonth = new Date(now.getFullYear(), now.getMonth(), 1);
            if (timeInput) {
                timeInput.value = defaultTimeFor(now);
            }
            validateAndSync();
            closeCalendar();
        });
        input.addEventListener('change', () => {
            if (syncingFromCustom) return;
            const value = input.value;
            dateInput.value = formatDate(value.slice(0, 10));
            if (timeInput) timeInput.value = value.slice(11, 16);
            updateValidity();
            if (!calendar.hidden) renderCalendar();
        });
        input.form?.addEventListener('reset', () => {
            requestAnimationFrame(() => {
                dateInput.value = formatDate(input.value.slice(0, 10));
                if (timeInput) timeInput.value = input.value.slice(11, 16);
                displayedMonth = dateFromIso(input.value.slice(0, 10)) || new Date();
                updateValidity();
                renderCalendar();
            });
        });

        wrapper.closeCalendar = closeCalendar;
        input.required = false;
        dateInput.value = formatDate(input.value.slice(0, 10));
        if (timeInput) timeInput.value = input.value.slice(11, 16);
        if (input.disabled) dateInput.disabled = true;
        if (input.readOnly) dateInput.readOnly = true;
        updateValidity();
        renderCalendar();
    }

    document.addEventListener('click', (event) => {
        document.querySelectorAll('.admin-searchable-select').forEach((wrapper) => {
            if (wrapper.contains(event.target)) return;
            const panel = wrapper.querySelector('.admin-searchable-select__panel');
            if (panel && !panel.hidden) wrapper.closeDropdown?.();
        });
        document.querySelectorAll('.admin-date-picker').forEach((wrapper) => {
            if (wrapper.contains(event.target)) return;
            wrapper.closeCalendar?.();
        });
    });

    document.addEventListener(
        'invalid',
        (event) => {
            const select = event.target;
            if (!(select instanceof HTMLSelectElement)) return;
            const wrapper = select.closest('.admin-searchable-select');
            if (!wrapper?.classList.contains('admin-searchable-select')) return;
            event.preventDefault();
            const trigger = wrapper.querySelector('.admin-searchable-select__trigger');
            trigger?.focus();
            wrapper.closeDropdown?.();
            trigger?.click();
        },
        true,
    );

    const observer = new MutationObserver((records) => {
        records.forEach((record) => {
            record.addedNodes.forEach((node) => {
                if (!(node instanceof Element)) return;
                if (node.matches('select')) enhance(node);
                node.querySelectorAll('select').forEach(enhance);
                if (node.matches('input[type="date"], input[type="datetime-local"]')) {
                    enhanceDate(node);
                }
                node
                    .querySelectorAll('input[type="date"], input[type="datetime-local"]')
                    .forEach(enhanceDate);
            });
        });
    });

    function initialize() {
        document.querySelectorAll('select').forEach(enhance);
        document
            .querySelectorAll('input[type="date"], input[type="datetime-local"]')
            .forEach(enhanceDate);
        observer.observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, { once: true });
    } else {
        initialize();
    }
})();
