<?php

namespace App\Http\Controllers;

use App\Support\LinkSettings;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sert l'espace vendeur web à l'adresse /vendor.
 *
 * Les fichiers compilés sont dans public/vendor-web, déposés au déploiement et
 * absents du dépôt. Le serveur web les sert directement ; seule cette page passe
 * par Laravel, pour suivre l'interrupteur et ne jamais rester en cache.
 */
class VendorWebController extends Controller
{
    public function show(): Response
    {
        abort_unless(LinkSettings::webVendorEnabled(), 404);

        $page = public_path('vendor-web/index.html');
        abort_unless(is_file($page), 404);

        return response()->file($page, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            // Un QR affiché dans le cadre d'une page tierce servirait à hameçonner.
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
