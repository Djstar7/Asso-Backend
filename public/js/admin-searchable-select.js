(() => {
    const enhanced = new WeakSet();
    const layoutClassPattern =
        /^(?:(?:sm|md|lg|xl|2xl):)?(?:w-|min-w-|max-w-|h-|min-h-|max-h-|col-span-|row-span-|flex-|self-|mt-|mb-|ml-|mr-|mx-|my-|block$|inline$|inline-block$|inline-flex$)/;

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
        wrapper.classList.add(...layoutClasses);

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

        select.insertAdjacentElement('afterend', wrapper);
        wrapper.append(trigger, panel);
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

    document.addEventListener('click', (event) => {
        document.querySelectorAll('.admin-searchable-select').forEach((wrapper) => {
            if (wrapper.contains(event.target)) return;
            const panel = wrapper.querySelector('.admin-searchable-select__panel');
            if (panel && !panel.hidden) wrapper.closeDropdown?.();
        });
    });

    document.addEventListener(
        'invalid',
        (event) => {
            const select = event.target;
            if (!(select instanceof HTMLSelectElement)) return;
            const wrapper = select.nextElementSibling;
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
            });
        });
    });

    function initialize() {
        document.querySelectorAll('select').forEach(enhance);
        observer.observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, { once: true });
    } else {
        initialize();
    }
})();
