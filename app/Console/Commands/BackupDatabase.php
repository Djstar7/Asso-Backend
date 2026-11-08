<?php

namespace App\Console\Commands;

use App\Mail\DatabaseBackupMail;
use App\Services\Backup\GoogleDriveUploader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Number;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

/**
 * Sauvegarde quotidienne de la base de données.
 *
 * 1. Export pg_dump (format custom, compressé), relu par pg_restore et comparé
 *    au nombre de tables de la base : une archive tronquée n'est jamais envoyée.
 * 2. Chiffrement AES-256 dans un ZIP protégé par BACKUP_ZIP_PASSWORD.
 * 3. Copie sur Google Drive, puis e-mail avec l'archive en pièce jointe.
 *    Les deux copies sont indépendantes : l'échec de l'une n'empêche pas l'autre,
 *    et le mail indique le statut de la copie Drive.
 *
 * En cas d'échec de l'export, un mail « ÉCHEC » part à la place de la sauvegarde.
 */
class BackupDatabase extends Command
{
    protected $signature = 'backup:database
        {--no-mail : Ne pas envoyer l\'e-mail}
        {--no-drive : Ne pas copier sur Google Drive}
        {--to=* : Destinataire(s) à la place de BACKUP_MAIL_TO}';

    protected $description = 'Sauvegarde chiffrée de la base, envoyée par e-mail et copiée sur Google Drive';

    public function handle(GoogleDriveUploader $drive): int
    {
        $started = microtime(true);
        $now = now(config('backup.timezone'));
        $base = 'asso_db_' . $now->format('Y-m-d_Hi');
        $dir = config('backup.local.path');
        $dump = "{$dir}/{$base}.dump";
        $zip = "{$dir}/{$base}.zip";

        $report = [
            'status' => 'success',
            'day' => $now->format('d/m/Y'),
            'date' => $now->copy()->locale('fr')->translatedFormat('l j F Y à H:i') . ' (' . config('backup.timezone') . ')',
            'file' => "{$base}.zip",
            'database' => config('database.connections.' . config('database.default') . '.database'),
            'host' => gethostname(),
            'drive' => ['status' => 'disabled'],
            'attached' => false,
        ];

        $this->log('info', "Début de la sauvegarde {$base}");

        try {
            if (blank(config('backup.zip_password'))) {
                throw new RuntimeException('BACKUP_ZIP_PASSWORD est vide : la sauvegarde ne peut pas être chiffrée.');
            }
            if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
                throw new RuntimeException("Impossible de créer le dossier {$dir}.");
            }

            $this->dump($dump);
            $report += $this->verify($dump);
            $this->encrypt($dump, $zip, "{$base}.dump");
            @unlink($dump);

            $report['size'] = Number::fileSize(filesize($zip), precision: 1);
            $report['sha256'] = hash_file('sha256', $zip);
        } catch (Throwable $e) {
            @unlink($dump);
            @unlink($zip);
            $this->log('error', 'Échec de la sauvegarde : ' . $e->getMessage());

            $report['status'] = 'failed';
            $report['error'] = $e->getMessage();
            $report['duration'] = $this->duration($started);
            if (! $this->option('no-mail')) {
                $this->sendMail($report, null);
            }

            return self::FAILURE;
        }

        $this->log('info', "Archive prête : {$report['file']} ({$report['size']}, {$report['tables']} tables)");

        if (! $this->option('no-drive')) {
            $report['drive'] = $this->copyToDrive($drive, $zip, $report['file']);
        }

        $report['duration'] = $this->duration($started);

        $mailed = true;
        if (! $this->option('no-mail')) {
            $maxBytes = config('backup.mail.max_attachment_mb') * 1024 * 1024;
            $report['attached'] = filesize($zip) <= $maxBytes;
            $mailed = $this->sendMail($report, $report['attached'] ? $zip : null);
        }

        $this->pruneLocal($dir, config('backup.local.retention_days'));

        $driveFailed = $report['drive']['status'] === 'failed';
        $this->log($mailed && ! $driveFailed ? 'info' : 'warning', sprintf(
            'Sauvegarde terminée en %s — mail : %s, Drive : %s',
            $report['duration'],
            $this->option('no-mail') ? 'désactivé' : ($mailed ? 'envoyé' : 'ÉCHEC'),
            $report['drive']['status'],
        ));

        return $mailed && ! $driveFailed ? self::SUCCESS : self::FAILURE;
    }

