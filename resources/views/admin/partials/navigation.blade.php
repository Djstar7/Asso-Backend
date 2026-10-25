<nav id="adminSidebarNav"
     class="asso-navigation flex-1 min-h-0 overflow-y-auto" lang="{{ $navigationLocale }}"
     aria-label="{{ __('admin_nav.label', [], $navigationLocale) }}"
     data-storage-key="admin.navigation.v1.{{ auth()->id() }}">
    <div class="asso-navigation__search">
        <label for="adminNavigationSearch" class="sr-only">{{ __('admin_nav.search.label', [], $navigationLocale) }}</label>
        <div class="asso-navigation__search-field">
            <i class="fas fa-search" aria-hidden="true"></i>
            <input id="adminNavigationSearch" type="search" autocomplete="off" spellcheck="false"
                   placeholder="{{ __('admin_nav.search.placeholder', [], $navigationLocale) }}" data-nav-search>
            <button type="button" data-nav-clear hidden aria-label="{{ __('admin_nav.search.clear', [], $navigationLocale) }}">
                <i class="fas fa-times" aria-hidden="true"></i>
            </button>
        </div>
    </div>

    <div class="asso-navigation__groups">
        @foreach($navigation as $group)
            @php
                $groupActive = collect($group['items'])->contains(fn ($item) => $item['active']);
                $groupBadge = collect($group['items'])->sum('badge');
                $groupDanger = collect($group['items'])->contains(fn ($item) => $item['badge'] > 0 && $item['tone'] === 'danger');
            @endphp
            <details class="asso-navigation__group" data-nav-group="{{ $group['id'] }}"
                     data-nav-label="{{ $group['label'] }}"
                     @if($groupActive || in_array($group['id'], ['overview', 'help'], true)) open @endif>
                <summary class="asso-navigation__summary" aria-controls="adminNavGroup-{{ $group['id'] }}">
                    <i class="fas {{ $group['icon'] }} asso-navigation__icon" aria-hidden="true"></i>
                    <span class="asso-navigation__group-label">{{ $group['label'] }}</span>
                    @if($groupBadge > 0)
                        <span class="asso-navigation__badge asso-navigation__group-badge {{ $groupDanger ? 'asso-navigation__badge--danger' : '' }}">{{ $groupBadge }}</span>
                    @endif
                    <i class="fas fa-chevron-right asso-navigation__chevron" aria-hidden="true"></i>
                </summary>
                <div id="adminNavGroup-{{ $group['id'] }}" class="asso-navigation__items">
                    @foreach($group['items'] as $item)
                        <a href="{{ $item['url'] }}"
                           class="asso-navigation__item {{ $item['active'] ? 'asso-navigation__item--active' : '' }}"
                           data-nav-item="{{ $item['id'] }}" data-nav-label="{{ $item['label'] }}" data-nav-keywords="{{ $item['keywords'] }}"
                           data-active="{{ $item['active'] ? 'true' : 'false' }}"
                           @if($item['active']) aria-current="page" @endif>
                            <i class="fas {{ $item['icon'] }} asso-navigation__item-icon" aria-hidden="true"></i>
                            <span class="asso-navigation__item-label">{{ $item['label'] }}</span>
                            @if($item['badge'] > 0)
                                <span class="asso-navigation__badge {{ $item['tone'] === 'danger' ? 'asso-navigation__badge--danger' : '' }}">{{ $item['badge'] }}</span>
                            @endif
                        </a>
                    @endforeach
                </div>
            </details>
        @endforeach
    </div>
    <p class="asso-navigation__empty" data-nav-empty role="status" aria-live="polite" hidden>{{ __('admin_nav.search.empty', [], $navigationLocale) }}</p>
</nav>

