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
            requestAnimationFrame(revealCurrent);
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

    function revealCurrent() {
        if (searching) return;
        const current = nav.querySelector('[aria-current="page"]');
        if (!current || current.hidden) return;
        const group = current.closest('[data-nav-group]');
        if (group?.hidden) return;
        if (group) group.open = true;
        const bounds = nav.getBoundingClientRect();
        if (bounds.height <= 0) return;
        const searchHeight = nav.querySelector('.asso-navigation__search')?.getBoundingClientRect().height || 0;
        const top = bounds.top + searchHeight + 8;
        const bottom = bounds.bottom - 8;
        const item = current.getBoundingClientRect();
        // Correct only this container. Saved scroll must not hide the active link
        // under the sticky search field or beneath the bottom of the sidebar.
        if (item.top < top) nav.scrollTop -= top - item.top;
        else if (item.bottom > bottom) nav.scrollTop += item.bottom - bottom;
    }
    const revealAfterLayout = () => requestAnimationFrame(revealCurrent);
    requestAnimationFrame(() => {
        const savedScroll = read('.scroll');
        if (typeof savedScroll === 'number' && Number.isFinite(savedScroll)) nav.scrollTop = savedScroll;
        revealCurrent();
        revealAfterLayout();
    });
    window.addEventListener('load', revealAfterLayout, { once: true });
    window.addEventListener('pageshow', revealAfterLayout);
    window.addEventListener('resize', revealAfterLayout);
    document.fonts?.ready.then(revealAfterLayout);
    if (typeof ResizeObserver !== 'undefined') new ResizeObserver(revealAfterLayout).observe(nav);
    const sidebar = nav.closest('aside');
    if (sidebar) new MutationObserver(() => {
        if (sidebar.classList.contains('translate-x-0')) revealAfterLayout();
    }).observe(sidebar, { attributes: true, attributeFilter: ['class'] });
    const saveScroll = () => write('.scroll', nav.scrollTop);
    nav.addEventListener('scroll', saveScroll, { passive: true });
    window.addEventListener('pagehide', saveScroll);
})();
