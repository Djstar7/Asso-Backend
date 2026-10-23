<?php

namespace App\Console\Commands;

use App\Models\RealEstate\Alert;
use App\Models\RealEstate\Listing;
use App\Services\FirebaseMessagingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * « Actualiser mon alerte » : prévient chaque utilisateur des nouveaux biens
 * publiés qui correspondent à ses critères (ville, budget, durée).
 */
class RealEstateNotifyAlerts extends Command
{
    protected $signature = 'immo:notify-alerts';

    protected $description = 'Notifie les nouveaux biens correspondant aux critères enregistrés';

    public function handle(): int
    {
        $sent = 0;
        Alert::where('is_active', true)->with('user')->chunkById(200, function ($alerts) use (&$sent) {
            foreach ($alerts as $alert) {
                Cache::lock('immo:alert:'.$alert->id, 120)->get(function () use ($alert, &$sent) {
                    $alert->refresh();
                    if (! $alert->is_active || ! $alert->user) {
                        return;
                    }
                    $since = $alert->last_notified_at ?? $alert->updated_at;
                    // Une borne exclusive à la seconde évite de perdre une annonce
                    // publiée pendant le traitement avec le même horodatage.
                    $until = $alert->pending_until ?? now()->startOfSecond();
                    if ($until->lessThanOrEqualTo($since)) {
                        return;
                    }
                    $matches = Listing::published()
                        ->where('type', $alert->type ?: 'rent')
                        ->where('published_at', '>=', $since)
                        ->where('published_at', '<', $until)
                        ->when($alert->city, fn ($q) => $q->where(fn ($w) => $w->where('city', 'like', $alert->city.'%')
                            ->orWhere('district', 'like', '%'.$alert->city.'%')))
                        ->when($alert->budget_max !== null, fn ($q) => $q->where('net_price', '<=', $alert->budget_max))
                        ->when($alert->budget_min !== null, fn ($q) => $q->where('net_price', '>=', $alert->budget_min))
                        ->when($alert->type === Listing::TYPE_RENT && $alert->lease_months, fn ($q) => $q->where(fn ($w) => $w->whereNull('min_lease_months')
                            ->orWhere('min_lease_months', '<=', $alert->lease_months)))
                        ->count();

                    if ($matches === 0) {
                        $alert->forceFill(['last_notified_at' => $until, 'pending_until' => null])->save();

                        return;
                    }
                    // Geler le lot pour qu'une reprise ne perde ni ne duplique son historique.
                    $alert->forceFill(['last_notified_at' => $since, 'pending_until' => $until])->save();
                    try {
                        $result = app(FirebaseMessagingService::class)->sendToUser(
                            $alert->user,
                            $alert->user->localized('notifications.real_estate_alert.title'),
                            $alert->user->localized('notifications.real_estate_alert.body', ['count' => $matches, 'city' => $alert->city ?: '']),
                            [
                                'type' => 'real_estate_alert', 'action' => 'open_real_estate',
                                'property_type' => $alert->type,
                                'notification_key' => 'immo-alert:'.$alert->id.':'.$since->timestamp.':'.$until->timestamp,
                            ],
                        );
                        if (! ($result['success'] ?? false) || (isset($result['success_count']) && $result['success_count'] < 1)) {
                            Log::warning('[IMMO] Alerte à reprendre', ['alert' => $alert->id, 'message' => $result['message'] ?? null]);

                            return;
                        }
                        Alert::whereKey($alert->id)->where('pending_until', $until)
                            ->where('last_notified_at', $since)
                            ->update(['last_notified_at' => $until, 'pending_until' => null]);
                        $sent++;
                    } catch (\Throwable $e) {
                        Log::warning('[IMMO] Alerte non envoyée', ['alert' => $alert->id, 'error' => $e->getMessage()]);
                    }
                });
            }
        });
        $this->info("$sent alerte(s) envoyée(s).");

        return self::SUCCESS;
    }
}
