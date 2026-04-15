<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\User;
use App\Models\DelivererCodeSync;
use App\Services\FirebaseMessagingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckDeliveryAcceptanceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $orderId
    ) {}

    public function handle(FirebaseMessagingService $fcmService): void
    {
        $order = Order::with('deliveryCompany')->find($this->orderId);

        if (!$order) return;

        // Si un livreur a déjà accepté → rien à faire
        if ($order->delivery_person_id !== null) {
            Log::info("[CheckDeliveryAcceptance] Order #{$order->order_number} already has a deliverer, skipping.");
            return;
        }

        // Si la commande n'est plus en confirmed → rien à faire (annulée, etc.)
        if ($order->status !== 'confirmed') {
            return;
        }

        Log::info("[CheckDeliveryAcceptance] No deliverer after 5min for order #{$order->order_number}");

        // Récupérer les livreurs synchronisés à la company
        $deliverers = [];
        if ($order->delivery_company_id) {
            $syncs = DelivererCodeSync::where('company_id', $order->delivery_company_id)
                ->active()
                ->with('user')
                ->get();

            foreach ($syncs as $sync) {
                if ($sync->user) {
                    $deliverers[] = [
                        'id' => $sync->user->id,
                        'name' => $sync->user->first_name . ' ' . $sync->user->last_name,
                        'phone' => $sync->user->phone,
                    ];
                }
            }
        }

        // Notifier le vendeur
        $sellerIds = $order->items()->pluck('seller_id')->unique();
        foreach ($sellerIds as $sellerId) {
            $seller = User::find($sellerId);
            if ($seller) {
                $fcmService->sendToUser(
                    $seller,
                    'Aucun livreur disponible',
                    "Aucun livreur n'a accepté la commande #{$order->order_number} après 5 minutes. Consultez les livreurs disponibles.",
                    [
                        'type' => 'delivery_timeout',
                        'order_id' => (string) $order->id,
                        'order_number' => $order->order_number,
                        'company_name' => $order->deliveryCompany?->name ?? '',
                        'deliverers_count' => (string) count($deliverers),
                    ]
                );
            }
        }

        // Aussi notifier le client
        $client = $order->user;
        if ($client) {
            $fcmService->sendToUser(
                $client,
                'En attente d\'un livreur',
                "Aucun livreur n'a encore pris en charge votre commande #{$order->order_number}. Nous vous tiendrons informé.",
                [
                    'type' => 'delivery_timeout_client',
                    'order_id' => (string) $order->id,
                    'order_number' => $order->order_number,
                ]
            );
        }
    }
}
