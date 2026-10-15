<?php

namespace App\Services;

use App\Exceptions\InvalidSalesCodeException;
use App\Exceptions\OwnSalesCodeException;
use App\Models\PackageSubscription;
use App\Models\SalesAgent;
use App\Models\SalesCommission;
use App\Models\Setting;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * P6 — Codes commerciaux et commissions sur les souscriptions de forfaits.
 *
 *  - Tout utilisateur peut devenir commercial depuis l'application en acceptant les
 *    modalités (Setting `sales_program_terms`) : il reçoit un code unique.
 *  - Le vendeur saisit (facultatif) le code d'un commercial à sa PREMIÈRE souscription
 *    uniquement ; il est rattaché à la PackageSubscription dès sa création.
 *  - À l'activation du forfait (paiement confirmé), une commission est créée, une seule
 *    par vendeur : montant payé × taux du commercial (sinon Setting
 *    `sales_commission_rate`), arrondi au franc.
 *  - Commercial lié à un compte : la commission est versée aussitôt dans son wallet.
 *    Sinon elle reste « à payer » et l'admin la marque payée (versement hors plateforme)
 *    ou l'annule.
 */
class SalesCommissionService
{
    public const DEFAULT_RATE = 10.0;

    public function __construct(protected WalletService $walletService) {}

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

    /** Le code commercial n'est accepté qu'avant la première souscription payée du vendeur. */
    public function canUseSalesCode(User $vendor): bool
    {
        return ! PackageSubscription::where('user_id', $vendor->id)->where('status', 'paid')->exists();
    }

    /**
     * Résout le code saisi par le vendeur. Null si aucun code n'est saisi, ou si c'est
     * son propre code et qu'il a accepté de continuer sans commission.
     *
     * @throws OwnSalesCodeException son propre code, sans acceptation
     * @throws InvalidSalesCodeException code inconnu, désactivé ou vendeur ayant déjà souscrit
     */
    public function resolveForVendor(?string $code, User $vendor, bool $acceptOwnCode = false): ?SalesAgent
    {
        if (SalesAgent::normalizeCode($code) === '') {
            return null;
        }

        $agent = $this->findActiveByCode($code);
        if (! $agent) {
            throw new InvalidSalesCodeException(__('packages.sales_code_invalid'));
        }
        if (! $this->canUseSalesCode($vendor)) {
            throw new InvalidSalesCodeException(__('packages.sales_code_not_first'));
        }
        if ($this->isOwnCode($agent, $vendor)) {
            if ($acceptOwnCode) {
                return null;
            }
            throw new OwnSalesCodeException(__('packages.sales_code_own'));
        }

        return $agent;
    }

    public function isOwnCode(SalesAgent $agent, User $vendor): bool
    {
        return $agent->user_id !== null && $agent->user_id === $vendor->id;
    }

    /**
     * Crée la commission d'une souscription payée portant un code commercial, et la
     * verse dans le wallet du commercial s'il a un compte. Idempotent : une seule
     * commission par souscription et par vendeur. À appeler dans la transaction
     * d'activation du forfait.
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

        // Verrou sur le vendeur : deux souscriptions confirmées en même temps ne
        // peuvent pas produire deux commissions.
        User::whereKey($subscription->user_id)->lockForUpdate()->first();
        $vendorAlreadyCounted = SalesCommission::where('vendor_id', $subscription->user_id)
            ->where('status', '!=', SalesCommission::STATUS_CANCELLED)
            ->exists();
        if ($vendorAlreadyCounted) {
            Log::info('[SalesCommission] Vendeur déjà compté pour un commercial, aucune commission', [
                'subscription_id' => $subscription->id,
                'vendor_id' => $subscription->user_id,
            ]);

            return null;
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

        $this->payToWallet($commission, $agent);

        return $commission;
    }

    /** Verse la commission dans le wallet du commercial lié à un compte, et la marque payée. */
    private function payToWallet(SalesCommission $commission, SalesAgent $agent): void
    {
        $agentUser = $agent->user_id ? User::find($agent->user_id) : null;
        if (! $agentUser || (float) $commission->commission_amount <= 0) {
            return;
        }

        $walletTransaction = $this->walletService->credit(
            user: $agentUser,
            amount: (float) $commission->commission_amount,
            description: WalletTransaction::label('sales_commission', ['package' => $commission->package_name]),
            metadata: [
                'kind' => 'sales_commission',
                'reference_type' => 'sales_commission',
                'sales_commission_id' => $commission->id,
                'subscription_id' => $commission->package_subscription_id,
                'rate' => (float) $commission->rate,
            ],
        );

        $commission->update([
            'status' => SalesCommission::STATUS_PAID,
            'payout_reference' => 'WALLET-'.$walletTransaction->id,
            'paid_at' => now(),
        ]);
    }

    /**
     * Inscrit l'utilisateur comme commercial après acceptation des modalités.
     * Idempotent : un utilisateur déjà commercial garde son code.
     */
    public function enroll(User $user): SalesAgent
    {
        return DB::transaction(function () use ($user) {
            User::whereKey($user->id)->lockForUpdate()->first();

            $agent = $this->agentFor($user);
            if ($agent) {
                if (! $agent->terms_accepted_at) {
                    $agent->update(['terms_accepted_at' => now()]);
                }

                return $agent;
            }

            return SalesAgent::create([
                'user_id' => $user->id,
                'first_name' => $user->first_name ?: 'Commercial',
                'last_name' => $user->last_name ?: '',
                'email' => $user->email,
                'phone' => $user->phone,
                'is_active' => true,
                'source' => SalesAgent::SOURCE_SELF,
                'terms_accepted_at' => now(),
            ]);
        });
    }

    /** Commercial rattaché au compte (le plus ancien s'il y en a plusieurs), ou null. */
    public function agentFor(User $user): ?SalesAgent
    {
        return SalesAgent::where('user_id', $user->id)->orderBy('id')->first();
    }

    public function programTerms(): string
    {
        return (string) Setting::get('sales_program_terms', '');
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
