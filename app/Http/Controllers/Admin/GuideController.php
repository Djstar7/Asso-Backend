<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AdminNavigation;
use App\Support\AssoScope;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Guide d'utilisation du back-office (menu « Guide d’utilisation »).
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
    private const COMMON_SHOTS = ['login', 'manager_login', 'dashboard', 'navigation'];

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

    /** Existing article anchors stay valid when the menu is reorganized. */
    private const ARTICLE_IDS = [
        'dashboard' => 'dashboard', 'statistics' => 'statistiques',
        'local_orders' => 'commandes-locales', 'wholesale_orders' => 'commandes-gros', 'deposit_orders' => 'acompte',
        'shops' => 'boutiques', 'products' => 'produits', 'categories' => 'categories',
        'import_countries' => 'pays', 'packages' => 'packages',
        'shipments' => 'expeditions', 'deliverers' => 'livreurs', 'delivery_partners' => 'partenaires', 'map' => 'carte',
        'diaspo_verifications' => 'diaspo-verifs', 'air_offers' => 'diaspo-offres',
        'listings' => 'immobilier', 'cabinets' => 'immo-cabinets',
        'revenue' => 'revenus', 'withdrawals' => 'retrait', 'transactions' => 'transactions', 'stripe_accounts' => 'iban',
        'disputes' => 'litiges', 'support' => 'support', 'messages' => 'messagerie', 'exchanges' => 'echanges',
        'ads' => 'ads', 'banners' => 'bannieres', 'preferences' => 'preferences', 'community' => 'voc',
        'affiliate_settings' => 'affiliation', 'sales_agents' => 'commerciaux', 'sales_commissions' => 'commissions',
        'users' => 'utilisateurs', 'managers' => 'gestionnaires', 'settings' => 'parametres',
        'payment_settings' => 'config-paiements', 'services' => 'services', 'maintenance' => 'maintenance',
        'legal_pages' => 'pages-legales', 'documents' => 'documents',
    ];

    public function index(Request $request, AdminNavigation $navigation)
    {
        $user = $request->user();
        $groups = $navigation->forUser($user, $request);
        // The existing guide is French. Its headings and paths use the French
        // menu translations, while keeping its established article content.
        foreach ($groups as &$group) {
            $group['label'] = __('admin_nav.groups.'.$group['id'], [], 'fr');
            foreach ($group['items'] as &$item) {
                $key = AssoScope::restricts($user) && $item['id'] === 'products' ? 'asso_products' : $item['id'];
                $item['label'] = __('admin_nav.items.'.$key, [], 'fr');
                $item['article_id'] = self::ARTICLE_IDS[$item['id']] ?? 'menu-'.$item['id'];
                $item['path'] = ['Menu', $group['label'], $item['label']];
                unset($item['badge'], $item['tone'], $item['active']);
            }
            unset($item);
        }
        unset($group);
        $menuArticles = collect($groups)->flatMap(fn ($group) => array_column($group['items'], 'article_id'))->all();

        return view('admin.guide.index', [
            'allowedPages' => array_values(array_unique([...$this->allowedPages($user), ...$menuArticles])),
            'guideNavigation' => $groups,
            'isAdmin' => $user->isAdmin(),
            'homeRoute' => $user->adminHomeRoute(),
        ]);
    }

    public function image(Request $request, string $file): BinaryFileResponse
    {
        $name = pathinfo($file, PATHINFO_FILENAME);
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        abort_unless(in_array($extension, ['webp', 'png'], true), 404);
        $path = resource_path('guide/img/'.$name.'.'.$extension);

        abort_unless(in_array($name, $this->allowedShots($request->user()), true) && is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => 'image/'.$extension,
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
