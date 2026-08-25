<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SupportService;

class AppController extends Controller
{
    /**
     * App "about" info (fake/static data for testing).
     * GET /v1/app/about
     */
    public function about()
    {
        return response()->json([
            'success' => true,
            // Compte support ASSO : le mobile démarre une conversation vers cet id
            // (module commande en gros, bloc 4.3).
            'support' => $this->supportPayload(),
            'about' => [
                'app_name' => 'ASSO',
                'version' => '1.0.0',
                'build_number' => '1',
                'description' => "ASSO est la marketplace communautaire qui connecte vendeurs, acheteurs et livreurs, avec un espace d'entraide et un service diaspo.",
                'logo' => null,
                'contact' => [
                    'email' => 'contact@asso.app',
                    'phone' => '+229 01 00 00 00',
                    'address' => 'Cotonou, Bénin',
                    'website' => 'https://asso.app',
                ],
                'social' => [
                    'facebook' => 'https://facebook.com/asso',
                    'instagram' => 'https://instagram.com/asso',
                    'twitter' => 'https://twitter.com/asso',
                ],
                'legal' => [
                    'company' => 'ASSO SARL',
                    'copyright' => '© 2026 ASSO. Tous droits réservés.',
                ],
            ],
        ]);
    }

    /**
     * App version / update info (fake/static data for testing).
     * GET /v1/app/version
     */
    public function version()
    {
        return response()->json([
            'success' => true,
            'data' => [
                'version' => '1.0.0',
                'build_number' => '1',
                'min_supported_version' => '1.0.0',
                'force_update' => false,
                'update_available' => false,
                'update_url' => null,
                'release_notes' => 'Version initiale.',
            ],
        ]);
    }

    /**
     * Compte support ASSO (public) — id à utiliser par le mobile pour démarrer une
     * conversation avec le support (POST /v1/conversations avec user_id = support_user_id).
     * GET /v1/app/support
     */
    public function support()
    {
        $support = $this->supportPayload();

        if ($support === null) {
            return response()->json([
                'success' => false,
                'message' => 'Compte support indisponible.',
                'support' => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'support' => $support,
        ]);
    }

    /**
     * Représentation publique du compte support (ou null s'il n'est pas provisionné).
     *
     * @return array<string, mixed>|null
     */
    private function supportPayload(): ?array
    {
        $user = SupportService::user();

        if (!$user) {
            return null;
        }

        return [
            'support_user_id' => $user->id,
            'name' => $user->name,
            'avatar' => $user->avatar,
        ];
    }
}
