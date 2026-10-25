(function () {
    'use strict';

    const nav = document.getElementById('adminSidebarNav');
    if (!nav) return;

    const search = nav.querySelector('[data-nav-search]');
    const clear = nav.querySelector('[data-nav-clear]');
    const empty = nav.querySelector('[data-nav-empty]');
    const groups = Array.from(nav.querySelectorAll('[data-nav-group]'));
    const storageKey = nav.dataset.storageKey;
    let searching = false;
    let beforeSearch = null;

    const read = (suffix) => {
        try { return JSON.parse(sessionStorage.getItem(storageKey + suffix)); }
        catch (_) { return null; }
    };
    const write = (suffix, value) => {
        try { sessionStorage.setItem(storageKey + suffix, JSON.stringify(value)); }
        catch (_) { /* Navigation remains usable when storage is unavailable. */ }
    };
    const openState = () => Object.fromEntries(groups.map(group => [group.dataset.navGroup, group.open]));
    const savedGroups = read('.groups');

    groups.forEach(group => {
        if (savedGroups && typeof savedGroups[group.dataset.navGroup] === 'boolean') {
            group.open = savedGroups[group.dataset.navGroup];
        }
        // A closed saved section must never hide the current page on arrival.
        if (group.querySelector('[aria-current="page"]')) group.open = true;
        group.addEventListener('toggle', () => {
            if (!searching) write('.groups', openState());
        });
    });

    const normalize = (value) => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase().trim();
    const filter = () => {
        const query = normalize(search.value);
        if (query && !searching) {
            beforeSearch = openState();
            searching = true;
        }
        let anyVisible = false;
        groups.forEach(group => {
            const groupMatches = normalize(group.dataset.navLabel || '').includes(query);
            let hasMatches = false;
            group.querySelectorAll('[data-nav-item]').forEach(item => {
                const matches = !query || groupMatches || normalize((item.dataset.navLabel || '') + ' ' + (item.dataset.navKeywords || '')).includes(query);
                item.hidden = !matches;
                hasMatches = hasMatches || matches;
            });
            group.hidden = !hasMatches;
            anyVisible = anyVisible || hasMatches;
            if (query && hasMatches) group.open = true;
            if (!query && beforeSearch) group.open = beforeSearch[group.dataset.navGroup];
        });
        clear.hidden = !query;
        empty.hidden = anyVisible;
        if (!query) {
            searching = false;
            beforeSearch = null;
        }
    };

    if (search && clear && empty) {
        search.addEventListener('input', filter);
        clear.addEventListener('click', () => {
            search.value = '';
            filter();
            search.focus();
        });
        search.addEventListener('keydown', event => {
            if (event.key === 'Escape' && search.value) {
                event.preventDefault();
                event.stopPropagation();
                search.value = '';
                filter();
            }
        });
        // Browsers may restore a search value during back/forward navigation.
        if (search.value) filter();
    }

    requestAnimationFrame(() => {
        const savedScroll = read('.scroll');
        if (typeof savedScroll === 'number' && Number.isFinite(savedScroll)) {
            nav.scrollTop = savedScroll;
        } else {
            const current = nav.querySelector('[aria-current="page"]');
            if (current) {
                // Change only this scroll container, never the document or sidebar horizontally.
                const top = current.getBoundingClientRect().top - nav.getBoundingClientRect().top + nav.scrollTop;
                nav.scrollTop = Math.max(0, top - nav.clientHeight / 2 + current.offsetHeight / 2);
            }
        }
    });
    const saveScroll = () => write('.scroll', nav.scrollTop);
    nav.addEventListener('scroll', saveScroll, { passive: true });
    window.addEventListener('pagehide', saveScroll);
})();
