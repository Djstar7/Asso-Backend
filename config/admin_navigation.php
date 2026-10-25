<?php

// Navigation only: route authorization remains in admin_access.php and the middleware.
return [
    [
        'id' => 'overview', 'icon' => 'fa-chart-pie',
        'items' => [
            ['id' => 'dashboard', 'route' => 'admin.dashboard', 'icon' => 'fa-chart-pie', 'match' => ['admin.dashboard']],
            ['id' => 'statistics', 'route' => 'admin.statistics.index', 'icon' => 'fa-chart-line', 'match' => ['admin.statistics.*']],
        ],
    ],
    [
        'id' => 'orders', 'icon' => 'fa-shopping-bag',
        'items' => [
            ['id' => 'asso_orders', 'route' => 'admin.asso-orders.index', 'icon' => 'fa-shopping-bag', 'match' => ['admin.asso-orders.*'], 'badge' => 'assoToConfirmCount'],
            ['id' => 'local_orders', 'keywords' => 'admin_nav.keywords.local_orders', 'route' => 'admin.local-orders.index', 'icon' => 'fa-store-alt', 'match' => ['admin.local-orders.*']],
            ['id' => 'wholesale_orders', 'route' => 'admin.wholesale-orders.index', 'icon' => 'fa-dolly', 'match' => ['admin.wholesale-orders.*'], 'badge' => 'wholesaleToValidateCount'],
            ['id' => 'deposit_orders', 'route' => 'admin.deposit-orders.index', 'icon' => 'fa-hand-holding-usd', 'match' => ['admin.deposit-orders.*'], 'badge' => 'depositToContactCount'],
        ],
    ],
    [
        'id' => 'catalog', 'icon' => 'fa-store',
        'items' => [
            ['id' => 'shops', 'route' => 'admin.shops.index', 'icon' => 'fa-store', 'match' => ['admin.shops.*'], 'badge' => 'pendingShopsCount'],
            ['id' => 'products', 'route' => 'admin.products.index', 'icon' => 'fa-box', 'match' => ['admin.products.*'], 'filter' => 'all_products'],
            ['id' => 'asso_products', 'route' => 'admin.products.index', 'icon' => 'fa-tags', 'match' => ['admin.products.*'], 'parameters' => ['asso' => 1], 'filter' => 'asso_products'],
            ['id' => 'categories', 'route' => 'admin.settings.categories', 'icon' => 'fa-th-large', 'match' => ['admin.settings.categories*', 'admin.settings.subcategories*']],
            ['id' => 'import_countries', 'route' => 'admin.import-countries.index', 'icon' => 'fa-globe', 'match' => ['admin.import-countries.*']],
            ['id' => 'packages', 'route' => 'admin.packages.index', 'icon' => 'fa-cube', 'match' => ['admin.packages.*']],
        ],
    ],
    [
        'id' => 'delivery', 'icon' => 'fa-truck',
        'items' => [
            ['id' => 'shipments', 'keywords' => 'admin_nav.keywords.shipments', 'route' => 'admin.shipments.index', 'icon' => 'fa-shipping-fast', 'match' => ['admin.shipments.*']],
            ['id' => 'deliverers', 'route' => 'admin.deliverers.index', 'icon' => 'fa-truck', 'match' => ['admin.deliverers.*']],
            ['id' => 'delivery_partners', 'route' => 'admin.delivery-partners.index', 'icon' => 'fa-route', 'match' => ['admin.delivery-partners.*']],
            ['id' => 'map', 'route' => 'admin.map.index', 'icon' => 'fa-map-marked-alt', 'match' => ['admin.map.*']],
        ],
    ],
    [
        'id' => 'diaspo', 'icon' => 'fa-plane-departure',
        'items' => [
            ['id' => 'diaspo_verifications', 'route' => 'admin.diaspo.verifications.index', 'icon' => 'fa-id-card', 'match' => ['admin.diaspo.verifications.*'], 'badge' => 'pendingDiaspoVerifications'],
            ['id' => 'air_offers', 'route' => 'admin.diaspo.offers.index', 'icon' => 'fa-plane-departure', 'match' => ['admin.diaspo.offers.*']],
            ['id' => 'freight_partners', 'route' => 'admin.maritime.index', 'icon' => 'fa-ship', 'match' => ['admin.maritime.index', 'admin.maritime.partners.*', 'admin.maritime.hubs.*', 'admin.maritime.departures.*', 'admin.maritime.freight-rates.*', 'admin.partner-payouts.*']],
            ['id' => 'freight_bookings', 'route' => 'admin.maritime.bookings.index', 'icon' => 'fa-boxes', 'match' => ['admin.maritime.bookings.*', 'admin.maritime.claims.*']],
            ['id' => 'freight_inquiries', 'route' => 'admin.maritime.inquiries.index', 'icon' => 'fa-file-invoice', 'match' => ['admin.maritime.inquiries.*']],
            ['id' => 'parcel_natures', 'route' => 'admin.diaspo.parcel-natures.index', 'icon' => 'fa-shield-alt', 'match' => ['admin.diaspo.parcel-natures.*']],
        ],
    ],
    [
        'id' => 'real_estate', 'icon' => 'fa-home',
        'items' => [
            ['id' => 'listings', 'route' => 'admin.real-estate.listings.index', 'icon' => 'fa-home', 'match' => ['admin.real-estate.listings.*']],
            ['id' => 'landlords', 'route' => 'admin.real-estate.verifications.index', 'icon' => 'fa-user-check', 'match' => ['admin.real-estate.verifications.*']],
            ['id' => 'cabinets', 'route' => 'admin.real-estate.cabinets.index', 'icon' => 'fa-landmark', 'match' => ['admin.real-estate.cabinets.*']],
            ['id' => 'property_disputes', 'route' => 'admin.real-estate.disputes.index', 'icon' => 'fa-gavel', 'match' => ['admin.real-estate.disputes.*'], 'tone' => 'danger'],
            ['id' => 'property_settings', 'route' => 'admin.real-estate.settings.index', 'icon' => 'fa-sliders-h', 'match' => ['admin.real-estate.settings.*']],
        ],
    ],
    [
        'id' => 'finance', 'icon' => 'fa-coins',
        'items' => [
            ['id' => 'revenue', 'route' => 'admin.revenue.index', 'icon' => 'fa-coins', 'match' => ['admin.revenue.index', 'admin.revenue.show', 'admin.revenue.export']],
            ['id' => 'withdrawals', 'route' => 'admin.revenue.withdrawal', 'icon' => 'fa-money-bill-transfer', 'match' => ['admin.revenue.withdrawal', 'admin.revenue.withdrawal.*']],
            ['id' => 'transactions', 'route' => 'admin.transactions.index', 'icon' => 'fa-exchange-alt', 'match' => ['admin.transactions.*']],
            ['id' => 'stripe_accounts', 'route' => 'admin.stripe.accounts.index', 'icon' => 'fa-university', 'match' => ['admin.stripe.accounts.*'], 'badge' => 'pendingStripeAccounts'],
        ],
    ],
    [
        'id' => 'customer', 'icon' => 'fa-life-ring',
        'items' => [
            ['id' => 'disputes', 'route' => 'admin.disputes.index', 'icon' => 'fa-balance-scale', 'match' => ['admin.disputes.*'], 'badge' => 'newDisputesCount', 'tone' => 'danger'],
            ['id' => 'support', 'route' => 'admin.support.index', 'icon' => 'fa-life-ring', 'match' => ['admin.support.*']],
            ['id' => 'messages', 'route' => 'admin.messages.index', 'icon' => 'fa-comments', 'match' => ['admin.messages.*']],
            ['id' => 'exchanges', 'route' => 'admin.exchanges.index', 'icon' => 'fa-eye', 'match' => ['admin.exchanges.*']],
        ],
    ],
    [
        'id' => 'marketing', 'icon' => 'fa-bullhorn',
        'items' => [
            ['id' => 'ads', 'route' => 'admin.ads.index', 'icon' => 'fa-bullhorn', 'match' => ['admin.ads.*']],
            ['id' => 'banners', 'route' => 'admin.banners.index', 'icon' => 'fa-ad', 'match' => ['admin.banners.*']],
            ['id' => 'announcements', 'route' => 'admin.announcements.index', 'icon' => 'fa-bullhorn', 'match' => ['admin.announcements.*']],
            ['id' => 'preferences', 'route' => 'admin.preferences.index', 'icon' => 'fa-heart', 'match' => ['admin.preferences.*']],
            ['id' => 'community', 'route' => 'admin.diaspo.posts.index', 'icon' => 'fa-comment-dots', 'match' => ['admin.diaspo.posts.*']],
            ['id' => 'affiliate_tree', 'route' => 'admin.affiliate.tree', 'icon' => 'fa-sitemap', 'match' => ['admin.affiliate.tree', 'admin.affiliate.user*']],
            ['id' => 'affiliate_commissions', 'route' => 'admin.affiliate.commissions', 'icon' => 'fa-money-bill-wave', 'match' => ['admin.affiliate.commissions*']],
            ['id' => 'affiliate_settings', 'route' => 'admin.affiliate.settings', 'icon' => 'fa-cog', 'match' => ['admin.affiliate.settings', 'admin.affiliate.update-settings']],
            ['id' => 'sales_agents', 'route' => 'admin.sales.agents.index', 'icon' => 'fa-user-tie', 'match' => ['admin.sales.agents.*']],
            ['id' => 'sales_commissions', 'route' => 'admin.sales.commissions.index', 'icon' => 'fa-hand-holding-usd', 'match' => ['admin.sales.commissions.*']],
        ],
    ],
    [
        'id' => 'administration', 'icon' => 'fa-user-shield',
        'items' => [
            ['id' => 'users', 'route' => 'admin.users.index', 'icon' => 'fa-users', 'match' => ['admin.users.*']],
            ['id' => 'managers', 'route' => 'admin.managers.index', 'icon' => 'fa-user-shield', 'match' => ['admin.managers.*']],
            ['id' => 'settings', 'route' => 'admin.settings.index', 'icon' => 'fa-cog', 'match' => ['admin.settings.index', 'admin.settings.update', 'admin.settings.account.*']],
            ['id' => 'payment_settings', 'route' => 'admin.settings.payments', 'icon' => 'fa-credit-card', 'match' => ['admin.settings.payments*']],
            ['id' => 'services', 'route' => 'admin.settings.services', 'icon' => 'fa-plug', 'match' => ['admin.settings.services*']],
            ['id' => 'maintenance', 'route' => 'admin.settings.maintenance', 'icon' => 'fa-tools', 'match' => ['admin.settings.maintenance*']],
            ['id' => 'legal_pages', 'route' => 'admin.legal-pages.index', 'icon' => 'fa-file-contract', 'match' => ['admin.legal-pages.*']],
            ['id' => 'documents', 'route' => 'admin.documents.index', 'icon' => 'fa-folder-open', 'match' => ['admin.documents.*']],
            ['id' => 'fcm_tokens', 'keywords' => 'admin_nav.keywords.fcm_tokens', 'route' => 'admin.fcm-tokens.index', 'icon' => 'fa-bell', 'match' => ['admin.fcm-tokens.*']],
            ['id' => 'database', 'route' => 'admin.database.index', 'icon' => 'fa-database', 'match' => ['admin.database.*']],
            ['id' => 'vault', 'route' => 'admin.vault.index', 'icon' => 'fa-lock', 'match' => ['admin.vault.*']],
            ['id' => 'otp_bypass', 'route' => 'admin.otp-bypass.index', 'icon' => 'fa-mobile-alt', 'match' => ['admin.otp-bypass.*']],
        ],
    ],
    [
        'id' => 'help', 'icon' => 'fa-book-open',
        'items' => [
            ['id' => 'guide', 'route' => 'admin.guide', 'icon' => 'fa-book-open', 'match' => ['admin.guide']],
        ],
    ],
];
