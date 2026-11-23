<?php

namespace App\Support;

use App\Models\Order;
use App\Models\SupplierOrder;

/**
 * Colis d'un fournisseur international (CJ…) vu par le client : où il en est,
 * quand il est attendu à l'entrepôt de Douala, et son numéro de suivi une fois
 * expédié. Le nom du fournisseur et ses prix ne sont jamais exposés.
 */
class SupplierShipmentPresenter
{
    /** null si la commande ne contient aucun produit d'un fournisseur international. */
    public static function forOrder(Order $order): ?array
    {
        $shipment = SupplierOrder::where('order_id', $order->id)
            ->whereNotIn('status', [SupplierOrder::CANCELLED])
            ->latest('id')->first();
        if (! $shipment) {
            return null;
        }

        $stage = match ($shipment->status) {
            SupplierOrder::PAID => 'preparing',
            SupplierOrder::SHIPPED, SupplierOrder::DELIVERED => 'in_transit',
            SupplierOrder::RECEIVED => 'arrived',
            SupplierOrder::SUPPLIER_CANCELLED => 'issue',
            default => 'ordering',
        };
        $shipped = in_array($stage, ['in_transit', 'arrived'], true);

        return [
            'stage' => $stage,
            'stage_label' => __("delivery.international.stages.{$stage}"),
            'expected_hub_date' => $stage !== 'arrived' ? $shipment->expected_hub_at?->toDateString() : null,
            'is_late' => $shipment->isLate(),
            'arrived_at' => $shipment->hub_received_at?->toIso8601String(),
            'carrier_name' => $shipped ? $shipment->logistic_name : null,
            'tracking_number' => $shipped ? $shipment->tracking_number : null,
            'tracking_url' => $shipped ? $shipment->tracking_url : null,
            'hub_name' => ImportHub::NAME,
        ];
    }
}
