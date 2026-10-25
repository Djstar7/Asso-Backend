@php
    $navigationLocale = auth()->user()->preferredLocale();
    $navigation = app(\App\Support\AdminNavigation::class)->forUser(auth()->user(), request(), get_defined_vars());
    $currentNavigationItem = collect($navigation)->flatMap(fn ($group) => $group['items'])->firstWhere('active', true);
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="Dashboard Panel Admin de l'application ASSO - Gérez vos utilisateurs, boutiques, produits, transactions et bien plus.">

    <!-- Open Graph / Partage de lien -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:title" content="ASSO - Dashboard Admin">
    <meta property="og:description" content="Dashboard Panel Admin de l'application ASSO - Gérez vos utilisateurs, boutiques, produits, transactions et bien plus.">
    <meta property="og:image" content="{{ asset('logo/Asso.png') }}">

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="ASSO - Dashboard Admin">
    <meta name="twitter:description" content="Dashboard Panel Admin de l'application ASSO - Gérez vos utilisateurs, boutiques, produits, transactions et bien plus.">
    <meta name="twitter:image" content="{{ asset('logo/Asso.png') }}">

    <!-- Favicon -->
    <link rel="icon" type="image/png" href="{{ asset('logo/Asso.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('logo/Asso.png') }}">

    <title>@yield('title', $currentNavigationItem['label'] ?? 'ASSO') - ASSO Admin</title>

    <!-- Tailwind CSS -->
    <!-- Servi par ASSO : le CDN tailwindcss.com est parfois injoignable (DNS), la page restait sans style. -->
    <script src="{{ asset('vendor/tailwindcss/tailwindcss-3.4.17.js') }}"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: {
                            50: '#fff5ee',
                            100: '#ffe8dd',
                            200: '#ffd0bb',
                            300: '#ffb199',
                            400: '#ff9266',
                            500: '#ff7344',
                            600: '#ff5722',
                            700: '#e64a19',
                            800: '#bf3e15',
                            900: '#993211',
                        },
                        dark: {
                            50: '#18181b',
                            100: '#09090b',
                            200: '#27272a',
                            300: '#3f3f46',
                            400: '#52525b',
                            500: '#71717a',
                            600: '#a1a1aa',
                            700: '#d4d4d8',
                            800: '#e4e4e7',
                            900: '#f4f4f5',
                        }
                    }
                }
            }
        }
    </script>

    <!-- Alpine.js -->
    <script src="{{ asset('js/admin-tabs.js') }}"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <!-- Chart.js -->
    @unless($__env->hasSection('skip-charts'))
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    @endunless

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="{{ asset('css/admin-file-upload.css') }}">

    <style>
        [x-cloak] { display: none !important; }

        /* Couleur de texte de base claire pour tout le back-office (thème sombre).
           Tout élément sans classe de couleur explicite hérite d'un texte lisible.
           Les classes Tailwind text-* (text-gray-400, text-red-500, text-white…) restent prioritaires. */
        body {
            color: #e5e7eb; /* gray-200 */
        }

        /* Champs de formulaire : texte en blanc, lisible sur le thème sombre.
           Sélecteurs d'éléments (faible spécificité) : les classes Tailwind text-* restent prioritaires. */
        input:not([type="checkbox"]):not([type="radio"]),
        select,
        textarea {
            color: #ffffff;
        }
        select option,
        select optgroup {
            background-color: #18181b; /* dark-50 */
            color: #ffffff;
        }
        /* Barres de défilement fines aux couleurs du thème (menu, listes, tableaux). */
        * {
            scrollbar-width: thin;
            scrollbar-color: #3f3f46 transparent; /* dark-300 */
        }
        *::-webkit-scrollbar { width: 8px; height: 8px; }
        *::-webkit-scrollbar-track { background: transparent; }
        *::-webkit-scrollbar-thumb {
            background-color: #3f3f46;
            border-radius: 9999px;
            border: 2px solid transparent;
            background-clip: content-box;
        }
        *::-webkit-scrollbar-thumb:hover { background-color: #52525b; }
        *::-webkit-scrollbar-corner { background: transparent; }

        input::placeholder,
        textarea::placeholder {
            color: #9ca3af; /* gray-400 : visible mais distinct du texte saisi */
        }

        /* ===== Chargement des pages (écran initial + navigation) =====
           CSS brut : Tailwind (CDN) n'est pas encore prêt quand l'écran initial s'affiche. */
        #pageProgress {
            position: fixed; top: 0; left: 0; height: 3px; width: 0;
            background: linear-gradient(90deg, #ff9266, #ff5722);
            box-shadow: 0 0 8px rgba(255, 87, 34, .6);
            z-index: 200; opacity: 0; pointer-events: none;
            transition: width .4s ease, opacity .3s ease;
        }
        #pageProgress.is-active { opacity: 1; }
        #pageLoader {
            position: fixed; inset: 0; z-index: 190;
            display: flex; align-items: center; justify-content: center;
            background: rgba(9, 9, 11, .55);
            -webkit-backdrop-filter: blur(2px); backdrop-filter: blur(2px);
            opacity: 0; visibility: hidden;
            transition: opacity .2s ease, visibility 0s linear .2s;
        }
        #pageLoader.is-active { opacity: 1; visibility: visible; transition: opacity .2s ease; }
        #pageLoader.is-initial { background: #18181b; -webkit-backdrop-filter: none; backdrop-filter: none; }
        #pageLoader .page-loader-box {
            display: flex; flex-direction: column; align-items: center; gap: 14px;
        }
        #pageLoader .page-loader-ring {
            position: relative; width: 72px; height: 72px;
        }
        #pageLoader .page-loader-ring::before {
            content: ""; position: absolute; inset: 0; border-radius: 50%;
            border: 3px solid #27272a; border-top-color: #ff5722;
            animation: pageLoaderSpin .8s linear infinite;
        }
        #pageLoader .page-loader-ring img {
            position: absolute; inset: 10px; width: 52px; height: 52px;
            border-radius: 50%; object-fit: cover;
        }
        #pageLoader .page-loader-text {
            font: 500 13px/1.2 ui-sans-serif, system-ui, sans-serif;
            color: #a1a1aa; letter-spacing: .02em;
        }
        @keyframes pageLoaderSpin { to { transform: rotate(360deg); } }
        @media (prefers-reduced-motion: reduce) {
            #pageLoader .page-loader-ring::before { animation-duration: 2s; }
        }
    </style>
    <link rel="stylesheet" href="{{ asset('css/admin-searchable-select.css') }}">
    <link rel="stylesheet" href="{{ asset('css/admin-checkbox.css') }}">
    <noscript><style>#pageLoader, #pageProgress { display: none !important; }</style></noscript>

    @stack('styles')
