<?php

namespace App\Services\Backup;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Copie des sauvegardes sur Google Drive, via l'API REST (OAuth, jeton de
 * rafraîchissement d'un compte Google).
 *
 * La portée `drive.file` ne donne accès qu'aux fichiers créés par ASSO : le
 * reste du Drive du compte reste invisible pour le serveur.
 */
class GoogleDriveUploader
{
    public const SCOPE = 'https://www.googleapis.com/auth/drive.file';

    private const API = 'https://www.googleapis.com/drive/v3';

    private const UPLOAD = 'https://www.googleapis.com/upload/drive/v3/files';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const FOLDER_MIME = 'application/vnd.google-apps.folder';

    private ?string $accessToken = null;

    public function isConfigured(): bool
    {
        $c = config('backup.drive');

        return filled($c['client_id']) && filled($c['client_secret']) && filled($c['refresh_token']);
    }

    /**
     * Envoie le fichier dans le dossier de sauvegarde.
     *
     * @return array{id: string, link: string}
     */
    public function upload(string $path, string $name): array
    {
        $folderId = $this->folderId();

        $init = $this->http()
            ->withHeaders([
                'X-Upload-Content-Type' => 'application/zip',
                'X-Upload-Content-Length' => (string) filesize($path),
            ])
            ->post(self::UPLOAD . '?uploadType=resumable&fields=id,webViewLink', [
                'name' => $name,
                'parents' => [$folderId],
                'mimeType' => 'application/zip',
            ]);
        $this->ensure($init, 'ouverture de l\'envoi');

        $session = $init->header('Location');
        if (! $session) {
            throw new RuntimeException('Google Drive n\'a pas renvoyé d\'adresse d\'envoi.');
        }

        $stream = fopen($path, 'rb');
        try {
            $put = Http::timeout(600)
                ->withToken($this->token())
                ->withBody($stream, 'application/zip')
                ->put($session);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        $this->ensure($put, 'envoi du fichier');

        $id = (string) $put->json('id');

        return [
            'id' => $id,
            'link' => $put->json('webViewLink') ?: "https://drive.google.com/file/d/{$id}/view",
        ];
    }

    /**
     * Supprime de Drive les sauvegardes plus anciennes que la durée de conservation.
     */
    public function prune(int $days): int
    {
        $before = Carbon::now('UTC')->subDays($days)->format('Y-m-d\TH:i:s');
        $q = sprintf(
            "'%s' in parents and trashed = false and name contains 'asso_db_' and createdTime < '%s'",
            $this->folderId(),
            $before,
        );

        $deleted = 0;
        $pageToken = null;
        do {
            $res = $this->http()->get(self::API . '/files', array_filter([
                'q' => $q,
                'fields' => 'nextPageToken,files(id)',
                'pageSize' => 100,
                'pageToken' => $pageToken,
            ]));
            $this->ensure($res, 'liste des anciennes sauvegardes');

            foreach ($res->json('files', []) as $file) {
                $del = $this->http()->delete(self::API . '/files/' . $file['id']);
                if ($del->successful()) {
                    $deleted++;
                }
            }
            $pageToken = $res->json('nextPageToken');
        } while ($pageToken);

        return $deleted;
    }

    public function authorizationUrl(): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => config('backup.drive.client_id'),
            'redirect_uri' => config('backup.drive.redirect_uri'),
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
        ]);
    }

    /**
     * Échange le code d'autorisation contre un jeton de rafraîchissement.
     */
    public function exchangeCode(string $code): string
    {
        $res = Http::asForm()->timeout(30)->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => config('backup.drive.client_id'),
            'client_secret' => config('backup.drive.client_secret'),
            'redirect_uri' => config('backup.drive.redirect_uri'),
            'grant_type' => 'authorization_code',
        ]);
        $this->ensure($res, 'échange du code d\'autorisation');

        $refresh = $res->json('refresh_token');
        if (! $refresh) {
            throw new RuntimeException('Google n\'a pas renvoyé de jeton de rafraîchissement : retirez l\'accès de l\'application dans le compte Google puis recommencez.');
        }

        return $refresh;
    }

    private function folderId(): string
    {
        if ($id = config('backup.drive.folder_id')) {
            return $id;
        }

        $name = config('backup.drive.folder_name');
        $res = $this->http()->get(self::API . '/files', [
            'q' => sprintf("mimeType = '%s' and name = '%s' and trashed = false", self::FOLDER_MIME, addslashes($name)),
            'fields' => 'files(id)',
            'pageSize' => 1,
        ]);
        $this->ensure($res, 'recherche du dossier');

        if ($found = $res->json('files.0.id')) {
            return $found;
        }

        $created = $this->http()->post(self::API . '/files?fields=id', [
            'name' => $name,
            'mimeType' => self::FOLDER_MIME,
        ]);
        $this->ensure($created, 'création du dossier');

        return (string) $created->json('id');
    }

    private function http()
    {
        return Http::timeout(60)->withToken($this->token())->acceptJson();
    }

    private function token(): string
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        $res = Http::asForm()->timeout(30)->post(self::TOKEN_URL, [
            'client_id' => config('backup.drive.client_id'),
            'client_secret' => config('backup.drive.client_secret'),
            'refresh_token' => config('backup.drive.refresh_token'),
            'grant_type' => 'refresh_token',
        ]);
        $this->ensure($res, 'authentification');

        return $this->accessToken = (string) $res->json('access_token');
    }

    private function ensure($response, string $step): void
    {
        if ($response->successful()) {
            return;
        }

        $detail = $response->json('error_description')
            ?? $response->json('error.message')
            ?? (is_string($response->json('error')) ? $response->json('error') : null)
            ?? mb_substr($response->body(), 0, 300);

        throw new RuntimeException("Google Drive ({$step}) : HTTP {$response->status()} — {$detail}");
    }
}
