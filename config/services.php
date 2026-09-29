<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Micro-service Asso-Lookup (Python) : fiche produit pré-remplie depuis un
    // scan, sans LLM. Service interne, jamais exposé au mobile.
    'product_lookup' => [
        'url' => env('PRODUCT_LOOKUP_URL', 'http://127.0.0.1:8001'),
        'token' => env('PRODUCT_LOOKUP_TOKEN', ''),
        // Le service s'accorde 8 s de recherche ; marge pour le réseau.
        'timeout' => (int) env('PRODUCT_LOOKUP_TIMEOUT', 12),
    ],

    'exchangerate' => [
        'api_key' => env('EXCHANGERATE_API_KEY', ''),
    ],

    // Conversion des vidéos produits (ProcessProductVideo). Sans ffmpeg, la
    // vidéo reçue est publiée telle quelle, sans affiche ni aperçu léger.
    'ffmpeg' => [
        'ffmpeg' => env('FFMPEG_BINARY', 'ffmpeg'),
        'ffprobe' => env('FFPROBE_BINARY', 'ffprobe'),
        // Doit rester sous le `retry_after` de la file (90 s par défaut) : au-delà,
        // un autre worker reprendrait la même vidéo en parallèle.
        'timeout' => (int) env('FFMPEG_TIMEOUT', 75),
    ],

];
