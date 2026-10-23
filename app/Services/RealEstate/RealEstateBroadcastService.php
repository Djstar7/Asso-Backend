<?php

namespace App\Services\RealEstate;

use App\Http\Middleware\SetLocale;
use App\Models\RealEstate\Listing;
use App\Services\FirebaseMessagingService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RealEstateBroadcastService
{
    /** Envoi immédiat, puis reprise des seuls topics en échec par le scheduler. */
    public function send(int $listingId): void
    {
        try {
            Cache::lock('immo:publication:'.$listingId, 120)->get(function () use ($listingId) {
                $listing = Listing::published()->find($listingId);
                if (! $listing || $listing->announced_at) {
                    return;
                }
                $sent = $listing->announced_topics ?? [];
                $fcm = app(FirebaseMessagingService::class);
                foreach (FirebaseMessagingService::announcementTopics() as $topic) {
                    if (in_array($topic, $sent, true)) {
                        continue;
                    }
                    $locale = collect(SetLocale::SUPPORTED)->first(
                        fn ($locale) => $topic === FirebaseMessagingService::localeTopic($locale)
                    ) ?? 'fr';
                    $key = $listing->isLand() ? 'new_real_estate_land' : 'new_real_estate_rent';
                    $result = $fcm->sendToTopic($topic,
                        __('notifications.'.$key.'.title', [], $locale),
                        __('notifications.'.$key.'.body', [
                            'title' => $listing->getTranslation('title', $locale) ?? $listing->getTranslation('title', 'fr'),
                            'city' => $listing->city,
                        ], $locale),
                        [
                            'type' => 'new_real_estate_listing', 'action' => 'open_real_estate',
                            'listing_id' => (string) $listing->id, 'property_type' => $listing->type,
                        ],
                    );
                    if (! ($result['success'] ?? false)) {
                        Log::warning('[IMMO] Publication non annoncée', ['listing_id' => $listingId, 'topic' => $topic, 'message' => $result['message'] ?? null]);

                        continue;
                    }
                    $sent[] = $topic;
                    Listing::whereKey($listingId)->update(['announced_topics' => json_encode($sent)]);
                }
                if (count(array_diff(FirebaseMessagingService::announcementTopics(), $sent)) === 0) {
                    Listing::whereKey($listingId)->update(['announced_at' => now()]);
                }
            });
        } catch (\Throwable $e) {
            // Le terrain reste publié ; les marqueurs permettent la reprise.
            Log::error('[IMMO] Échec annonce publique', ['listing_id' => $listingId, 'error' => $e->getMessage()]);
        }
    }
}
