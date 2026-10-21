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
        'pickup_requested' => 'Demande d’enlèvement transmise au partenaire',
        'pickup_accepted' => 'Enlèvement accepté par le partenaire',
        'collected_by_carrier' => 'Colis récupéré chez ASSO par le partenaire',
        'partner_in_transit' => 'En acheminement par le partenaire',
        'agency_assigned' => 'Agence de retrait affectée',
        'collected_at_agency' => 'Retrait en agence enregistré par ASSO',
        'reception_confirmed' => 'Réception confirmée par le client',
        'arrived_hub' => "Arrivé à l'entrepôt ASSO de Douala",
        'arrived' => 'Arrivé dans la ville de destination',
        'ready_for_pickup' => 'Disponible au retrait en agence',
        'delivered' => 'Livré — remise enregistrée',
        'conformity_confirmed' => 'Commande validée par le client — tout est conforme',
        'auto_validated' => 'Commande validée automatiquement (48 h sans réclamation)',
        'cancelled' => 'Commande annulée',
    ];

    /** Libellé d'une étape dans la langue de la requête (STEPS reste le libellé enregistré). */
    public static function stepLabel(?string $step): ?string
    {
        return $step !== null && isset(self::STEPS[$step]) ? __("tracking.steps.{$step}") : null;
    }

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
        array $confirmation = [],
    ): OrderTrackingEvent {
        if ($order->isCarrierDelivery() && in_array($step, array_merge(
            self::CARRIER_UPDATE_STEPS, [self::IMPORT_HUB_STEP, 'handed_to_carrier'],
            AssoShipmentService::EXTERNAL_STEPS
        ), true) && $actorType !== 'admin') {
            // Le vendeur peut seulement déclarer le dépôt initial d'un colis national.
            if ($actorType !== 'vendor' || $step !== 'handed_to_carrier' || $order->is_wholesale) {
                throw \Illuminate\Validation\ValidationException::withMessages(['step' => __('shipment.asso_only')]);
            }
        }
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
        ] + array_intersect_key($confirmation, array_flip([
            'confirmation_contact', 'confirmation_reference', 'confirmation_channel',
        ])));

        $order->forceFill(['tracking_status' => $step])->saveQuietly();

        // Livraison confirmée : fenêtre de contrôle de 48 h (part vendeur bloquée).
        if (in_array($step, ['delivered', 'collected_at_agency'], true)) {
            app(OrderService::class)->startControlWindow($order->fresh());
        }

        // Commande avec acompte : produit présenté au client → vérification ASSO à faire.
        if ($order->isDepositOrder()) {
            app(DepositOrderService::class)->onTrackingStep($order, $step);
        }

        if ($notifyBuyer && $order->user) {
            try {
                $buyer = $order->user;
                $buyerLabel = array_key_exists($step, self::STEPS)
                    ? $buyer->localized("tracking.steps.{$step}")
                    : $label;
                $variant = 'body' . ($location ? '_location' : '') . ($note ? '_note' : '');
                $body = $buyer->localized("notifications.order_tracking.{$variant}", [
                    'step' => $buyerLabel, 'location' => (string) $location, 'note' => (string) $note,
                ]);
                if ($step === 'ready_for_pickup' && $order->pickup_agency_snapshot) {
                    $body = $buyer->localized('shipment.ready_notification', $order->pickup_agency_snapshot + ['order' => $order->order_number]);
                }
                $this->fcm->sendToUser(
                    $buyer,
                    $buyer->localized('notifications.order_tracking.title', ['order_number' => $order->order_number]),
                    $body,
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

    /** Suivi complet pour l'API (acheteur, vendeur, admin). */
    public static function timeline(Order $order): array
    {
        return $order->trackingEvents->map->toApi()->values()->all();
    }
}
