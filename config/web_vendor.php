<?php

/*
|--------------------------------------------------------------------------
| Espace vendeur web (asso-dashboard.sbs/vendor)
|--------------------------------------------------------------------------
|
| Connexion par QR, comme WhatsApp : le navigateur affiche un code et le scan
| depuis l'application vaut autorisation. Le navigateur reçoit un jeton de courte durée limité aux
| routes ci-dessous (EnsureWebVendorScope). Toute route absente est refusée :
| une nouvelle route reste donc fermée à ce jeton tant qu'on ne l'a pas déclarée.
|
| Volontairement absents : wallet, retraits, paiements, forfaits, achat de
| boosts, choix du partenaire et paiement des courses de litige, modification
| du compte.
|
*/

return [

    'ability' => 'vendor-web',
    'token_name' => 'vendor-web',
    'qr_prefix' => 'asso-vendor-web:',

    // Durée d'un QR, puis délai laissé au navigateur pour récupérer son jeton.
    'session_seconds' => 120,
    'pickup_seconds' => 60,
    'poll_seconds' => 2,

    'token_hours' => 12,

    'routes' => [
        // Compte : lecture, langue et déconnexion seulement.
        'GET api/user',
        'GET api/v1/auth/profile',
        'GET api/v1/auth/preferences',
        'PUT api/v1/auth/locale',
        'POST api/v1/auth/logout',

        // Tableau de bord et forfait en lecture.
        'GET api/v1/vendor/dashboard',
        'GET api/v1/vendor/package/current',
        'GET api/v1/vendor/boosts',
        'GET api/v1/vendor/products/{id}/boost-status',
        'GET api/v1/pricing/preview',

        // Produits.
        'POST api/v1/products',
        'GET api/v1/vendor/products',
        'POST api/v1/vendor/products/{id}',
        'PUT api/v1/vendor/products/{id}',
        'DELETE api/v1/vendor/products/{id}',
        'PUT api/v1/vendor/products/{id}/status',
        'PUT api/v1/vendor/products/{id}/deposit',
        'PUT api/v1/vendor/products/{id}/free-delivery',
        'POST api/v1/vendor/products/{id}/stock',
        'POST api/v1/vendor/product-videos/chunks',
        'GET api/v1/vendor/product-videos/{video}',
        'DELETE api/v1/vendor/product-videos/{video}',
        'GET api/v1/vendor/inventory',
        'POST api/v1/vendor/inventory/entry',

        // Commandes.
        'GET api/v1/vendor/orders',
        'GET api/v1/vendor/orders/check-active',
        'GET api/v1/vendor/orders/delivery-persons',
        'GET api/v1/vendor/orders/{id}',
        'POST api/v1/vendor/orders/{id}/validate',
        'POST api/v1/vendor/orders/{id}/reject',
        'POST api/v1/vendor/orders/{id}/assign-delivery',
        'POST api/v1/vendor/orders/{id}/hand-to-carrier',
        'POST api/v1/vendor/orders/{id}/tracking',

        // Boutique.
        'GET api/v1/vendor/shop',
        'PUT api/v1/vendor/shop',
        'PUT api/v1/vendor/shop/free-delivery',
        'GET api/v1/vendor/shop/location-requests',
        'POST api/v1/vendor/shop/location-requests',
        'GET api/v1/vendor/shops',

        // Réclamations : suivi et réponse. Le partenaire et le paiement de la
        // course restent dans l'application.
        'GET api/v1/vendor/disputes',
        'GET api/v1/vendor/disputes/{id}',
        'POST api/v1/vendor/disputes/{id}/evidence',
        'POST api/v1/vendor/disputes/{id}/replace',
        'POST api/v1/vendor/disputes/{id}/return',
        'POST api/v1/vendor/disputes/shipments/{id}/step',
        'POST api/v1/vendor/disputes/receipts/{receipt}/contest',

        // Statistiques.
        'GET api/v1/vendor/statistics',
        'GET api/v1/vendor/statistics/export',
    ],
];
