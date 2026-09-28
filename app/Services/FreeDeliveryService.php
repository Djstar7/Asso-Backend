<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductPriceTier;
use App\Models\ProductVariant;

/**
 * Livraison gratuite offerte par le vendeur (boutique entière ou produit par produit,
 * cf. Product::hasFreeDelivery).
 *
 * L'acheteur voit le prix de chaque offre de livraison, barré, et ne le paie pas.
 * La course reste réglée comme d'habitude — part transporteur au partenaire,
 * commission livraison à ASSO — mais c'est le vendeur qui la finance :
 *
 *   part vendeur = prix vendeur des articles − prix de livraison affiché
 *
 * La gratuité ne vaut que si TOUS les articles l'offrent, et tombe d'elle-même
 * quand la course coûte plus que la part du vendeur : l'acheteur paie alors la
 * livraison normalement.
 */
class FreeDeliveryService
{
    /** @param iterable<Product> $products */
    public static function cartEligible(iterable $products): bool
    {
        $any = false;
        foreach ($products as $product) {
            if (!$product->hasFreeDelivery()) {
                return false;
            }
            $any = true;
        }

        return $any;
    }

    /** La course est-elle offerte, compte tenu de ce que touche le vendeur ? */
    public static function applies(bool $eligible, float $deliveryPrice, ?float $vendorNet): bool
    {
        return $eligible && $vendorNet !== null && $deliveryPrice > 0 && $deliveryPrice <= $vendorNet;
    }

    /**
     * Part vendeur (XAF) d'un panier, avant livraison : prix vendeur, hors commission
     * ASSO, ou prix du palier en gros. Même calcul qu'à la création de la commande.
     * Null si un prix ne peut pas être converti en XAF.
     *
     * @param array $items [['product_id', 'quantity', 'variant_id'?, 'price_tier_id'?], …]
     */
    public static function vendorNetXaf(array $items): ?float
    {
        $ids = collect($items)->pluck('product_id')->map(fn ($id) => (int) $id);
        $products = Product::whereIn('id', $ids)->get()->keyBy('id');
        $tiers = ProductPriceTier::whereIn('id', collect($items)->pluck('price_tier_id')->filter())->get()->keyBy('id');
        $variants = ProductVariant::whereIn('id', collect($items)->pluck('variant_id')->filter())->get()->keyBy('id');

        $total = 0.0;
        foreach ($items as $item) {
            $product = $products->get((int) $item['product_id']);
            if (!$product) {
                return null;
            }
            $quantity = max(1, (int) ($item['quantity'] ?? 1));

            $tier = $tiers->get((int) ($item['price_tier_id'] ?? 0));
            if ($tier && $tier->product_id === $product->id) {
                $currency = strtoupper($tier->currency ?? 'XAF');
                $unit = (float) $tier->unit_price;
            } else {
                $variant = $variants->get((int) ($item['variant_id'] ?? 0));
                $currency = strtoupper($product->currency ?? 'XAF');
                $unit = (float) $product->price
                    + ($variant && $variant->product_id === $product->id ? (float) $variant->price_adjustment : 0);
            }

            if ($currency !== 'XAF') {
                $conv = ExchangeRateService::convert($currency, 'XAF', $unit);
                if (empty($conv['success']) || $conv['amount'] === null) {
                    return null;
                }
                $unit = round((float) $conv['amount'], 2);
            }
            $total += $unit * $quantity;
        }

        return round($total, 2);
    }
}
