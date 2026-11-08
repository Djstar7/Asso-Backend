<?php

/*
 * Sauvegarde quotidienne de la base de données (commande `backup:database`).
 *
 * Chaque nuit à minuit (heure du Cameroun) : export pg_dump, contrôle de
 * l'archive, chiffrement AES-256 dans un ZIP, copie sur Google Drive puis
 * envoi par e-mail avec la sauvegarde en pièce jointe.
 */
return [

    // Heure locale de référence : planification et horodatage des fichiers.
    'timezone' => env('BACKUP_TIMEZONE', 'Africa/Douala'),

    'time' => env('BACKUP_TIME', '00:00'),

    // Mot de passe du ZIP. Sans lui, la sauvegarde est inexploitable : il doit
    // être conservé ailleurs que sur ce serveur.
    'zip_password' => env('BACKUP_ZIP_PASSWORD'),

    'local' => [
        // Sous storage/app/private, donc jamais servi par le web.
        'path' => storage_path('app/private/backups'),
        'retention_days' => (int) env('BACKUP_LOCAL_RETENTION_DAYS', 7),
    ],

    'mail' => [
        'to' => array_values(array_filter(array_map('trim', explode(',', (string) env('BACKUP_MAIL_TO', ''))))),
        // Au-delà, le mail donne le lien Drive au lieu de joindre le fichier.
        'max_attachment_mb' => (int) env('BACKUP_MAIL_MAX_ATTACHMENT_MB', 20),
    ],

    'drive' => [
        'client_id' => env('GOOGLE_DRIVE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_DRIVE_CLIENT_SECRET'),
        'refresh_token' => env('GOOGLE_DRIVE_REFRESH_TOKEN'),
        // Vide : le dossier `folder_name` est créé (ou retrouvé) automatiquement.
        'folder_id' => env('GOOGLE_DRIVE_FOLDER_ID'),
        'folder_name' => env('GOOGLE_DRIVE_FOLDER_NAME', 'Sauvegardes ASSO'),
        'retention_days' => (int) env('BACKUP_DRIVE_RETENTION_DAYS', 30),
        // Redirection d'un client OAuth « Application de bureau ».
        'redirect_uri' => env('GOOGLE_DRIVE_REDIRECT_URI', 'http://localhost'),
    ],
];