<style>
    .asso-navigation { color: #d4d4d8; scrollbar-width: thin; scrollbar-color: #3f3f46 transparent; padding-bottom: 1rem; }
    .asso-navigation [hidden] { display: none !important; }
    .asso-navigation__search { position: sticky; top: 0; z-index: 2; padding: 1rem .875rem .75rem; background: #09090b; }
    .asso-navigation__search-field { display: flex; align-items: center; gap: .5rem; min-width: 0; padding: .625rem .75rem; border: 1px solid #3f3f46; border-radius: .5rem; background: #18181b; }
    .asso-navigation__search-field > i { color: #a1a1aa; font-size: .75rem; }
    .asso-navigation__search-field:focus-within { border-color: #ff9266; box-shadow: 0 0 0 2px rgb(255 146 102 / 15%); }
    .asso-navigation__search input { min-width: 0; width: 100%; border: 0; outline: 0; background: transparent; color: #f4f4f5; font: inherit; font-size: .8125rem; line-height: 1.4; }
    .asso-navigation__search input::placeholder { color: #a1a1aa; opacity: 1; }
    .asso-navigation__search input::-webkit-search-cancel-button { display: none; }
    .asso-navigation__search button { display: inline-flex; justify-content: center; align-items: center; flex-shrink: 0; width: 1.25rem; height: 1.25rem; border-radius: .25rem; color: #a1a1aa; font-size: .75rem; }
    .asso-navigation__search button:hover { color: #fff; background: #3f3f46; }
    .asso-navigation__groups { padding: 0 .625rem; }
    .asso-navigation__group { padding: .125rem 0; }
    .asso-navigation__summary { display: flex; align-items: center; gap: .625rem; padding: .75rem .625rem; border-radius: .5rem; cursor: pointer; list-style: none; font-size: .8125rem; font-weight: 600; line-height: 1.4; color: #d4d4d8; }
    .asso-navigation__summary::-webkit-details-marker { display: none; }
    .asso-navigation__summary:hover { background: #18181b; color: #fafafa; }
    .asso-navigation__icon { width: 1rem; flex-shrink: 0; text-align: center; color: #a1a1aa; font-size: .8125rem; }
    .asso-navigation__group-label { flex: 1; min-width: 0; overflow-wrap: anywhere; }
    .asso-navigation__chevron { flex-shrink: 0; color: #a1a1aa; font-size: .625rem; transition: transform .15s ease; }
    .asso-navigation__group[open] > .asso-navigation__summary .asso-navigation__chevron { transform: rotate(90deg); }
    .asso-navigation__group[open] > .asso-navigation__summary .asso-navigation__group-badge { display: none; }
    .asso-navigation__items { margin: .125rem 0 .375rem 1rem; padding-left: .5rem; border-left: 1px solid #27272a; }
    .asso-navigation__item { display: flex; align-items: center; gap: .5rem; min-height: 2.5rem; margin: .125rem 0; padding: .5rem .625rem; border-radius: .375rem; color: #a1a1aa; font-size: .8125rem; line-height: 1.45; transition: background .15s ease, color .15s ease; }
    .asso-navigation__item:hover { background: #27272a; color: #fafafa; }
    .asso-navigation__item--active, .asso-navigation__item--active:hover { background: rgb(255 115 68 / 12%); color: #ffb199; box-shadow: inset 2px 0 0 #ff7344; font-weight: 600; }
    .asso-navigation__item-icon { width: .875rem; flex-shrink: 0; text-align: center; font-size: .75rem; opacity: .85; }
    .asso-navigation__item-label { flex: 1; min-width: 0; overflow-wrap: anywhere; }
    .asso-navigation__badge { display: inline-flex; justify-content: center; align-items: center; flex-shrink: 0; min-width: 1.25rem; padding: .125rem .375rem; border-radius: .375rem; background: rgb(250 204 21 / 12%); color: #fde68a; font-size: .6875rem; font-weight: 600; line-height: 1.4; font-variant-numeric: tabular-nums; }
    .asso-navigation__badge--danger { background: rgb(248 113 113 / 12%); color: #fca5a5; }
    .asso-navigation__empty { padding: 1.5rem 1.25rem; color: #a1a1aa; font-size: .8125rem; line-height: 1.6; }
    .asso-navigation__summary:focus-visible, .asso-navigation__item:focus-visible, .asso-navigation__search button:focus-visible { outline: 2px solid #ff9266; outline-offset: 2px; }
    @media (prefers-reduced-motion: reduce) { .asso-navigation__chevron, .asso-navigation__item { transition: none; } }
</style>
