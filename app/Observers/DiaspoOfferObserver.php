<?php

namespace App\Observers;

use App\Models\DiaspoOffer;
use App\Services\FirebaseMessagingService;
use Illuminate\Support\Facades\Log;

class DiaspoOfferObserver
{
    /**
     * Handle the DiaspoOffer "created" event.
     * Auto-approve offers from verified users immediately after creation
     */
    public function created(DiaspoOffer $offer): void
    {
        // Check if user is verified for Diaspo
        if ($offer->user && $offer->user->canCreateDiaspoOffers()) {
            // Auto-approve offer for verified users
            $offer->update([
                'status' => 'approved',
                'verification_status' => 'verified',
                'verified_at' => now(),
                'verified_by' => $offer->user_id, // Self-verified (user is already KYC verified)
            ]);

            // Envoyer une notification push à tous les utilisateurs pour la nouvelle offre diaspo
            Log::info('[DIASPO_OFFER] Sending push notification for new approved diaspo offer', [
                'offer_id' => $offer->id,
                'departure' => $offer->departure_country . ' -> ' . $offer->arrival_country,
            ]);

            try {
                $fcmService = new FirebaseMessagingService();
                $fcmService->sendToTopic(
                    'all_users',
                    '✈️ Nouvelle offre DIASPO disponible',
                    "{$offer->departure_city} → {$offer->arrival_city} - {$offer->price_per_kg}€/kg",
                    [
                        'type' => 'new_diaspo_offer',
                        'offer_id' => (string) $offer->id,
                        'departure_country' => $offer->departure_country,
                        'departure_city' => $offer->departure_city,
                        'arrival_country' => $offer->arrival_country,
                        'arrival_city' => $offer->arrival_city,
                        'price_per_kg' => (string) $offer->price_per_kg,
                        'departure_datetime' => $offer->departure_datetime->toIso8601String(),
                    ]
                );
                Log::info('[DIASPO_OFFER] Push notification sent successfully');
            } catch (\Exception $e) {
                Log::error('[DIASPO_OFFER] Error sending push notification: ' . $e->getMessage());
                // On ne bloque pas la création de l'offre si la notification échoue
            }
        }
        // If user is not verified, offer stays in 'pending' status
        // and will need manual admin approval
    }

    /**
     * Handle the DiaspoOffer "updated" event.
     */
    public function updated(DiaspoOffer $offer): void
    {
        // Mark as expired if departure date has passed
        if ($offer->departure_datetime < now() && $offer->status !== 'expired') {
            $offer->update(['status' => 'expired']);
        }
    }

    /**
     * Handle the DiaspoOffer "deleted" event.
     */
    public function deleted(DiaspoOffer $offer): void
    {
        // Cancel all pending bookings when offer is deleted
        $offer->bookings()
            ->whereIn('status', ['pending', 'paid'])
            ->each(function ($booking) {
                $booking->update([
                    'status' => 'cancelled',
                    'cancellation_reason' => 'Offre supprimée par le vendeur',
                    'cancelled_at' => now(),
                ]);
            });
    }

    /**
     * Handle the DiaspoOffer "restored" event.
     */
    public function restored(DiaspoOffer $offer): void
    {
        //
    }

    /**
     * Handle the DiaspoOffer "force deleted" event.
     */
    public function forceDeleted(DiaspoOffer $offer): void
    {
        //
    }
}
