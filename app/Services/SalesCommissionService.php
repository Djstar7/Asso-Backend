<?php

namespace App\Services;

use App\Exceptions\InvalidSalesCodeException;
use App\Models\PackageSubscription;
use App\Models\SalesAgent;
use App\Models\SalesCommission;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * P6 — Codes commerciaux et commissions sur les souscriptions de forfaits.
 *
 *  - Le vendeur saisit (facultatif) le code d'un commercial à la souscription ;
 *    il est rattaché à la PackageSubscription dès sa création.
 *  - À l'activation du forfait (paiement confirmé), une commission « à payer » est
 *    créée, une seule par souscription : montant payé × taux du commercial (sinon
 *    Setting `sales_commission_rate`), arrondi au franc.
 *  - L'admin la marque « payée » (versement fait hors plateforme, référence obligatoire)
 *    ou l'annule.
 */
class SalesCommissionService
{
    public const DEFAULT_RATE = 10.0;

    public function defaultRate(): float
    {
        return (float) Setting::get('sales_commission_rate', self::DEFAULT_RATE);
    }

    public function rateFor(SalesAgent $agent): float
    {
        return $agent->commission_rate !== null ? (float) $agent->commission_rate : $this->defaultRate();
    }

    public function computeCommission(float $amount, float $rate): float
    {
        return (float) round($amount * $rate / 100);
    }

    /** Commercial actif correspondant au code, ou null. */
    public function findActiveByCode(?string $code): ?SalesAgent
    {
        $code = SalesAgent::normalizeCode($code);
        if ($code === '') {
            return null;
        }

        return SalesAgent::active()->where('code', $code)->first();
    }

    /**
     * Résout le code saisi par le vendeur. Null si aucun code n'est saisi.
     *
     * @throws InvalidSalesCodeException code inconnu, désactivé ou auto-parrainage
     */
    public function resolveForVendor(?string $code, User $vendor): ?SalesAgent
    {
        if (SalesAgent::normalizeCode($code) === '') {
            return null;
        }

        $agent = $this->findActiveByCode($code);
        if (! $agent) {
            throw new InvalidSalesCodeException(__('packages.sales_code_invalid'));
        }
        if ($agent->user_id && $agent->user_id === $vendor->id) {
            throw new InvalidSalesCodeException(__('packages.sales_code_own'));
        }

        return $agent;
    }

    /**
     * Crée la commission d'une souscription payée portant un code commercial.
     * Idempotent (une seule commission par souscription). À appeler dans la
     * transaction d'activation du forfait.
     */
    public function recordForSubscription(PackageSubscription $subscription): ?SalesCommission
    {
        if (! $subscription->sales_agent_id || $subscription->status !== 'paid') {
            return null;
        }

        $existing = SalesCommission::where('package_subscription_id', $subscription->id)->first();
        if ($existing) {
            return $existing;
        }

        $agent = SalesAgent::withTrashed()->find($subscription->sales_agent_id);
        if (! $agent) {
            return null;
        }

        $package = $subscription->package;
        $amount = (float) $subscription->amount_xaf;
        $rate = $this->rateFor($agent);

        $commission = SalesCommission::create([
            'sales_agent_id' => $agent->id,
            'package_subscription_id' => $subscription->id,
            'vendor_id' => $subscription->user_id,
            'package_id' => $subscription->package_id,
            'sales_code' => $subscription->sales_code ?: $agent->code,
            'package_name' => $package?->name ?? ($subscription->metadata['package_name'] ?? 'Forfait'),
            'package_type' => $package?->type,
            'amount_paid_xaf' => $amount,
            'payment_method' => $subscription->payment_method,
            'transaction_reference' => $subscription->payment_reference,
            'sold_at' => $subscription->paid_at ?? now(),
            'rate' => $rate,
            'commission_amount' => $this->computeCommission($amount, $rate),
            'status' => SalesCommission::STATUS_DUE,
        ]);

        Log::info('[SalesCommission] Commission enregistrée', [
            'commission_id' => $commission->id,
            'sales_agent_id' => $agent->id,
            'subscription_id' => $subscription->id,
            'amount' => $commission->commission_amount,
        ]);

        return $commission;
    }

    /**
     * Marque des commissions « à payer » comme payées. Les autres sont ignorées.
     *
     * @param  iterable<int>  $ids
     * @return int nombre de commissions effectivement marquées payées
     */
    public function markPaid(iterable $ids, string $payoutReference, User $admin): int
    {
        $ids = Collection::make($ids)->map(fn ($id) => (int) $id)->filter()->unique();
        if ($ids->isEmpty()) {
            return 0;
        }

        return DB::transaction(fn () => SalesCommission::whereIn('id', $ids)
            ->where('status', SalesCommission::STATUS_DUE)
            ->lockForUpdate()
            ->get()
            ->each(fn (SalesCommission $c) => $c->update([
                'status' => SalesCommission::STATUS_PAID,
                'payout_reference' => $payoutReference,
                'paid_at' => now(),
                'paid_by' => $admin->id,
            ]))
            ->count());
    }

    /** Annule une commission encore « à payer ». Renvoie false si elle ne l'est plus. */
    public function cancel(SalesCommission $commission, string $reason): bool
    {
        return DB::transaction(function () use ($commission, $reason) {
            $c = SalesCommission::whereKey($commission->id)->lockForUpdate()->first();
            if (! $c || $c->status !== SalesCommission::STATUS_DUE) {
                return false;
            }
            $c->update([
                'status' => SalesCommission::STATUS_CANCELLED,
                'cancel_reason' => $reason,
                'cancelled_at' => now(),
            ]);

            return true;
        });
    }
}
