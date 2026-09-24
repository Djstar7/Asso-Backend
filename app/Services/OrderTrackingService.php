<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderTrackingEvent;
use Illuminate\Support\Facades\Log;

/**
 * Historique daté du parcours de livraison, de la commande jusqu'à la réception.
 * Toute étape de livraison passe par ici pour rester tracée (orders.tracking_status
 * = dernière étape connue ; orders.status reste le statut métier).
 */
class OrderTrackingService
{
    public const STEPS = [
        'pending' => 'Commande passée',
        'confirmed' => 'Commande validée par le vendeur',
        'preparing' => 'Colis en préparation',
        'out_for_delivery' => 'Pris en charge par le livreur',
        'handed_to_carrier' => 'Remis au transporteur',
        'in_transit' => 'En transit',
        'customs' => 'En dédouanement',
        'arrived_hub' => "Arrivé à l'entrepôt ASSO de Douala",
        'arrived' => 'Arrivé dans la ville de destination',
        'ready_for_pickup' => 'Disponible au retrait en agence',
        'delivered' => 'Livré — réception confirmée',
        'cancelled' => 'Commande annulée',
    ];

    /** Étapes qu'un vendeur ou l'admin saisit pendant l'acheminement transporteur. */
    public const CARRIER_UPDATE_STEPS = ['in_transit', 'customs', 'arrived', 'ready_for_pickup'];

    /** Import en gros : arrivée à Douala, où SOLEX prend le relais. */
    public const IMPORT_HUB_STEP = 'arrived_hub';

    /** Étapes proposées pour une commande (l'arrivée à Douala seulement pour un import). */
    public static function carrierUpdateSteps(Order $order): array
    {
        return $order->is_wholesale
            ? ['in_transit', 'customs', self::IMPORT_HUB_STEP, 'arrived', 'ready_for_pickup']
            : self::CARRIER_UPDATE_STEPS;
    }

    public function __construct(private FirebaseMessagingService $fcm)
    {
    }

    public function record(
        Order $order,
        string $step,
        ?string $location = null,
        ?string $note = null,
        string $actorType = 'system',
        ?int $actorId = null,
        bool $notifyBuyer = false,
    ): OrderTrackingEvent {
        $label = self::STEPS[$step] ?? $step;

        $event = OrderTrackingEvent::create([
            'order_id' => $order->id,
            'step' => $step,
            'label' => $label,
            'location' => $location,
            'note' => $note,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'occurred_at' => now(),
        ]);

        $order->forceFill(['tracking_status' => $step])->saveQuietly();

        // Transporteur à domicile : colis arrivé à l'agence (ou import arrivé à l'entrepôt
        // de Douala) → les coursiers du partenaire le voient dans l'app et l'un d'eux
        // l'accepte (flux urbain, code à 6 chiffres).
        if ($step === $order->lastMileStep() && $order->delivery_person_id === null) {
            $this->notifyLastMileCouriers($order);
        }

        if ($notifyBuyer && $order->user) {
            try {
                $this->fcm->sendToUser(
                    $order->user,
                    "Commande #{$order->order_number}",
                    $label . ($location ? " — {$location}" : '') . ($note ? ". {$note}" : '.'),
                    [
                        'type' => 'order_tracking',
                        'order_id' => (string) $order->id,
                        'order_number' => $order->order_number,
                        'step' => $step,
                    ]
                );
            } catch (\Throwable $e) {
                Log::warning('[OrderTracking] Notification acheteur échouée: ' . $e->getMessage());
            }
        }

        return $event;
    }

    private function notifyLastMileCouriers(Order $order): void
    {
        $syncs = \App\Models\DelivererCodeSync::where('company_id', $order->delivery_company_id)
            ->active()
            ->with('user')
            ->get();

        foreach ($syncs as $sync) {
            if (!$sync->user) {
                continue;
            }
            try {
                $this->fcm->sendToUser(
                    $sync->user,
                    'Colis à livrer depuis l\'agence',
                    "Commande #{$order->order_number} — livraison vers {$order->delivery_address}.",
                    [
                        'type' => 'new_delivery_request',
                        'order_id' => (string) $order->id,
                        'order_number' => $order->order_number,
                        'delivery_address' => (string) $order->delivery_address,
                    ]
                );
            } catch (\Throwable $e) {
                Log::warning('[OrderTracking] Notification coursier échouée: ' . $e->getMessage());
            }
        }
    }

    /** Suivi complet pour l'API (acheteur, vendeur, admin). */
    public static function timeline(Order $order): array
    {
        return $order->trackingEvents->map->toApi()->values()->all();
    }
}
