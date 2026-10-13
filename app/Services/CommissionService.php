<?php

namespace App\Services;

use App\Models\CommissionRange;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Commission ASSO — source de vérité unique, pilotée depuis l'admin (onglet Commissions).
 *
 * Modèle « MAJORATION » : le vendeur fixe SON prix et reçoit exactement ce prix ;
 * l'acheteur voit et paie ce prix majoré de la commission ASSO, rien d'autre.
 *
 *   prix acheteur = prix vendeur × (1 + taux / 100)
 *
 * Taux de vente = plage `commission_ranges` active couvrant le prix vendeur (en XAF) ;
 * à défaut, Setting `default_sale_commission_rate` (0 % par défaut).
 * Le prix acheteur et la part ASSO sont FIGÉS sur la commande à sa création : un
 * changement de taux ultérieur ne modifie jamais une commande passée.
 *
 * Les autres commissions restent des majorations configurées dans l'admin :
 *  - livraison : Setting `delivery_commission_rate` (%) du prix HT du partenaire ;
 *  - Diaspo    : Setting `diaspo_commission_rate` (%) ajouté au prix du voyageur.
 */
class CommissionService
{
    /** Plages actives mémorisées pour la requête courante (listes de produits). */
    private static ?Collection $ranges = null;

    /** Réinitialise le cache des plages (après modification dans l'admin, en tests). */
    public static function flush(): void
    {
        self::$ranges = null;
    }

    /** Taux de commission vente (%) applicable à un prix vendeur exprimé en XAF. */
    public static function rateFor(float $sellerPriceXaf): float
    {
        self::$ranges ??= CommissionRange::where('is_active', true)->orderBy('min_amount')->get();

        $range = self::$ranges->first(
            fn ($r) => (float) $r->min_amount <= $sellerPriceXaf && (float) $r->max_amount >= $sellerPriceXaf
        );

        $rate = $range
            ? (float) $range->percentage
            : (float) Setting::get('default_sale_commission_rate', 0);

        return max(0.0, min(100.0, $rate));
    }

    /** Taux appliqué à un produit (déterminé par son prix de base vendeur, en XAF). */
    public static function rateForProduct(Product $product): float
    {
        $baseXaf = $product->basePriceXaf();

        // Sans taux, la tranche ne peut pas être choisie : taux par défaut.
        return $baseXaf !== null
            ? self::rateFor($baseXaf)
            : max(0.0, min(100.0, (float) Setting::get('default_sale_commission_rate', 0)));
    }

    /** Applique la majoration à un prix vendeur (arrondi à l'unité en FCFA, au centime sinon). */
    public static function markup(float $sellerPrice, float $rate, string $currency = 'XAF'): float
    {
        $price = $sellerPrice * (1 + $rate / 100);

        return in_array(strtoupper($currency), ['XAF', 'XOF'], true)
            ? round($price)
            : round($price, 2);
    }

    /**
     * Prix PUBLICS (acheteur) d'un produit, dans sa devise source et en XAF.
     *
     * @return array{rate: float, price: float, price_xaf: ?float, currency: string, min_price: ?float, max_price: ?float, formatted_price: string}
     */
    public static function buyerPricing(Product $product): array
    {
        $rate = self::rateForProduct($product);
        $currency = strtoupper($product->currency ?? 'XAF');
        $baseXaf = $product->basePriceXaf();

        $price = self::markup((float) $product->price, $rate, $currency);
        $min = $product->min_price ? self::markup((float) $product->min_price, $rate, $currency) : null;
        $max = $product->max_price ? self::markup((float) $product->max_price, $rate, $currency) : null;

        $symbol = $product->currency_symbol;
        $formatted = $product->price_type === 'variable'
            ? number_format((float) $min, 0, ',', ' ') . ' - ' . number_format((float) $max, 0, ',', ' ') . ' ' . $symbol
            : number_format($price, 0, ',', ' ') . ' ' . $symbol;

        return [
            'rate' => $rate,
            'price' => $price,
            // null si la devise n'a aucun taux : l'app affiche alors le prix dans sa devise.
            'price_xaf' => $baseXaf !== null ? self::markup($baseXaf, $rate, 'XAF') : null,
            'currency' => $currency,
            'min_price' => $min,
            'max_price' => $max,
            'formatted_price' => $formatted,
        ];
    }

    /**
     * Prix public d'un produit pour une carte simple (chat, boutique, notification) :
     * dans sa devise, en XAF et avec le code devise, pour que l'app convertisse juste.
     *
     * @return array{price: float, price_xaf: ?float, currency: string}
     */
    public static function buyerPriceFields(Product $product): array
    {
        $pricing = self::buyerPricing($product);

        return ['price' => $pricing['price'], 'price_xaf' => $pricing['price_xaf'], 'currency' => $pricing['currency']];
    }

    /** Prix acheteur d'un produit dans sa devise source (raccourci pour les cartes simples). */
    public static function buyerPrice(Product $product): float
    {
        return self::markup(
            (float) $product->price,
            self::rateForProduct($product),
            (string) ($product->currency ?? 'XAF')
        );
    }

    /**
     * Compte plateforme ASSO qui reçoit les commissions
     * (Setting `platform_account_email`, puis admin@asso.com, puis l'utilisateur #1).
     */
    public static function platformAccount(): ?User
    {
        $email = Setting::get('platform_account_email', 'admin@asso.com');

        return User::where('email', $email)->first()
            ?? User::where('email', 'admin@asso.com')->first()
            ?? User::find(1);
    }
}
