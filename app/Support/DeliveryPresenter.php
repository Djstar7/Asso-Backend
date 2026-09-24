<?php

namespace App\Support;

use App\Models\DelivererCompany;
use App\Models\Order;
use App\Services\OrderTrackingService;

/**
 * Bloc « livraison » d'une commande pour l'API : partenaire, catégorie, détail du
 * prix figé à la commande, numéro et lien de suivi transporteur, historique daté.
 */
class DeliveryPresenter
{
    public static function forOrder(Order $order): array
    {
        $snapshot = $order->delivery_breakdown ?? [];
        $company = $order->relationLoaded('deliveryCompany') ? $order->deliveryCompany : null;
        $isCarrier = $order->isCarrierDelivery();

        $trackingUrl = $company?->trackingUrl($order->carrier_tracking_number);
        if (!$trackingUrl && $order->is_wholesale && $order->carrier_tracking_number && $order->shipping_option_id) {
            $template = \App\Models\ImportShippingOption::whereKey($order->shipping_option_id)->value('tracking_url_template');
            $trackingUrl = $template ? str_replace('{number}', rawurlencode($order->carrier_tracking_number), $template) : null;
        }

        return [
            'mode' => $order->delivery_mode,
            'is_carrier' => $isCarrier,
            'company_name' => $snapshot['company_name'] ?? $company?->name,
            'service_type' => $snapshot['service_type'] ?? null,
            'service_type_label' => $snapshot['service_type_label'] ?? null,
            'service_mode' => $snapshot['service_mode'] ?? $company?->service_mode,
            'service_mode_label' => $snapshot['service_mode_label']
                ?? ($company ? (DelivererCompany::SERVICE_MODES[$company->service_mode] ?? null) : null),
            'route_label' => $snapshot['route_label'] ?? null,
            'vehicle' => $order->delivery_vehicle,
            'vehicle_label' => $snapshot['vehicle_label'] ?? null,
            'delivery_option' => $snapshot['delivery_option'] ?? ($isCarrier && !$order->hasLastMileDelivery() ? 'agency_pickup' : 'home_delivery'),
            'delivery_option_label' => $snapshot['delivery_option_label'] ?? null,
            'last_mile' => $order->hasLastMileDelivery(),
            'lead_time' => $snapshot['lead_time'] ?? null,
            'conditions' => $snapshot['conditions'] ?? null,
            'price_grid' => $snapshot['price_grid'] ?? [],
            'weight_kg' => $order->shipping_weight_kg,
            'breakdown' => $snapshot !== [] ? array_intersect_key($snapshot, array_flip([
                'weight_kg', 'range_label', 'range_price', 'extra_kg', 'extra_per_kg', 'extra_price', 'legs',
                'carrier_price_ht', 'prices_exclude_vat', 'vat_rate', 'vat_amount', 'carrier_price',
                'asso_commission', 'total',
            ])) : null,
            // Import en gros : trajet Chine / Dubaï / Turquie → Douala, avant SOLEX.
            'import_leg' => $snapshot['import_leg'] ?? null,
            'carrier_tracking_number' => $order->carrier_tracking_number,
            'carrier_tracking_url' => $trackingUrl,
            'tracking_status' => $order->tracking_status,
            'tracking_status_label' => $order->tracking_status ? (OrderTrackingService::STEPS[$order->tracking_status] ?? null) : null,
            // Acheteur : confirmer le retrait en agence. À domicile, le coursier clôture
            // avec le code à 6 chiffres.
            'can_confirm_reception' => $isCarrier && !$order->hasLastMileDelivery() && $order->status === 'shipped',
            'timeline' => $order->relationLoaded('trackingEvents') ? OrderTrackingService::timeline($order) : [],
        ];
    }

    /**
     * Point d'ENLÈVEMENT présenté au livreur : la boutique (ou l'agence du
     * partenaire pour un dernier kilomètre, ou l'entrepôt ASSO de Douala pour un
     * import livré dans Douala). Le coursier va chercher le colis ici, il lui faut
     * donc de quoi s'y rendre ET appeler sur place.
     */
    public static function pickupFor(Order $order): array
    {
        $fromHub = $order->leavesFromImportHub();
        $shop = ($fromHub ? ImportHub::shop() : null) ?? $order->items->first()?->product?->shop;
        $isAgency = $order->hasLastMileDelivery() && !$fromHub;
        $company = $order->relationLoaded('deliveryCompany') ? $order->deliveryCompany : $order->deliveryCompany;

        if ($isAgency) {
            return [
                'kind' => 'agency',
                'name' => trim('Agence ' . ($company?->name ?? '')),
                'phone' => $company?->phone,
                'address' => null,
                'city' => null,
                'latitude' => null,
                'longitude' => null,
            ];
        }

        return [
            'kind' => 'shop',
            'name' => $shop?->name,
            // Le vendeur est le contact sur place si la boutique n'a pas de ligne.
            'phone' => $shop?->phone ?: $shop?->user?->phone,
            'address' => trim(implode(' — ', array_filter([$shop?->location_label, $shop?->address]))) ?: null,
            'city' => $shop?->city,
            'latitude' => $shop?->latitude !== null ? (float) $shop->latitude : null,
            'longitude' => $shop?->longitude !== null ? (float) $shop->longitude : null,
        ];
    }

    /**
     * Point de LIVRAISON présenté au livreur : l'acheteur et son adresse.
     */
    public static function dropoffFor(Order $order): array
    {
        return [
            'name' => $order->user?->name ?: 'Client',
            'phone' => $order->customer_phone ?: ($order->user?->phone ?? null),
            'address' => $order->delivery_address,
            'address_details' => $order->delivery_address_details,
            'latitude' => $order->delivery_latitude !== null ? (float) $order->delivery_latitude : null,
            'longitude' => $order->delivery_longitude !== null ? (float) $order->delivery_longitude : null,
        ];
    }
}
