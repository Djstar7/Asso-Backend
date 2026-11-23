<?php

namespace App\Services\Suppliers;

use App\Models\Supplier;
use App\Services\ExchangeRateService;

/**
 * Prix de vente ASSO d'un produit fournisseur, en FCFA :
 *
 *   (coût fournisseur + transport jusqu'à Douala) en USD
 *   × taux USD→XAF × (1 + marge de change) × (1 + marge ASSO),
 *   arrondi aux 50 FCFA supérieurs.
 *
 * Le transport international est inclus dans le prix affiché ; seule la
 * livraison locale (Douala → client) s'ajoute au moment de la commande.
 */
class SupplierPricing
{
    public const ROUNDING_XAF = 50;

    private ?float $rate = null;

    public function __construct(private Supplier $supplier) {}

    public function usdToXafRate(): float
    {
        if ($this->rate === null) {
            $rate = ExchangeRateService::rate('USD', 'XAF');
            if (! $rate || $rate <= 0) {
                throw new SupplierImportException('Taux USD → FCFA indisponible : import impossible sans taux.');
            }
            $this->rate = $rate;
        }

        return $this->rate;
    }

    public function sellingPriceXaf(float $costUsd, float $freightUsd): float
    {
        $margin = (float) $this->supplier->setting('margin_percent', 0) / 100;
        $fxBuffer = (float) $this->supplier->setting('fx_buffer_percent', 0) / 100;
        $amount = ($costUsd + $freightUsd) * $this->usdToXafRate() * (1 + $fxBuffer) * (1 + $margin);

        return (float) (ceil($amount / self::ROUNDING_XAF) * self::ROUNDING_XAF);
    }
}
