<?php

/*
|--------------------------------------------------------------------------
| Accès au back-office : rôles et permissions par section
|--------------------------------------------------------------------------
|
| Les rôles restent ceux du modèle User (colonnes `role` / `roles`).
| - admin : accès complet, y compris la gestion des gestionnaires.
| - product_manager : section Produits uniquement, plus les sections que
|   l'admin lui ouvre (colonne users.admin_permissions).
|
| Chaque route admin est rattachée à une permission via `routes`. Une route
| nommée absente de cette table reste réservée à l'admin : une nouvelle
| section est donc fermée aux gestionnaires tant qu'on ne l'a pas déclarée.
|
*/

return [

    // Rôles autorisés à se connecter au back-office.
    'staff_roles' => ['admin', 'product_manager'],

    // Rôles que l'admin peut attribuer depuis la section Gestionnaires.
    'manager_roles' => [
        'product_manager' => 'Gestionnaire produits',
    ],

    // Permissions données d'office par le rôle.
    'role_permissions' => [
        'product_manager' => [
            'products.view',
            'products.create',
            'products.update',
            'products.delete',
            'products.toggle_visibility',
        ],
    ],

    // Permissions que l'admin peut accorder, regroupées pour le formulaire.
    'permissions' => [
        'Produits' => [
            'products.view' => 'Consulter les produits',
            'products.create' => 'Ajouter des produits',
            'products.update' => 'Modifier des produits',
            'products.delete' => 'Supprimer des produits',
            'products.toggle_visibility' => 'Masquer / afficher des produits',
        ],
        'Sections du tableau de bord' => [
            'dashboard' => 'Dashboard',
            'users' => 'Utilisateurs',
            'preferences' => 'Préférences',
            'deliverers' => 'Livreurs',
            'delivery_partners' => 'Partenaires logistiques',
            'shipments' => 'Expéditions',
            'shops' => 'Boutiques',
            'statistics' => 'Statistiques boutiques',
            'import_countries' => 'Pays importés',
            'wholesale_orders' => 'Commandes en gros',
            'categories' => 'Catégories',
            'packages' => 'Forfaits',
            'ads' => 'Asso Ads',
            'transactions' => 'Transactions',
            'exchanges' => 'Échanges',
            'map' => 'Carte',
            'support' => 'Support',
            'messages' => 'Messagerie',
            'diaspo' => 'DIASPO Exchange',
            'affiliate' => 'Affiliation',
            'sales' => 'Commerciaux',
            'banners' => 'Bannières',
            'announcements' => 'Annonces',
            'documents' => 'Documents',
            'fcm_tokens' => 'FCM Tokens',
            'stripe' => 'Comptes de virement',
            'legal_pages' => 'Pages légales',
        ],
    ],

    /*
    | Route admin => permission (ou liste : l'une d'elles suffit).
    | Premier motif qui correspond (syntaxe Str::is).
    |
    | Volontairement absents (admin uniquement) : gestionnaires, base de
    | données, coffre-fort, paramètres, paiements, services, maintenance,
    | bypass OTP — ils permettraient de s'attribuer plus de droits.
    */
    'routes' => [
        // Produits
        'admin.products.index' => 'products.view',
        'admin.products.show' => 'products.view',
        'admin.products.create' => 'products.create',
        'admin.products.store' => 'products.create',
        'admin.products.edit' => 'products.update',
        'admin.products.update' => 'products.update',
        'admin.products.images.*' => 'products.update',
        'admin.products.destroy' => 'products.delete',
        'admin.products.toggle-status' => 'products.toggle_visibility',
        'admin.categories.subcategories' => ['products.create', 'products.update'],
        'admin.product-videos.*' => ['products.create', 'products.update'],

        // Sections
        'admin.dashboard' => 'dashboard',
        'admin.users.*' => 'users',
        'admin.preferences.*' => 'preferences',
        'admin.deliverers.*' => 'deliverers',
        'admin.delivery-partners.*' => 'delivery_partners',
        'admin.shipments.*' => 'shipments',
        'admin.shops.*' => 'shops',
        'admin.statistics.*' => 'statistics',
        'admin.import-countries.*' => 'import_countries',
        'admin.wholesale-orders.*' => 'wholesale_orders',
        'admin.settings.categories*' => 'categories',
        'admin.settings.subcategories*' => 'categories',
        'admin.packages.*' => 'packages',
        'admin.ads.*' => 'ads',
        'admin.transactions.*' => 'transactions',
        'admin.exchanges.*' => 'exchanges',
        'admin.map.*' => 'map',
        'admin.support.*' => 'support',
        'admin.messages.*' => 'messages',
        'admin.diaspo.*' => 'diaspo',
        'admin.affiliate.*' => 'affiliate',
        'admin.sales.*' => 'sales',
        'admin.banners.*' => 'banners',
        'admin.announcements.*' => 'announcements',
        'admin.documents.*' => 'documents',
        'admin.fcm-tokens.*' => 'fcm_tokens',
        'admin.stripe.*' => 'stripe',
        'admin.legal-pages.*' => 'legal_pages',
    ],

    // Routes ouvertes à tout membre du back-office connecté.
    'open_routes' => [
        'admin.logout',
    ],

    // Page d'arrivée d'un gestionnaire : première section accessible.
    'home_routes' => [
        'admin.dashboard',
        'admin.products.index',
        'admin.shops.index',
        'admin.users.index',
        'admin.wholesale-orders.index',
        'admin.transactions.index',
        'admin.support.index',
        'admin.messages.index',
    ],

];
