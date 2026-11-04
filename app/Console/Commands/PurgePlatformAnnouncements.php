<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\NotificationDigestItem;
use Illuminate\Console\Command;

/**
 * Annonces de la plateforme (nouveau produit, nouvelle chambre, sponsoring...) : passé
 * le délai, elles quittent le résumé et l'historique des notifications. Commandes,
 * paiements, litiges et messages ne sont jamais concernés.
 */
class PurgePlatformAnnouncements extends Command
{
    private const TYPES = [
        'marketing_digest', 'announcement', 'new_product', 'sponsored_product',
        'new_diaspo_offer', 'new_real_estate_listing', 'real_estate_alert',
    ];

    protected $signature = 'announcements:purge {--days= : Délai de conservation, en jours}';

    protected $description = 'Retire les annonces de la plateforme passé le délai de conservation';

    public function handle(): int
    {
        $limit = now()->subDays((int) ($this->option('days') ?? config('notifications.marketing_retention_days')));

        $items = NotificationDigestItem::where('created_at', '<', $limit)->delete();
        $notifications = Notification::whereIn('type', self::TYPES)->where('created_at', '<', $limit)->delete();

        $this->info("Annonces retirées : {$items} — notifications retirées : {$notifications}");

        return self::SUCCESS;
    }
}
