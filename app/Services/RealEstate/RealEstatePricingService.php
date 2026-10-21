<?php

namespace App\Services\RealEstate;

use App\Models\RealEstate\Cabinet;
use App\Models\RealEstate\Listing;
use App\Models\RealEstate\Unit;
use App\Models\Setting;

/**
 * Seul endroit où se calculent les prix de la rubrique Immobilier.
 *
 * - Le montant affiché inclut la part ASSO (10 % par défaut).
 * - La commission partenaire (5 % par défaut) est calculée sur le montant net.
 * - Le cabinet reçoit la commission et, s'il gère l'annonce, le montant net.
 */
class RealEstatePricingService
{
    public const DEFAULTS = [
        'immo_asso_markup_rate' => 10,
        'immo_partner_commission_rate' => 5,
        'immo_default_cabinet_id' => 0,
        'immo_land_default_deposit_percent' => 10,
        'immo_visit_window_days' => 7,
        'immo_land_release_days' => 7,
        'immo_low_stock_threshold' => 4,
        'immo_popular_views' => 100,
    ];

    public static function setting(string $key): float
    {
        return (float) Setting::get($key, self::DEFAULTS[$key] ?? 0);
    }

    public function markupRate(string $type): float
    {
        return self::setting('immo_asso_markup_rate');
    }

    public function partnerCommissionRate(): float
    {
        return self::setting('immo_partner_commission_rate');
    }

    /** Commission partner for this listing, or the configured default partner. */
    public function commissionCabinet(Listing $listing): ?Cabinet
    {
        if ($listing->cabinet_id) {
            $cabinet = $listing->relationLoaded('cabinet') ? $listing->cabinet : $listing->cabinet()->first();

            return $cabinet?->is_active ? $cabinet : null;
        }

        return Cabinet::defaultPartner();
    }

    /** Prix affiché avec la part ASSO, arrondi au franc. */
    public function displayed(int $net, string $type): int
    {
        if ($net <= 0) {
            return 0;
        }
        $raw = $net * (1 + $this->markupRate($type) / 100);

        return (int) round($raw);
    }

    /**
     * Décomposition d'un paiement. Tous les montants sont en FCFA.
     *
     * @return array{unit_price:int, advance_months:?int, base_amount:int, commission:int,
     *               total:int, owner_amount:int, platform_amount:int, deposit_percent:?float}
     */
    public function quote(Listing $listing, ?Unit $unit = null, ?int $advanceMonths = null): array
    {
        $net = (int) ($unit?->net_price ?? $listing->net_price);
        $unitPrice = $this->displayed($net, $listing->type);

        if ($listing->type === Listing::TYPE_LAND) {
            $percent = (float) ($listing->deposit_percent ?: self::setting('immo_land_default_deposit_percent'));
            $owner = (int) round($net * $percent / 100);
            $base = $owner + (int) round($owner * $this->markupRate($listing->type) / 100);
            $commission = (int) round($owner * $this->partnerCommissionRate() / 100);
            $months = null;
        } else {
            $months = max(1, (int) ($advanceMonths ?: $listing->advance_months ?: 1));
            $percent = null;
            $owner = $net * $months;
            $base = $owner + (int) round($owner * $this->markupRate($listing->type) / 100);
            $commission = (int) round($owner * $this->partnerCommissionRate() / 100);
        }

        $total = $base + $commission;

        return [
            'unit_price' => $unitPrice,
            'advance_months' => $months,
            'deposit_percent' => $percent,
            'base_amount' => $base,
            'commission' => $commission,
            'total' => $total,
            'owner_amount' => $owner,
            'platform_amount' => $base - $owner,
        ];
    }
}
