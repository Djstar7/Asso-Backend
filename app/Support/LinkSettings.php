<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;

/**
 * Interrupteurs des liens partagés, modifiables depuis le back-office sans
 * déploiement ni nouvelle version de l'application.
 *
 * Tous sont coupés par défaut : déployer le code n'active rien pour les
 * utilisateurs. `share_links_enabled` est dans le groupe public `general`, que
 * l'application lit au démarrage ; les autres ne sortent jamais par l'API.
 */
class LinkSettings
{
    public const GROUP = 'links';

    /** Empreinte SHA-256 d'un certificat de signature, telle qu'affichée par la Play Console. */
    public const FINGERPRINT_PATTERN = '/^([0-9A-F]{2}:){31}[0-9A-F]{2}$/';

    /** Chemins remis à l'application. `/produit` en est absent : voir routes/web.php. */
    public const APP_PATHS = ['/p/*', '/b/*'];

    public static function shareEnabled(): bool
    {
        return (bool) Setting::get('share_links_enabled', false);
    }

    public static function associationEnabled(): bool
    {
        return (bool) Setting::get('app_links_association_enabled', false);
    }

    /** @return list<string> */
    public static function androidFingerprints(): array
    {
        $raw = (string) Setting::get('android_sha256_fingerprints', '');

        return collect(preg_split('/[\s,]+/', strtoupper($raw), -1, PREG_SPLIT_NO_EMPTY))
            ->filter(fn (string $value) => preg_match(self::FINGERPRINT_PATTERN, $value) === 1)
            ->unique()
            ->values()
            ->all();
    }

    public static function webVendorEnabled(): bool
    {
        return (bool) Setting::get('web_vendor_login_enabled', false);
    }

    /**
     * Comptes inscrits à l'espace web pendant les essais ; vide : tous les vendeurs.
     *
     * @return list<int>
     */
    public static function webVendorTestUsers(): array
    {
        $raw = (string) Setting::get('web_vendor_test_user_ids', '');

        return collect(preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY))
            ->filter(fn (string $value) => ctype_digit($value))
            ->map(fn (string $value) => (int) $value)
            ->unique()
            ->values()
            ->all();
    }

    public static function webVendorAllows(User $user): bool
    {
        if (! self::webVendorEnabled()) {
            return false;
        }

        $testUsers = self::webVendorTestUsers();

        return $testUsers === [] || in_array($user->id, $testUsers, true);
    }

    /** Fichier d'association Android : vide tant que l'association est coupée. */
    public static function assetLinks(): array
    {
        $fingerprints = self::androidFingerprints();
        if (! self::associationEnabled() || $fingerprints === []) {
            return [];
        }

        return [[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => [
                'namespace' => 'android_app',
                'package_name' => config('app.android_package'),
                'sha256_cert_fingerprints' => $fingerprints,
            ],
        ]];
    }

    /** Fichier d'association Apple : aucun chemin tant que l'association est coupée. */
    public static function appleAssociation(): array
    {
        return ['applinks' => [
            'apps' => [],
            'details' => self::associationEnabled()
                ? [['appID' => config('app.ios_team_id').'.'.config('app.ios_bundle_id'), 'paths' => self::APP_PATHS]]
                : [],
        ]];
    }
}
