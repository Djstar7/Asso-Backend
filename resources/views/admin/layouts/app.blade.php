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

    <title>@yield('title', 'Dashboard') - ASSO Admin</title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
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
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

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
        input::placeholder,
        textarea::placeholder {
            color: #9ca3af; /* gray-400 : visible mais distinct du texte saisi */
        }
    </style>

    @stack('styles')
</head>
<body class="bg-dark-50" x-data="{ sidebarOpen: false }">
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
                <a href="{{ route('admin.dashboard') }}" class="flex items-center space-x-3">
                    <div class="w-10 h-10 bg-white rounded-full flex items-center justify-center shadow-lg overflow-hidden p-0.5">
                        <img src="{{ asset('logo/Asso.png') }}" alt="ASSO Logo" class="w-full h-full object-cover rounded-full">
                    </div>
                    <span class="text-white font-bold text-xl">ASSO</span>
                </a>
                <button @click="sidebarOpen = false" class="lg:hidden text-gray-400 hover:text-white">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <!-- Navigation -->
            <nav class="flex-1 mt-6 px-3 overflow-y-auto">
                <!-- Dashboard -->
                <div class="space-y-1">
                    <a href="{{ route('admin.dashboard') }}"
                       class="flex items-center px-4 py-3 text-sm rounded-lg transition-all {{ request()->routeIs('admin.dashboard') ? 'bg-gradient-to-r from-primary-500 to-primary-600 text-white shadow-md' : 'text-gray-300 hover:bg-dark-200 hover:text-white' }}">
                        <i class="fas fa-chart-pie w-5 mr-3"></i>
                        Dashboard
                    </a>
                </div>

                <!-- Section Gestion -->
                <div class="mt-8 pt-6 border-t border-dark-200">
                    <p class="px-4 text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Gestion</p>

                    <a href="{{ route('admin.users.index') }}"
                       class="flex items-center px-4 py-3 text-sm rounded-lg transition-all {{ request()->routeIs('admin.users.*') ? 'bg-gradient-to-r from-primary-500 to-primary-600 text-white shadow-md' : 'text-gray-300 hover:bg-dark-200 hover:text-white' }}">
                        <i class="fas fa-users w-5 mr-3"></i>
                        Utilisateurs
                    </a>

                    <a href="{{ route('admin.preferences.index') }}"
                       class="flex items-center px-4 py-3 text-sm rounded-lg transition-all {{ request()->routeIs('admin.preferences.*') ? 'bg-gradient-to-r from-primary-500 to-primary-600 text-white shadow-md' : 'text-gray-300 hover:bg-dark-200 hover:text-white' }}">
                        <i class="fas fa-heart w-5 mr-3"></i>
                        Préférences
                    </a>

                    <a href="{{ route('admin.deliverers.index') }}"
                       class="flex items-center px-4 py-3 text-sm rounded-lg transition-all {{ request()->routeIs('admin.deliverers.*') ? 'bg-gradient-to-r from-primary-500 to-primary-600 text-white shadow-md' : 'text-gray-300 hover:bg-dark-200 hover:text-white' }}">
                        <i class="fas fa-truck w-5 mr-3"></i>
                        Livreurs
                    </a>

                    <a href="{{ route('admin.shops.index') }}"
                       class="flex items-center justify-between px-4 py-3 text-sm rounded-lg transition-all {{ request()->routeIs('admin.shops.*') ? 'bg-gradient-to-r from-primary-500 to-primary-600 text-white shadow-md' : 'text-gray-300 hover:bg-dark-200 hover:text-white' }}">
                        <span class="flex items-center">
                            <i class="fas fa-store w-5 mr-3"></i>
                            Boutiques
                        </span>
                        @if(isset($pendingShopsCount) && $pendingShopsCount > 0)
                            <span class="flex items-center justify-center min-w-[1.5rem] h-6 px-2 bg-yellow-500 text-dark-100 text-xs font-bold rounded-full animate-pulse">
                                {{ $pendingShopsCount }}
                            </span>
                        @endif
                    </a>

                    <a href="{{ route('admin.products.index') }}"
                       class="flex items-center px-4 py-3 text-sm rounded-lg transition-all {{ request()->routeIs('admin.products.*') ? 'bg-gradient-to-r from-primary-500 to-primary-600 text-white shadow-md' : 'text-gray-300 hover:bg-dark-200 hover:text-white' }}">
                        <i class="fas fa-box w-5 mr-3"></i>
                        Produits
                    </a>

                    <a href="{{ route('admin.import-countries.index') }}"
                       class="flex items-center px-4 py-3 text-sm rounded-lg transition-all {{ request()->routeIs('admin.import-countries.*') ? 'bg-gradient-to-r from-primary-500 to-primary-600 text-white shadow-md' : 'text-gray-300 hover:bg-dark-200 hover:text-white' }}">
                        <i class="fas fa-globe w-5 mr-3"></i>
                        Pays importés
                    </a>

                    <a href="{{ route('admin.settings.categories') }}"
                       class="flex items-center px-4 py-3 text-sm rounded-lg transition-all {{ request()->routeIs('admin.settings.categories*') ? 'bg-gradient-to-r from-primary-500 to-primary-600 text-white shadow-md' : 'text-gray-300 hover:bg-dark-200 hover:text-white' }}">
                        <i class="fas fa-th-large w-5 mr-3"></i>
                        Catégories
                    </a>

                    <a href="{{ route('admin.packages.index') }}"
                       class="flex items-center px-4 py-3 text-sm rounded-lg transition-all {{ request()->routeIs('admin.packages.*') ? 'bg-gradient-to-r from-primary-500 to-primary-600 text-white shadow-md' : 'text-gray-300 hover:bg-dark-200 hover:text-white' }}">
                        <i class="fas fa-cube w-5 mr-3"></i>
                        Packages
                    </a>

                    <a href="{{ route('admin.transactions.index') }}"
                       class="flex items-center px-4 py-3 text-sm rounded-lg transition-all {{ request()->routeIs('admin.transactions.*') ? 'bg-gradient-to-r from-primary-500 to-primary-600 text-white shadow-md' : 'text-gray-300 hover:bg-dark-200 hover:text-white' }}">
                        <i class="fas fa-exchange-alt w-5 mr-3"></i>
                        Transactions
                    </a>

                    <a href="{{ route('admin.exchanges.index') }}"
                       class="flex items-center px-4 py-3 text-sm rounded-lg transition-all {{ request()->routeIs('admin.exchanges.*') ? 'bg-gradient-to-r from-primary-500 to-primary-600 text-white shadow-md' : 'text-gray-300 hover:bg-dark-200 hover:text-white' }}">
                        <i class="fas fa-sync-alt w-5 mr-3"></i>
                        Échanges
                    </a>

                    <a href="{{ route('admin.map.index') }}"
                       class="flex items-center px-4 py-3 text-sm rounded-lg transition-all {{ request()->routeIs('admin.map.*') ? 'bg-gradient-to-r from-primary-500 to-primary-600 text-white shadow-md' : 'text-gray-300 hover:bg-dark-200 hover:text-white' }}">
                        <i class="fas fa-map-marked-alt w-5 mr-3"></i>
                        Carte
                    </a>

                    <a href="{{ route('admin.support.index') }}"
                       class="flex items-center px-4 py-3 text-sm rounded-lg transition-all {{ request()->routeIs('admin.support.*') ? 'bg-gradient-to-r from-primary-500 to-primary-600 text-white shadow-md' : 'text-gray-300 hover:bg-dark-200 hover:text-white' }}">
                        <i class="fas fa-life-ring w-5 mr-3"></i>
                        Support
                    </a>
                </div>

                <!-- Section DIASPO -->
                <div class="mt-8 pt-6 border-t border-dark-200">
                    <p class="px-4 text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">DIASPO Exchange</p>

                    <a href="{{ route('admin.diaspo.verifications.index') }}"
                       class="flex items-center justify-between px-4 py-3 text-sm rounded-lg transition-all {{ request()->routeIs('admin.diaspo.verifications.*') ? 'bg-gradient-to-r from-primary-500 to-primary-600 text-white shadow-md' : 'text-gray-300 hover:bg-dark-200 hover:text-white' }}">
                        <span class="flex items-center">
                            <i class="fas fa-id-card w-5 mr-3"></i>
                            Vérifications
                        </span>
                        @if(isset($pendingDiaspoVerifications) && $pendingDiaspoVerifications > 0)
                            <span class="flex items-center justify-center min-w-[1.5rem] h-6 px-2 bg-yellow-500 text-dark-100 text-xs font-bold rounded-full animate-pulse">
                                {{ $pendingDiaspoVerifications }}
                            </span>
                        @endif
                    </a>

                    <a href="{{ route('admin.diaspo.posts.index') }}"
                       class="flex items-center px-4 py-3 text-sm rounded-lg transition-all {{ request()->routeIs('admin.diaspo.posts.*') ? 'bg-gradient-to-r from-primary-500 to-primary-600 text-white shadow-md' : 'text-gray-300 hover:bg-dark-200 hover:text-white' }}">
                        <i class="fas fa-comments w-5 mr-3"></i>
                        Posts
                    </a>

                    <a href="{{ route('admin.diaspo.offers.index') }}"
                       class="flex items-center px-4 py-3 text-sm rounded-lg transition-all {{ request()->routeIs('admin.diaspo.offers.*') ? 'bg-gradient-to-r from-primary-500 to-primary-600 text-white shadow-md' : 'text-gray-300 hover:bg-dark-200 hover:text-white' }}">
                        <i class="fas fa-plane-departure w-5 mr-3"></i>
                        Offres (Kilos)
                    </a>
                </div>

                <!-- Section Affiliation -->
                <div class="mt-8 pt-6 border-t border-dark-200">
                    <p class="px-4 text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Affiliation</p>

                    <a href="{{ route('admin.affiliate.settings') }}"
                       class="flex items-center px-4 py-3 text-sm rounded-lg transition-all {{ request()->routeIs('admin.affiliate.settings') ? 'bg-gradient-to-r from-primary-500 to-primary-600 text-white shadow-md' : 'text-gray-300 hover:bg-dark-200 hover:text-white' }}">
                        <i class="fas fa-cog w-5 mr-3"></i>
                        Configuration
                    </a>

                    <a href="{{ route('admin.affiliate.tree') }}"
                       class="flex items-center px-4 py-3 text-sm rounded-lg transition-all {{ request()->routeIs('admin.affiliate.tree') ? 'bg-gradient-to-r from-primary-500 to-primary-600 text-white shadow-md' : 'text-gray-300 hover:bg-dark-200 hover:text-white' }}">
                        <i class="fas fa-sitemap w-5 mr-3"></i>
                        Arbre Réseau
                    </a>

                    <a href="{{ route('admin.affiliate.commissions') }}"
                       class="flex items-center px-4 py-3 text-sm rounded-lg transition-all {{ request()->routeIs('admin.affiliate.commissions') ? 'bg-gradient-to-r from-primary-500 to-primary-600 text-white shadow-md' : 'text-gray-300 hover:bg-dark-200 hover:text-white' }}">
                        <i class="fas fa-money-bill-wave w-5 mr-3"></i>
                        Commissions
                    </a>
                </div>

                <!-- Section Annonce -->
                <div class="mt-8 pt-6 border-t border-dark-200">
                    <p class="px-4 text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Annonce</p>

                    <a href="{{ route('admin.banners.index') }}"
                       class="flex items-center px-4 py-3 text-sm rounded-lg transition-all {{ request()->routeIs('admin.banners.*') ? 'bg-gradient-to-r from-primary-500 to-primary-600 text-white shadow-md' : 'text-gray-300 hover:bg-dark-200 hover:text-white' }}">
                        <i class="fas fa-ad w-5 mr-3"></i>
                        Bannières
                    </a>

                    <a href="{{ route('admin.announcements.index') }}"
                       class="flex items-center px-4 py-3 text-sm rounded-lg transition-all {{ request()->routeIs('admin.announcements.*') ? 'bg-gradient-to-r from-primary-500 to-primary-600 text-white shadow-md' : 'text-gray-300 hover:bg-dark-200 hover:text-white' }}">
                        <i class="fas fa-bullhorn w-5 mr-3"></i>
                        Annonce Générale
                    </a>
                </div>

                <!-- Section Outils -->
                <div class="mt-8 pt-6 border-t border-dark-200">
                    <p class="px-4 text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Outils</p>

                    <a href="{{ route('admin.documents.index') }}"
                       class="flex items-center px-4 py-3 text-sm rounded-lg transition-all {{ request()->routeIs('admin.documents.*') ? 'bg-gradient-to-r from-primary-500 to-primary-600 text-white shadow-md' : 'text-gray-300 hover:bg-dark-200 hover:text-white' }}">
                        <i class="fas fa-folder-open w-5 mr-3"></i>
                        Documents
                    </a>

                    <a href="{{ route('admin.database.index') }}"
                       class="flex items-center px-4 py-3 text-sm rounded-lg transition-all {{ request()->routeIs('admin.database.*') ? 'bg-gradient-to-r from-primary-500 to-primary-600 text-white shadow-md' : 'text-gray-300 hover:bg-dark-200 hover:text-white' }}">
                        <i class="fas fa-database w-5 mr-3"></i>
                        Database SQL
                    </a>

                    <a href="{{ route('admin.vault.index') }}"
                       class="flex items-center px-4 py-3 text-sm rounded-lg transition-all {{ request()->routeIs('admin.vault.*') ? 'bg-gradient-to-r from-primary-500 to-primary-600 text-white shadow-md' : 'text-gray-300 hover:bg-dark-200 hover:text-white' }}">
                        <i class="fas fa-lock w-5 mr-3"></i>
                        Vault (Credentials)
                    </a>

                    <a href="{{ route('admin.fcm-tokens.index') }}"
                       class="flex items-center px-4 py-3 text-sm rounded-lg transition-all {{ request()->routeIs('admin.fcm-tokens.*') ? 'bg-gradient-to-r from-primary-500 to-primary-600 text-white shadow-md' : 'text-gray-300 hover:bg-dark-200 hover:text-white' }}">
                        <i class="fas fa-bell w-5 mr-3"></i>
                        FCM Tokens
                    </a>
                </div>

                <!-- Section Administration -->
                <div class="mt-8 pt-6 border-t border-dark-200">
                    <p class="px-4 text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Administration</p>

                    <a href="{{ route('admin.settings.maintenance') }}"
                       class="flex items-center px-4 py-3 text-sm rounded-lg transition-all {{ request()->routeIs('admin.settings.maintenance*') ? 'bg-gradient-to-r from-primary-500 to-primary-600 text-white shadow-md' : 'text-gray-300 hover:bg-dark-200 hover:text-white' }}">
                        <i class="fas fa-tools w-5 mr-3"></i>
                        Maintenance
                    </a>

                    <a href="{{ route('admin.settings.index') }}"
                       class="flex items-center px-4 py-3 text-sm rounded-lg transition-all {{ request()->routeIs('admin.settings.index') ? 'bg-gradient-to-r from-primary-500 to-primary-600 text-white shadow-md' : 'text-gray-300 hover:bg-dark-200 hover:text-white' }}">
                        <i class="fas fa-cog w-5 mr-3"></i>
                        Paramètres
                    </a>

                    <a href="{{ route('admin.settings.payments') }}"
                       class="flex items-center px-4 py-3 text-sm rounded-lg transition-all {{ request()->routeIs('admin.settings.payments*') ? 'bg-gradient-to-r from-primary-500 to-primary-600 text-white shadow-md' : 'text-gray-300 hover:bg-dark-200 hover:text-white' }}">
                        <i class="fas fa-credit-card w-5 mr-3"></i>
                        Paiements
                    </a>

                    <a href="{{ route('admin.settings.services') }}"
                       class="flex items-center px-4 py-3 text-sm rounded-lg transition-all {{ request()->routeIs('admin.settings.services*') ? 'bg-gradient-to-r from-primary-500 to-primary-600 text-white shadow-md' : 'text-gray-300 hover:bg-dark-200 hover:text-white' }}">
                        <i class="fas fa-plug w-5 mr-3"></i>
                        Services
                    </a>

                    <a href="{{ route('admin.otp-bypass.index') }}"
                       class="flex items-center px-4 py-3 text-sm rounded-lg transition-all {{ request()->routeIs('admin.otp-bypass.*') ? 'bg-gradient-to-r from-primary-500 to-primary-600 text-white shadow-md' : 'text-gray-300 hover:bg-dark-200 hover:text-white' }}">
                        <i class="fas fa-mobile-alt w-5 mr-3"></i>
                        Bypass OTP
                    </a>

                    <a href="{{ route('admin.legal-pages.index') }}"
                       class="flex items-center px-4 py-3 text-sm rounded-lg transition-all {{ request()->routeIs('admin.legal-pages.*') ? 'bg-gradient-to-r from-primary-500 to-primary-600 text-white shadow-md' : 'text-gray-300 hover:bg-dark-200 hover:text-white' }}">
                        <i class="fas fa-file-contract w-5 mr-3"></i>
                        Pages Légales
                    </a>
                </div>
            </nav>

            <!-- User Info at bottom -->
            <div class="p-4 border-t border-dark-200 flex-shrink-0">
                <div class="flex items-center">
                    <div class="w-10 h-10 rounded-full flex items-center justify-center overflow-hidden flex-shrink-0 bg-gradient-to-br from-primary-500 to-primary-600 shadow-md">
                        <span class="text-white font-bold">{{ strtoupper(substr(auth()->user()->name ?? 'A', 0, 1)) }}</span>
                    </div>
                    <div class="ml-3 flex-1">
                        <p class="text-sm font-medium text-white">{{ auth()->user()->name ?? 'Admin' }}</p>
                        <p class="text-xs text-gray-400">Administrateur</p>
                    </div>
                    <form action="{{ route('admin.logout') }}" method="POST">
                        @csrf
                        <button type="submit" class="text-gray-400 hover:text-primary-500 transition-colors" title="Déconnexion">
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
                <div class="flex items-center">
                    <button @click="sidebarOpen = true" class="lg:hidden text-gray-300 hover:text-white mr-4">
                        <i class="fas fa-bars text-xl"></i>
                    </button>
                    <h1 class="text-xl font-semibold text-white">@yield('header', 'Dashboard')</h1>
                </div>

                <div class="flex items-center space-x-4">
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
                <!-- Flash Messages -->
                @if(session('success'))
                    <div class="mb-6 bg-green-50 border-l-4 border-green-500 text-green-700 px-4 py-3 rounded-lg flex items-center justify-between shadow-sm" role="alert">
                        <div class="flex items-center w-full">
                            <i class="fas fa-check-circle mr-2 text-green-500 flex-shrink-0"></i>
                            <div class="flex-1">{!! session('success') !!}</div>
                        </div>
                        <button onclick="this.parentElement.remove()" class="text-green-700 hover:text-green-900 ml-4 flex-shrink-0">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                @endif

                @if(session('error'))
                    <div class="mb-6 bg-red-900/20 border-l-4 border-red-500 text-red-400 px-4 py-3 rounded-lg flex items-center justify-between shadow-sm" role="alert">
                        <div class="flex items-center w-full">
                            <i class="fas fa-exclamation-circle mr-2 text-red-500 flex-shrink-0"></i>
                            <div class="flex-1">{!! session('error') !!}</div>
                        </div>
                        <button onclick="this.parentElement.remove()" class="text-red-400 hover:text-red-500 ml-4 flex-shrink-0">
                            <i class="fas fa-times"></i>
                        </button>
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

    <!-- ===== Modale de confirmation personnalisée (remplace window.confirm) ===== -->
    <div id="confirmModal" class="fixed inset-0 z-[100] hidden items-center justify-center bg-black/60 backdrop-blur-sm p-4">
        <div id="confirmModalBox" class="bg-dark-100 border border-dark-200 rounded-2xl shadow-2xl w-full max-w-md p-6 scale-95 opacity-0 transition-all duration-150">
            <div class="flex items-start gap-4">
                <div id="confirmModalIconWrap" class="w-12 h-12 rounded-full bg-red-500/15 text-red-400 flex items-center justify-center flex-shrink-0">
                    <i id="confirmModalIcon" class="fas fa-triangle-exclamation text-xl"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <h3 id="confirmModalTitle" class="text-lg font-bold text-white mb-1">Confirmer l'action</h3>
                    <p id="confirmModalMessage" class="text-sm text-gray-300 leading-relaxed"></p>
                </div>
            </div>
            <div class="flex justify-end gap-3 mt-6">
                <button type="button" id="confirmModalCancel"
                        class="px-4 py-2.5 rounded-lg bg-dark-200 text-gray-200 hover:bg-dark-300 transition-colors font-medium">
                    Annuler
                </button>
                <button type="button" id="confirmModalOk"
                        class="px-5 py-2.5 rounded-lg bg-red-600 text-white hover:bg-red-700 transition-colors font-semibold">
                    Confirmer
                </button>
            </div>
        </div>
    </div>

    <script>
    (function () {
        const modal   = document.getElementById('confirmModal');
        const box     = document.getElementById('confirmModalBox');
        const msgEl   = document.getElementById('confirmModalMessage');
        const okBtn   = document.getElementById('confirmModalOk');
        const cancel  = document.getElementById('confirmModalCancel');
        let onConfirm = null;

        function openModal(message, cb) {
            msgEl.textContent = message || 'Voulez-vous vraiment effectuer cette action ?';
            onConfirm = cb;
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            requestAnimationFrame(() => { box.classList.remove('scale-95', 'opacity-0'); });
            okBtn.focus();
        }
        function closeModal() {
            box.classList.add('scale-95', 'opacity-0');
            onConfirm = null;
            setTimeout(() => { modal.classList.add('hidden'); modal.classList.remove('flex'); }, 150);
        }

        okBtn.addEventListener('click', function () {
            const cb = onConfirm;
            closeModal();
            if (cb) cb();
        });
        cancel.addEventListener('click', closeModal);
        modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !modal.classList.contains('hidden')) closeModal();
        });

        // API publique : window.customConfirm(message, onOk)
        window.customConfirm = openModal;

        // 1) Formulaires avec data-confirm : intercepter la soumission
        document.addEventListener('submit', function (e) {
            const form = e.target;
            if (form.matches && form.matches('form[data-confirm]') && form.dataset.confirmed !== '1') {
                e.preventDefault();
                openModal(form.getAttribute('data-confirm'), function () {
                    form.dataset.confirmed = '1';
                    if (typeof form.requestSubmit === 'function') form.requestSubmit();
                    else form.submit();
                });
            }
        }, true);

        // 2) Liens et boutons avec data-confirm : intercepter le clic
        document.addEventListener('click', function (e) {
            const el = e.target.closest('[data-confirm]');
            if (!el || el.tagName === 'FORM' || el.dataset.confirmed === '1') return;
            e.preventDefault();
            e.stopPropagation();
            openModal(el.getAttribute('data-confirm'), function () {
                el.dataset.confirmed = '1';
                if (el.tagName === 'A' && el.href) {
                    window.location.href = el.href;
                } else if (el.tagName === 'BUTTON') {
                    const f = el.closest('form');
                    if (f) { (typeof f.requestSubmit === 'function') ? f.requestSubmit(el) : f.submit(); }
                    else { el.click(); }
                } else {
                    el.click();
                }
            });
        }, true);
    })();
    </script>

    @stack('scripts')
</body>
</html>
