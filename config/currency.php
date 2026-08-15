<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Base Currency
    |--------------------------------------------------------------------------
    |
    | The base currency used throughout the application (FCFA - West African CFA franc)
    |
    */
    'base' => 'FCFA',

    /*
    |--------------------------------------------------------------------------
    | Exchange Rates
    |--------------------------------------------------------------------------
    |
    | Exchange rates relative to FCFA (base currency)
    | 1 EUR = 655.957 FCFA (fixed rate for West African CFA franc)
    |
    */
    'rates' => [
        'EUR' => 655.957,
        'FCFA' => 1.0,
        'XOF' => 1.0, // West African CFA franc (same as FCFA)
    ],

    /*
    |--------------------------------------------------------------------------
    | Supported Currencies
    |--------------------------------------------------------------------------
    |
    | List of currencies that can be used in the application
    |
    */
    'supported' => ['FCFA', 'EUR', 'XOF'],
];
