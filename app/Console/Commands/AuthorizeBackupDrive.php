<?php

namespace App\Console\Commands;

use App\Services\Backup\GoogleDriveUploader;
use Illuminate\Console\Command;
use Throwable;

/**
 * Autorisation unique du serveur sur le Google Drive qui reçoit les sauvegardes.
 *
 * Sans `--code` : affiche le lien à ouvrir, connecté au compte Google choisi.
 * Après acceptation, le navigateur part vers http://localhost/?code=… (la page
 * ne s'affiche pas, c'est normal) : copier toute l'adresse et la passer à `--code`.
 */
class AuthorizeBackupDrive extends Command
{
    protected $signature = 'backup:drive-authorize {--code= : Code (ou adresse complète) renvoyé par Google}';

    protected $description = 'Relie le compte Google Drive qui reçoit les sauvegardes de la base';

    public function handle(GoogleDriveUploader $drive): int
    {
        if (blank(config('backup.drive.client_id')) || blank(config('backup.drive.client_secret'))) {
            $this->error('Renseignez GOOGLE_DRIVE_CLIENT_ID et GOOGLE_DRIVE_CLIENT_SECRET dans .env puis lancez php artisan config:cache.');

            return self::FAILURE;
        }

        $code = (string) $this->option('code');
        if ($code === '') {
            $this->line('1. Ouvrez ce lien, connecté au compte Google qui recevra les sauvegardes :');
            $this->newLine();
            $this->line($drive->authorizationUrl());
            $this->newLine();
            $this->line('2. Acceptez. Le navigateur affiche une erreur sur « localhost » : c\'est normal.');
            $this->line('3. Copiez toute l\'adresse de la page puis lancez :');
            $this->line('   php artisan backup:drive-authorize --code="<adresse copiée>"');

            return self::SUCCESS;
        }

        if (str_contains($code, 'code=')) {
            parse_str((string) parse_url($code, PHP_URL_QUERY), $query);
            $code = $query['code'] ?? '';
        }

        try {
            $refresh = $drive->exchangeCode($code);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Compte Google relié. Ajoutez cette ligne dans .env puis lancez php artisan config:cache :');
        $this->newLine();
        $this->line("GOOGLE_DRIVE_REFRESH_TOKEN={$refresh}");

        return self::SUCCESS;
    }
}
