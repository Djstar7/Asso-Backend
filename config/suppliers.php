<?php

/*
| Fournisseurs de dropshipping. Les secrets ne sont pas ici : ils se saisissent
| dans le back-office (Catalogue et boutiques > Fournisseurs) et sont chiffrés
| en base (table suppliers).
*/
return [
    'cj' => [
        'name' => 'CJdropshipping',
        'base_url' => env('CJ_API_BASE_URL', 'https://developers.cjdropshipping.com/api2.0/v1'),
        'timeout' => (int) env('CJ_API_TIMEOUT', 20),
        // Niveau gratuit : 1 requête par seconde. À relever si le compte CJ monte de niveau.
        'requests_per_second' => (int) env('CJ_API_QPS', 1),
        // Nouvelles tentatives après « trop de requêtes » (1600200) ou une erreur réseau.
        'retries' => 2,
        // Jeton renouvelé un peu avant son expiration réelle.
        'token_refresh_margin_hours' => 24,
        'defaults' => [
            'margin_percent' => 25,
            'fx_buffer_percent' => 3,
            'balance_alert_usd' => 100,
            'from_country_code' => 'CN',
            // Destination des colis CJ : l'entrepôt ASSO de Douala.
            'to_country_code' => 'CM',
            // Transporteur CJ préféré (ex. « CJPacket Ordinary ») ; vide = le moins cher.
            'preferred_logistic' => null,
            // Jours ajoutés au délai CJ : réception à Douala, contrôle, remise au livreur.
            'hub_handling_days' => 3,
            'images_per_product' => 4,
            'max_variants' => 100,
        ],
    ],
];
