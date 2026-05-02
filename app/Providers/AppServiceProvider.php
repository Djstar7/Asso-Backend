<?php

namespace App\Providers;

use Illuminate\Support\Facades\View;
use App\Models\Shop;
use App\Models\User;
use App\Models\DeviceToken;
use App\Models\DiaspoOffer;
use App\Models\Conversation;
use App\Observers\DeviceTokenObserver;
use App\Observers\DiaspoOfferObserver;
use App\Observers\ConversationObserver;
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

        // Share pending shops count with all admin views
        View::composer('admin.layouts.app', function ($view) {
            $pendingShopsCount = Shop::pending()->count();
            $pendingDiaspoVerifications = User::where('diaspo_verification_status', 'pending')->count();

            $view->with('pendingShopsCount', $pendingShopsCount);
            $view->with('pendingDiaspoVerifications', $pendingDiaspoVerifications);
        });
    }
}