</head>
<body class="bg-dark-50" x-data="{ sidebarOpen: false }">
    <!-- Chargement des pages : barre de progression + écran d'attente -->
    <div id="pageProgress" class="is-active" style="width: 30%"></div>
    <div id="pageLoader" class="is-active is-initial" role="status" aria-live="polite" aria-label="Chargement en cours">
        <div class="page-loader-box">
            <div class="page-loader-ring">
                <img src="{{ asset('logo/Asso.png') }}" alt="">
            </div>
            <span class="page-loader-text">Chargement…</span>
        </div>
    </div>
    <div class="min-h-screen">
        <!-- Mobile Menu Overlay -->
        <div x-show="sidebarOpen"
             x-cloak
             @click="sidebarOpen = false"
             class="fixed inset-0 bg-black bg-opacity-50 z-40 lg:hidden"
             x-transition:enter="transition-opacity ease-out duration-300"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="transition-opacity ease-in duration-200"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"></div>

        <!-- Sidebar -->
        <aside :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
               class="fixed inset-y-0 left-0 z-50 w-64 bg-dark-100 transform transition-transform duration-300 ease-in-out lg:translate-x-0 flex flex-col h-screen">

            <!-- Logo -->
            <div class="flex items-center justify-between h-16 px-6 bg-dark-50 flex-shrink-0 border-b border-dark-200">
                <a href="{{ route(auth()->user()->adminHomeRoute() ?? 'admin.dashboard') }}" class="flex items-center space-x-3">
                    <div class="w-10 h-10 bg-white rounded-full flex items-center justify-center shadow-lg overflow-hidden p-0.5">
                        <img src="{{ asset('logo/Asso.png') }}" alt="ASSO Logo" class="w-full h-full object-cover rounded-full">
                    </div>
                    <span class="text-white font-bold text-xl">ASSO</span>
                </a>
                <button @click="sidebarOpen = false" class="lg:hidden text-gray-400 hover:text-white" aria-label="{{ __('admin_nav.close', [], auth()->user()->preferredLocale()) }}">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            @include('admin.partials.navigation')

            <!-- User Info at bottom -->
            <div class="p-4 border-t border-dark-200 flex-shrink-0">
                <div class="flex items-center">
                    <div class="w-10 h-10 rounded-full flex items-center justify-center overflow-hidden flex-shrink-0 bg-gradient-to-br from-primary-500 to-primary-600 shadow-md">
                        <span class="text-white font-bold">{{ mb_strtoupper(mb_substr(auth()->user()->name ?? 'A', 0, 1)) }}</span>
                    </div>
                    <div class="ml-3 flex-1">
                        <p class="text-sm font-medium text-white">{{ auth()->user()->name ?? 'Admin' }}</p>
                        <p class="text-xs text-gray-400">{{ auth()->user()->backofficeRoleLabel() }}</p>
                    </div>
                    <form action="{{ route('admin.logout') }}" method="POST">
                        @csrf
                        <button type="submit" class="text-gray-400 hover:text-primary-500 transition-colors" title="{{ __('admin_nav.logout', [], auth()->user()->preferredLocale()) }}" aria-label="{{ __('admin_nav.logout', [], auth()->user()->preferredLocale()) }}">
                            <i class="fas fa-sign-out-alt"></i>
                        </button>
                    </form>
                </div>
            </div>
        </aside>

        <!-- Main Content -->
        <div class="flex flex-col min-h-screen lg:ml-64">
            <!-- Top Header -->
            <header class="bg-dark-100 shadow-sm border-b border-dark-200 h-16 flex items-center justify-between px-6 flex-shrink-0 sticky top-0 z-10">
                <div class="flex items-center min-w-0 flex-1">
                    <button @click="sidebarOpen = true" class="lg:hidden text-gray-300 hover:text-white mr-4" aria-label="{{ __('admin_nav.open', [], auth()->user()->preferredLocale()) }}">
                        <i class="fas fa-bars text-xl"></i>
                    </button>
                    <h1 class="text-xl font-semibold text-white truncate" title="{{ $currentNavigationItem['label'] ?? 'ASSO' }}">@yield('header', $currentNavigationItem['label'] ?? 'ASSO')</h1>
                </div>

                <div class="flex items-center space-x-4 flex-shrink-0">
                    <!-- Notifications -->
                    <div x-data="{ open: false }" class="relative">
                        <button @click="open = !open" class="relative text-gray-300 hover:text-primary-500 transition-colors">
                            <i class="fas fa-bell text-xl"></i>
                            @if(($adminNotificationsCount ?? 0) > 0)
                                <span class="absolute -top-1 -right-1 min-w-[1.25rem] h-5 px-1 bg-primary-500 text-white text-xs font-bold rounded-full flex items-center justify-center">
                                    {{ $adminNotificationsCount > 99 ? '99+' : $adminNotificationsCount }}
                                </span>
                            @endif
                        </button>

                        <div x-show="open"
                             x-cloak
                             @click.away="open = false"
                             class="absolute right-0 mt-2 w-80 bg-dark-100 rounded-lg shadow-lg border border-dark-200 py-2 z-50">
                            <div class="px-4 py-2 border-b border-dark-200 flex items-center justify-between">
                                <h3 class="font-semibold text-white">Notifications</h3>
                                @if(($adminNotificationsCount ?? 0) > 0)
                                    <span class="text-xs text-primary-400 font-semibold">{{ $adminNotificationsCount }}</span>
                                @endif
                            </div>
                            <div class="max-h-72 overflow-y-auto">
                                @forelse(($adminNotifications ?? []) as $notif)
                                    <a href="{{ $notif['url'] }}"
                                       class="flex items-start gap-3 px-4 py-3 hover:bg-dark-200 transition-colors border-b border-dark-200 last:border-0">
                                        <div class="w-9 h-9 rounded-full bg-dark-50 flex items-center justify-center flex-shrink-0">
                                            <i class="fas {{ $notif['icon'] }} {{ $notif['color'] }}"></i>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <p class="text-sm text-gray-200 leading-snug">{{ $notif['title'] }}</p>
                                            <span class="text-xs text-primary-400 font-medium">Voir →</span>
                                        </div>
                                    </a>
                                @empty
                                    <div class="px-4 py-8 text-center">
                                        <i class="fas fa-check-circle text-green-500/70 text-2xl mb-2"></i>
                                        <p class="text-sm text-gray-400">Aucune nouvelle notification</p>
                                    </div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Page Content -->
            <main class="flex-1 overflow-y-auto">
                @include('admin.partials.flash-message')
                @if($errors->any())
                    <div role="alert" class="m-6 p-4 rounded-lg border border-red-500/50 bg-red-500/10 text-red-300">
                        <p class="font-semibold">Vérifiez les informations du formulaire.</p>
                        <ul class="mt-2 list-disc pl-5">@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
                    </div>
                @endif

                @yield('content')
            </main>

            <!-- Footer -->
            <footer class="border-t border-dark-200 bg-dark-100 px-6 py-4 flex-shrink-0">
                <p class="text-sm text-gray-400 text-center">
                    &copy; {{ date('Y') }} ASSO. Tous droits réservés.
                </p>
            </footer>
        </div>
    </div>

    @include('admin.partials.confirmation-modal')

    <script defer src="{{ asset('js/admin-navigation.js') }}"></script>

    <script>
    // Chargement des pages : l'écran d'attente couvre le premier affichage (le temps que
    // Tailwind et les scripts soient prêts), puis réapparaît à chaque navigation
    // (clic sur un lien interne, envoi de formulaire) jusqu'à l'arrivée de la page suivante.
    // Exclure un lien ou un formulaire : attribut data-no-loader.
    (function () {
        const loader   = document.getElementById('pageLoader');
        const progress = document.getElementById('pageProgress');
        if (!loader || !progress) return;

        // Téléchargements (exports, PDF, documents) : la page ne change pas, donc pas d'attente.
        const DOWNLOAD_RE = /(export|download|pdf|csv|excel)/i;
        let overlayTimer = null, trickleTimer = null, safetyTimer = null, width = 0;

        function setWidth(w) { width = w; progress.style.width = w + '%'; }

        function hide() {
            clearTimeout(overlayTimer); clearInterval(trickleTimer); clearTimeout(safetyTimer);
            loader.classList.remove('is-active', 'is-initial');
            if (progress.classList.contains('is-active')) {
                setWidth(100);
                setTimeout(() => { progress.classList.remove('is-active'); setTimeout(() => setWidth(0), 300); }, 200);
            }
        }

        function show() {
            clearTimeout(overlayTimer); clearInterval(trickleTimer); clearTimeout(safetyTimer);
            progress.classList.add('is-active');
            setWidth(10);
            trickleTimer = setInterval(() => { if (width < 90) setWidth(width + (90 - width) * 0.1); }, 300);
            // L'écran d'attente n'apparaît que si la page tarde (évite le clignotement).
            overlayTimer = setTimeout(() => loader.classList.add('is-active'), 250);
            // Filet de sécurité : si la navigation n'aboutit pas (réponse de téléchargement…).
            safetyTimer = setTimeout(hide, 20000);
        }

        // Les scripts propres aux pages (chargés après celui-ci) peuvent encore annuler
        // l'événement (envoi en AJAX, etc.) : on vérifie une fois tous les gestionnaires passés.
        function showUnlessPrevented(e) {
            setTimeout(() => { if (!e.defaultPrevented) show(); }, 0);
        }

        function isDownloadUrl(url) {
            return DOWNLOAD_RE.test(url.pathname);
        }

        // Premier affichage
        if (document.readyState === 'complete') hide();
        else window.addEventListener('load', hide);
        // Retour arrière / avant servi depuis le cache du navigateur
        window.addEventListener('pageshow', (e) => { if (e.persisted) hide(); });

        // Liens internes (phase de bouillonnement : la modale de confirmation, en capture,
        // a déjà annulé l'événement si besoin -> defaultPrevented).
        document.addEventListener('click', function (e) {
            if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
            const a = e.target.closest('a[href]');
            if (!a || a.hasAttribute('download') || a.closest('[data-no-loader]')) return;
            if (a.target && a.target !== '_self') return;
            const raw = a.getAttribute('href');
            if (!raw || raw.startsWith('#') || /^(javascript|mailto|tel):/i.test(raw)) return;
            const url = new URL(a.href, location.href);
            if (url.origin !== location.origin || isDownloadUrl(url)) return;
            // Simple ancre sur la même page
            if (url.pathname === location.pathname && url.search === location.search && url.hash) return;
            showUnlessPrevented(e);
        });

        // Formulaires
        document.addEventListener('submit', function (e) {
            if (e.defaultPrevented) return;
            const form = e.target;
            if (!form || form.closest('[data-no-loader]')) return;
            const target = (e.submitter && e.submitter.getAttribute('formtarget')) || form.getAttribute('target');
            if (target && target !== '_self') return;
            const action = (e.submitter && e.submitter.getAttribute('formaction')) || form.getAttribute('action') || location.href;
            if (isDownloadUrl(new URL(action, location.href))) return;
            showUnlessPrevented(e);
        });

        // API publique pour les pages : window.pageLoader.show() / .hide()
        window.pageLoader = { show, hide };
    })();
    </script>

    <script defer src="{{ asset('js/admin-searchable-select.js') }}"></script>
    <script defer src="{{ asset('js/admin-file-upload.js') }}"></script>
    @stack('scripts')
</body>
</html>
