<?php

namespace App\Services\Suppliers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Télécharge les photos d'un fournisseur sur le disque public d'ASSO : l'API les
 * sert ensuite comme toute photo produit (même cache, mêmes en-têtes CORS).
 *
 * Chaque photo est vérifiée (adresse publique en http(s), sans redirection,
 * 10 Mo au plus, vraie image) puis réencodée en JPEG de 1200 px au plus :
 * le fichier écrit ne contient que des pixels.
 */
class SupplierImageStore
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    public const MAX_SIDE = 1200;

    public const QUALITY = 82;

    /** @var (\Closure(string): list<string>)|null résolution DNS remplaçable dans les tests */
    private static ?\Closure $resolver = null;

    public static function resolveHostsUsing(?\Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    /** @return string chemin à enregistrer dans product_images.image_path (« storage/… ») */
    public function store(string $url, string $directory, string $name): string
    {
        $this->assertPublicUrl($url);

        $response = Http::timeout(20)
            ->withOptions(['allow_redirects' => false])
            ->withHeaders(['Accept' => 'image/*'])
            ->get($url);
        if (! $response->successful()) {
            throw new SupplierImportException("Photo inaccessible (HTTP {$response->status()}) : {$url}");
        }
        $body = $response->body();
        if ($body === '' || strlen($body) > self::MAX_BYTES) {
            throw new SupplierImportException("Photo vide ou trop lourde : {$url}");
        }

        $jpeg = $this->reencode($body) ?? throw new SupplierImportException("Fichier qui n'est pas une image : {$url}");

        $relative = trim($directory, '/').'/'.Str::slug($name).'.jpg';
        Storage::disk('public')->put($relative, $jpeg);

        return 'storage/'.$relative;
    }

    public function deleteDirectory(string $directory): void
    {
        Storage::disk('public')->deleteDirectory(trim($directory, '/'));
    }

    private function reencode(string $bytes): ?string
    {
        $info = @getimagesizefromstring($bytes);
        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
            return null;
        }
        $source = @imagecreatefromstring($bytes);
        if (! $source) {
            return null;
        }

        [$width, $height] = [imagesx($source), imagesy($source)];
        $scale = min(1, self::MAX_SIDE / max($width, $height));
        $w = max(1, (int) round($width * $scale));
        $h = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($w, $h);
        // Fond blanc : les PNG transparents des fournisseurs restent lisibles en JPEG.
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $w, $h, $width, $height);

        ob_start();
        imagejpeg($canvas, null, self::QUALITY);
        $jpeg = (string) ob_get_clean();
        imagedestroy($source);
        imagedestroy($canvas);

        return $jpeg !== '' ? $jpeg : null;
    }

    /** Refuse les adresses locales ou privées (le lien vient d'une API tierce). */
    private function assertPublicUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';
        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['port'])) {
            throw new SupplierImportException("Adresse de photo refusée : {$url}");
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : (self::$resolver ? (self::$resolver)($host) : (gethostbynamel($host) ?: []));
        if ($ips === []) {
            throw new SupplierImportException("Hôte de photo introuvable : {$host}");
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new SupplierImportException("Adresse de photo refusée : {$url}");
            }
        }
    }
}
