/* Persist settings tabs without creating a browser-history entry for every click. */
window.adminTabState = function ({ key, property, allowed, initial, forced = '' }) {
    const valid = (value) => allowed.includes(value);
    const storageKey = `admin.tabs.v1.${key}`;
    const stored = () => {
        try { return sessionStorage.getItem(storageKey); } catch (_) { return null; }
    };
    const query = () => new URL(window.location.href).searchParams.get('tab');
    const choose = (...values) => values.find(valid) || allowed[0];
    return {
        [property]: choose(forced, query(), stored(), initial),
        init() {
            const persist = (value) => {
                if (!valid(value)) return;
                try { sessionStorage.setItem(storageKey, value); } catch (_) { /* Private browsing. */ }
                const url = new URL(window.location.href);
                url.searchParams.set('tab', value);
                try { history.replaceState(history.state, '', url); } catch (_) { /* Embedded views. */ }
            };
            persist(this[property]);
            this.$watch(property, (value) => persist(value));
            this._tabPopstate = () => { this[property] = choose(query(), initial); };
            window.addEventListener('popstate', this._tabPopstate);
        },
        destroy() { window.removeEventListener('popstate', this._tabPopstate); },
    };
};