    private function dump(string $target): void
    {
        $db = config('database.connections.' . config('database.default'));
        if (($db['driver'] ?? null) !== 'pgsql') {
            throw new RuntimeException('Seule une base PostgreSQL peut être sauvegardée par cette commande.');
        }

        $process = new Process([
            'pg_dump',
            '--format=custom',
            '--compress=6',
            '--no-password',
            '--host=' . $db['host'],
            '--port=' . $db['port'],
            '--username=' . $db['username'],
            '--file=' . $target,
            $db['database'],
        ], null, ['PGPASSWORD' => (string) $db['password']], null, 1800);

        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('pg_dump : ' . trim($process->getErrorOutput() ?: $process->getOutput()));
        }
        chmod($target, 0600);
    }

    /**
     * Relit l'archive et compare le nombre de tables à celui de la base.
     *
     * @return array{tables: int}
     */
    private function verify(string $dump): array
    {
        $process = new Process(['pg_restore', '--list', $dump], null, null, null, 300);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('Archive illisible (pg_restore) : ' . trim($process->getErrorOutput()));
        }

        $inDump = preg_match_all('/^\d+; \d+ \d+ TABLE (?!DATA )\S+ \S+/m', $process->getOutput());
        $inDatabase = (int) DB::selectOne(
            "select count(*) as n from pg_tables where schemaname not in ('pg_catalog', 'information_schema')"
        )->n;

        if ($inDump === 0 || $inDump < $inDatabase) {
            throw new RuntimeException("Archive incomplète : {$inDump} table(s) sauvegardée(s) sur {$inDatabase}.");
        }

        return ['tables' => $inDump];
    }

    private function encrypt(string $dump, string $zip, string $entry): void
    {
        $archive = new ZipArchive();
        if ($archive->open($zip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Impossible de créer l'archive {$zip}.");
        }

        $archive->setPassword(config('backup.zip_password'));
        $archive->addFile($dump, $entry);
        $archive->setEncryptionName($entry, ZipArchive::EM_AES_256);

        $readme = "LISEZMOI.txt";
        $archive->addFromString($readme, $this->restoreInstructions($entry));
        $archive->setEncryptionName($readme, ZipArchive::EM_AES_256);

        if (! $archive->close()) {
            throw new RuntimeException('Échec de l\'écriture de l\'archive chiffrée.');
        }
        chmod($zip, 0600);

        // Relecture : l'entrée doit se déchiffrer et commencer par la signature pg_dump.
        $check = new ZipArchive();
        if ($check->open($zip, ZipArchive::CHECKCONS) !== true) {
            throw new RuntimeException('Archive chiffrée corrompue.');
        }
        $check->setPassword(config('backup.zip_password'));
        $head = $check->getFromName($entry, 5);
        $check->close();

        if ($head !== 'PGDMP') {
            throw new RuntimeException('L\'archive chiffrée ne se relit pas correctement.');
        }
    }

    /**
     * @return array{status: string, link?: string, error?: string, pruned?: int}
     */
    private function copyToDrive(GoogleDriveUploader $drive, string $zip, string $name): array
    {
        if (! $drive->isConfigured()) {
            $this->log('warning', 'Google Drive non configuré : copie ignorée.');

            return ['status' => 'not_configured'];
        }

        try {
            $file = $drive->upload($zip, $name);
            $this->log('info', "Copie Drive : {$file['link']}");
        } catch (Throwable $e) {
            $this->log('error', 'Copie Drive échouée : ' . $e->getMessage());

            return ['status' => 'failed', 'error' => $e->getMessage()];
        }

        $result = ['status' => 'ok', 'link' => $file['link']];
        try {
            $result['pruned'] = $drive->prune(config('backup.drive.retention_days'));
        } catch (Throwable $e) {
            $this->log('warning', 'Nettoyage Drive échoué : ' . $e->getMessage());
        }

        return $result;
    }

    private function sendMail(array $report, ?string $attachment): bool
    {
        $to = $this->option('to') ?: config('backup.mail.to');
        if (empty($to)) {
            $this->log('error', 'BACKUP_MAIL_TO est vide : aucun e-mail envoyé.');

            return false;
        }

        try {
            Mail::to($to)->send(new DatabaseBackupMail($report, $attachment));
            $this->log('info', 'E-mail envoyé à ' . implode(', ', $to));

            return true;
        } catch (Throwable $e) {
            $this->log('error', 'Envoi de l\'e-mail échoué : ' . $e->getMessage());

            return false;
        }
    }

    private function pruneLocal(string $dir, int $days): void
    {
        $limit = time() - $days * 86400;
        foreach (glob("{$dir}/asso_db_*.{zip,dump}", GLOB_BRACE) ?: [] as $file) {
            if (filemtime($file) < $limit) {
                @unlink($file);
            }
        }
    }

    private function restoreInstructions(string $entry): string
    {
        return <<<TXT
        Sauvegarde ASSO — {$entry}

        Restauration dans une base PostgreSQL vide :

            createdb asso_db_restauree
            pg_restore --no-owner --dbname=asso_db_restauree {$entry}

        Remplacement de la base existante (destructif) :

            pg_restore --clean --if-exists --no-owner --dbname=asso_db {$entry}

        Liste du contenu sans rien restaurer :

            pg_restore --list {$entry}

        TXT;
    }

    private function duration(float $started): string
    {
        return number_format(microtime(true) - $started, 1, ',', ' ') . ' s';
    }

    private function log(string $level, string $message): void
    {
        Log::channel('backup')->{$level}($message);
        $level === 'error' ? $this->error($message) : $this->line($message);
    }
}
