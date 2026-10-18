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
| Un gestionnaire ne voit que les boutiques ASSO : produits, boutiques,
| commandes et litiges des vendeurs particuliers lui restent invisibles, même
| dans les sections ouvertes (App\Support\AssoScope).
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
        'Boutiques ASSO' => [
            'asso_orders' => 'Commandes des boutiques ASSO',
            'shops' => 'Boutiques',
            'statistics' => 'Statistiques boutiques',
            'wholesale_orders' => 'Commandes en gros',
            'local_orders' => 'Commandes locales',
            'deposit_orders' => 'Commandes avec acompte',
            'shipments' => 'Expéditions',
            'disputes' => 'Réclamations / Litiges',
        ],
        'Contenu et réglages' => [
            'categories' => 'Catégories',
            'import_countries' => 'Pays importés',
            'packages' => 'Forfaits',
            'banners' => 'Bannières',
            'announcements' => 'Annonces',
            'legal_pages' => 'Pages légales',
        ],
        'Toute la plateforme' => [
            'dashboard' => 'Dashboard',
            'revenue' => 'Revenus ASSO',
            'users' => 'Utilisateurs',
            'preferences' => 'Préférences',
            'deliverers' => 'Livreurs',
            'delivery_partners' => 'Partenaires logistiques',
            'ads' => 'Asso Ads',
            'transactions' => 'Transactions',
            'exchanges' => 'Échanges',
            'map' => 'Carte',
            'support' => 'Support',
            'messages' => 'Messagerie',
            'diaspo' => 'DIASPO Exchange',
            'affiliate' => 'Affiliation',
            'sales' => 'Commerciaux',
            'documents' => 'Documents',
            'fcm_tokens' => 'FCM Tokens',
            'stripe' => 'Comptes de virement',
        ],
    ],

    // Avertissement affiché sous un groupe du formulaire.
    'permission_notes' => [
        'Produits' => 'Limité aux boutiques ASSO.',
        'Boutiques ASSO' => 'Limité aux boutiques ASSO : rien des vendeurs particuliers n\'apparaît.',
        'Toute la plateforme' => 'Attention : ces sections affichent les données de tous les clients et vendeurs (comptes, paiements, messages, chiffres globaux). À n\'ouvrir qu\'en cas de besoin.',
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
        'admin.revenue.*' => 'revenue',
        'admin.users.*' => 'users',
        'admin.preferences.*' => 'preferences',
        'admin.deliverers.*' => 'deliverers',
        'admin.delivery-partners.*' => 'delivery_partners',
        'admin.shipments.*' => 'shipments',
        'admin.shops.*' => 'shops',
        'admin.statistics.*' => 'statistics',
        'admin.import-countries.*' => 'import_countries',
        'admin.wholesale-orders.*' => 'wholesale_orders',
        'admin.asso-orders.*' => 'asso_orders',
        'admin.local-orders.*' => 'local_orders',
        'admin.deposit-orders.*' => 'deposit_orders',
        'admin.disputes.*' => 'disputes',
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
        'admin.maritime.*' => 'diaspo',
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
        'admin.guide',
        'admin.guide.image',
    ],

    // Page d'arrivée d'un gestionnaire : première section accessible.
    'home_routes' => [
        'admin.dashboard',
        'admin.asso-orders.index',
        'admin.products.index',
        'admin.shops.index',
        'admin.users.index',
        'admin.wholesale-orders.index',
        'admin.local-orders.index',
        'admin.deposit-orders.index',
        'admin.disputes.index',
        'admin.transactions.index',
        'admin.support.index',
        'admin.messages.index',
    ],

];
