<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\View;
use App\Models\Shop;
use App\Models\SupportTicket;
use App\Models\DeviceToken;
use App\Observers\DeviceTokenObserver;

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

        // Partage avec le layout admin : compteur boutiques + notifications réelles
        View::composer('admin.layouts.app', function ($view) {
            $pendingShopsCount = Shop::pending()->count();

            // Tickets de support ouverts (garde-fou si la table n'existe pas encore)
            try {
                $openTicketsCount = SupportTicket::open()->count();
            } catch (\Throwable $e) {
                $openTicketsCount = 0;
            }

            // Construire la liste des notifications (tâches admin en attente)
            $notifications = [];
            if ($pendingShopsCount > 0) {
                $notifications[] = [
                    'icon'  => 'fa-store',
                    'color' => 'text-yellow-400',
                    'title' => $pendingShopsCount . ' boutique' . ($pendingShopsCount > 1 ? 's' : '') . ' en attente de vérification',
                    'url'   => route('admin.shops.index'),
                ];
            }
            if ($openTicketsCount > 0) {
                $notifications[] = [
                    'icon'  => 'fa-headset',
                    'color' => 'text-blue-400',
                    'title' => $openTicketsCount . ' ticket' . ($openTicketsCount > 1 ? 's' : '') . ' de support ouvert' . ($openTicketsCount > 1 ? 's' : ''),
                    'url'   => route('admin.support.index'),
                ];
            }

            $view->with('pendingShopsCount', $pendingShopsCount);
            $view->with('adminNotifications', $notifications);
            $view->with('adminNotificationsCount', $pendingShopsCount + $openTicketsCount);
        });
    }
}
