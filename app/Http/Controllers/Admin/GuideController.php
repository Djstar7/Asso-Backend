<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Guide d'utilisation du back-office (menu « Aide & guide »).
 *
 * Chaque fiche du guide est rattachée à la route de la section qu'elle
 * décrit : un gestionnaire ne voit que les fiches des sections qui lui sont
 * ouvertes, et ne peut charger que leurs captures.
 */
class GuideController extends Controller
{
    /** Fiches communes à tous les membres du back-office. */
    private const COMMON_PAGES = ['bienvenue', 'connexion', 'interface', 'bonnes-pratiques', 'faq'];

    /** Captures des fiches communes. */
    private const COMMON_SHOTS = ['login', 'manager_login', 'dashboard'];

    /** Fiche => [route de la section, captures de la fiche]. */
    private const PAGES = [
        'dashboard' => ['admin.dashboard', ['dashboard']],
        'revenus' => ['admin.revenue.index', ['revenue']],
        'retrait' => ['admin.revenue.withdrawal', ['revenue_withdrawal']],
        'statistiques' => ['admin.statistics.index', ['statistics']],
        'utilisateurs' => ['admin.users.index', ['users', 'users_7', 'users_create']],
        'gestionnaires' => ['admin.managers.index', ['managers', 'managers_create']],
        'preferences' => ['admin.preferences.index', ['preferences']],
        'carte' => ['admin.map.index', ['map']],
        'boutiques' => ['admin.shops.index', ['shops', 'shops_2']],
        'produits' => ['admin.products.index', ['products', 'products_42', 'products_create']],
        'categories' => ['admin.settings.categories', ['settings_categories']],
        'packages' => ['admin.packages.index', ['packages']],
        'ads' => ['admin.ads.index', ['ads']],
        'commandes-locales' => ['admin.local-orders.index', ['local-orders']],
        'commandes-gros' => ['admin.wholesale-orders.index', ['wholesale-orders', 'wholesale-orders_24']],
        'acompte' => ['admin.deposit-orders.index', ['deposit-orders', 'deposit-orders_35']],
        'litiges' => ['admin.disputes.index', ['disputes']],
        'partenaires' => ['admin.delivery-partners.index', ['delivery-partners']],
        'immobilier' => ['admin.real-estate.listings.index', []],
        'immo-cabinets' => ['admin.real-estate.cabinets.index', []],
        'livreurs' => ['admin.deliverers.index', ['deliverers']],
        'expeditions' => ['admin.shipments.index', ['shipments']],
        'pays' => ['admin.import-countries.index', ['import-countries']],
        'transactions' => ['admin.transactions.index', ['transactions']],
        'iban' => ['admin.stripe.accounts.index', ['stripe_accounts']],
        'config-paiements' => ['admin.settings.payments', ['settings_payments']],
        'diaspo-verifs' => ['admin.diaspo.verifications.index', ['diaspo_verifications']],
        'diaspo-offres' => ['admin.diaspo.offers.index', ['diaspo_offers']],
        'voc' => ['admin.diaspo.posts.index', ['diaspo_posts']],
        'commerciaux' => ['admin.sales.agents.index', ['sales_agents', 'sales_agents_create']],
        'commissions' => ['admin.sales.commissions.index', ['sales_commissions']],
        'affiliation' => ['admin.affiliate.settings', ['affiliate_settings']],
        'bannieres' => ['admin.banners.index', ['banners']],
        'support' => ['admin.support.index', ['support']],
        'messagerie' => ['admin.messages.index', ['messages']],
        'echanges' => ['admin.exchanges.index', ['exchanges']],
        'parametres' => ['admin.settings.index', ['settings']],
        'maintenance' => ['admin.settings.maintenance', ['settings_maintenance']],
        'services' => ['admin.settings.services', ['settings_services']],
        'pages-legales' => ['admin.legal-pages.index', ['legal-pages']],
        'documents' => ['admin.documents.index', ['documents']],
    ];

    public function index(Request $request)
    {
        $user = $request->user();

        return view('admin.guide.index', [
            'allowedPages' => $this->allowedPages($user),
            'isAdmin' => $user->isAdmin(),
            'homeRoute' => $user->adminHomeRoute(),
        ]);
    }

    public function image(Request $request, string $file): BinaryFileResponse
    {
        $name = pathinfo($file, PATHINFO_FILENAME);
        $path = resource_path('guide/img/'.$name.'.webp');

        abort_unless(in_array($name, $this->allowedShots($request->user()), true) && is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'private, max-age=604800',
        ]);
    }

    /** @return list<string> */
    private function allowedPages(User $user): array
    {
        $pages = self::COMMON_PAGES;
        foreach (self::PAGES as $page => [$route]) {
            if ($user->canAccessAdminRoute($route)) {
                $pages[] = $page;
            }
        }

        return $pages;
    }

    /** @return list<string> */
    private function allowedShots(User $user): array
    {
        $shots = self::COMMON_SHOTS;
        foreach (self::PAGES as [$route, $pageShots]) {
            if ($user->canAccessAdminRoute($route)) {
                array_push($shots, ...$pageShots);
            }
        }

        return $shots;
    }
}
