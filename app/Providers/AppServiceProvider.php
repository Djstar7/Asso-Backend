<?php

namespace App\Providers;

use App\Models\Conversation;
use App\Models\DeviceToken;
use App\Models\DiaspoOffer;
use App\Models\Shop;
use App\Models\SupportTicket;
use App\Models\User;
use App\Observers\ConversationObserver;
use App\Observers\DeviceTokenObserver;
use App\Observers\DiaspoOfferObserver;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register DeviceToken observer for automatic topic subscription
        DeviceToken::observe(DeviceTokenObserver::class);

        // Register DiaspoOffer observer for automatic approval
        DiaspoOffer::observe(DiaspoOfferObserver::class);

        // Register Conversation observer for automatic security message
        Conversation::observe(ConversationObserver::class);

        // Back-office : @adminCan('admin.products.index') … @endadminCan
        // n'affiche un lien que si la route est ouverte au compte connecté.
        Blade::if('adminCan', fn (string $routeName) => (bool) auth()->user()?->canAccessAdminRoute($routeName));

        // Partage avec le layout admin : compteur boutiques + notifications réelles
        View::composer('admin.layouts.app', function ($view) {
            // Un gestionnaire ne voit pas les tâches des sections qui lui sont fermées.
            if (! auth()->user()?->isAdmin()) {
                $user = auth()->user();
                $canSee = fn (string $route) => (bool) $user?->canAccessAdminRoute($route);

                $view->with('pendingShopsCount', $canSee('admin.shops.index') ? Shop::pending()->count() : 0);
                $view->with('pendingDiaspoVerifications', $canSee('admin.diaspo.verifications.index')
                    ? User::where('diaspo_verification_status', 'pending')->count() : 0);
                $view->with('wholesaleToValidateCount', $canSee('admin.wholesale-orders.index')
                    ? \App\Support\WholesaleOrderStage::apply(\App\Models\Order::where('is_wholesale', true), 'to_validate')->count() : 0);
                $view->with('adminNotifications', []);
                $view->with('adminNotificationsCount', 0);

                return;
            }

            $pendingShopsCount = Shop::pending()->count();
            $pendingDiaspoVerifications = User::where('diaspo_verification_status', 'pending')->count();

            // Tickets de support ouverts (garde-fou si la table n'existe pas encore)
            try {
                $openTicketsCount = SupportTicket::open()->count();
            } catch (\Throwable $e) {
                $openTicketsCount = 0;
            }

            // Commandes en gros payées, en attente de validation par ASSO.
            $wholesaleToValidateCount = \App\Support\WholesaleOrderStage::apply(
                \App\Models\Order::where('is_wholesale', true), 'to_validate'
            )->count();

            // Changements d'emplacement de boutique à valider.
            $pendingLocationRequests = \App\Models\ShopLocationRequest::pending()->count();

            // Construire la liste des notifications (tâches admin en attente)
            $notifications = [];
            if ($pendingLocationRequests > 0) {
                $notifications[] = [
                    'icon' => 'fa-map-marker-alt',
                    'color' => 'text-yellow-400',
                    'title' => $pendingLocationRequests.' changement'.($pendingLocationRequests > 1 ? 's' : '')." d'emplacement de boutique à valider",
                    'url' => route('admin.shops.index', ['location_request' => 'pending']),
                ];
            }
            if ($wholesaleToValidateCount > 0) {
                $notifications[] = [
                    'icon' => 'fa-dolly',
                    'color' => 'text-yellow-400',
                    'title' => $wholesaleToValidateCount.' commande'.($wholesaleToValidateCount > 1 ? 's' : '').' en gros à valider',
                    'url' => route('admin.wholesale-orders.index', ['stage' => 'to_validate']),
                ];
            }
            if ($pendingShopsCount > 0) {
                $notifications[] = [
                    'icon' => 'fa-store',
                    'color' => 'text-yellow-400',
                    'title' => $pendingShopsCount.' boutique'.($pendingShopsCount > 1 ? 's' : '').' en attente de vérification',
                    'url' => route('admin.shops.index'),
                ];
            }
            if ($openTicketsCount > 0) {
                $notifications[] = [
                    'icon' => 'fa-headset',
                    'color' => 'text-blue-400',
                    'title' => $openTicketsCount.' ticket'.($openTicketsCount > 1 ? 's' : '').' de support ouvert'.($openTicketsCount > 1 ? 's' : ''),
                    'url' => route('admin.support.index'),
                ];
            }

            $view->with('pendingShopsCount', $pendingShopsCount);
            $view->with('pendingDiaspoVerifications', $pendingDiaspoVerifications);
            $view->with('adminNotifications', $notifications);
            $view->with('wholesaleToValidateCount', $wholesaleToValidateCount);
            $view->with('adminNotificationsCount', $pendingShopsCount + $openTicketsCount + $wholesaleToValidateCount + $pendingLocationRequests);
        });
    }
}
