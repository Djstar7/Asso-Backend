<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;

/**
 * Résolution du COMPTE SUPPORT ASSO — l'interlocuteur des clients depuis la commande
 * en gros (et plus largement le canal de support in-app).
 *
 * Source de vérité : setting `support_user_id` (configurable en admin / seeder), avec
 * repli sur l'utilisateur d'email support@asso.app. Consommé par :
 *   - l'API mobile (exposition de l'id pour démarrer une conversation support),
 *   - l'interface de messagerie admin (fil des conversations impliquant le support).
 */
class SupportService
{
    public const EMAIL = 'support@asso.app';

    /**
     * Utilisateur support (ou null si non provisionné).
     */
    public static function user(): ?User
    {
        $id = Setting::get('support_user_id');

        $user = $id ? User::find($id) : null;

        if (!$user) {
            $user = User::where('email', self::EMAIL)->first();
        }

        return $user;
    }

    /**
     * Id de l'utilisateur support (ou null).
     */
    public static function userId(): ?int
    {
        return self::user()?->id;
    }
}
