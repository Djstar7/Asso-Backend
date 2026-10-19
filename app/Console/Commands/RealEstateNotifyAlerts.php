<?php

namespace App\Console\Commands;

use App\Models\RealEstate\Alert;
use App\Models\RealEstate\Listing;
use App\Services\FirebaseMessagingService;
use App\Services\RealEstate\RealEstatePricingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * « Actualiser mon alerte » : prévient chaque utilisateur des nouveaux biens
 * publiés qui correspondent à ses critères (ville, budget, durée).
 */
class RealEstateNotifyAlerts extends Command
{
    protected $signature = 'immo:notify-alerts';

    protected $description = 'Notifie les nouveaux biens correspondant aux critères enregistrés';

    public function handle(RealEstatePricingService $pricing): int
    {
        $sent = 0;
        Alert::where('is_active', true)->with('user')->chunkById(200, function ($alerts) use ($pricing, &$sent) {
            foreach ($alerts as $alert) {
                $since = $alert->last_notified_at ?? $alert->updated_at;
                $markup = 1 + $pricing->markupRate($alert->type) / 100;
                $matches = Listing::published()
                    ->where('type', $alert->type ?: 'rent')
                    ->where('published_at', '>', $since)
                    ->when($alert->city, fn ($q) => $q->where(fn ($w) => $w->where('city', 'like', $alert->city.'%')
                        ->orWhere('district', 'like', '%'.$alert->city.'%')))
                    ->when($alert->budget_max, fn ($q) => $q->where('net_price', '<=', (int) ceil($alert->budget_max / $markup)))
                    ->when($alert->budget_min, fn ($q) => $q->where('net_price', '>=', (int) floor($alert->budget_min / $markup)))
                    ->when($alert->lease_months, fn ($q) => $q->where(fn ($w) => $w->whereNull('min_lease_months')
                        ->orWhere('min_lease_months', '<=', $alert->lease_months)))
                    ->count();

                $alert->forceFill(['last_notified_at' => now()])->save();
                if ($matches === 0 || ! $alert->user) {
                    continue;
                }
                try {
                    app(FirebaseMessagingService::class)->sendToUser(
                        $alert->user,
                        $alert->user->localized('notifications.real_estate_alert.title'),
                        $alert->user->localized('notifications.real_estate_alert.body', ['count' => $matches, 'city' => $alert->city ?: '']),
                        ['type' => 'real_estate_alert', 'action' => 'open_real_estate'],
                    );
                    $sent++;
                } catch (\Throwable $e) {
                    Log::warning('[IMMO] Alerte non envoyée', ['alert' => $alert->id, 'error' => $e->getMessage()]);
                }
            }
        });
        $this->info("$sent alerte(s) envoyée(s).");

        return self::SUCCESS;
    }
}
