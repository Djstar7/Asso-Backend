<?php

namespace App\Services;

use App\Exceptions\StripePayoutUnavailableException;
use App\Models\DelivererCompany;
use App\Models\PartnerLedgerEntry;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Virement réel de ce qu'ASSO doit à un partenaire (livraison ou maritime) vers
 * son IBAN, depuis la fiche partenaire de l'admin.
 *
 * L'IBAN est synchronisé chez Stripe comme celui des utilisateurs (compte
 * Connect « custom » + compte bancaire), mais saisi par l'admin. Le virement
 * suit le même chemin que les retraits IBAN (StripeService::payoutToVendor) ;
 * il est rapproché par les webhooks payout.paid / payout.failed.
 */
class PartnerPayoutService
{
    public function __construct(
        private StripeService $stripe,
        private StripeWithdrawalService $withdrawals,
    ) {
    }

    public static function hasIban(DelivererCompany $company): bool
    {
        return !empty($company->stripe_account_id);
    }

    /** État du compte Stripe du partenaire (null sans IBAN). */
    public function accountState(DelivererCompany $company): ?array
    {
        return self::hasIban($company) && $this->stripe->isConfigured()
            ? $this->stripe->accountState($company->stripe_account_id)
            : null;
    }

    /**
     * Crée le compte Connect du partenaire avec son IBAN, ou remplace l'IBAN et
     * met à jour l'identité du représentant.
     *
     * @param array $data country, iban, account_holder_name, first_name, last_name,
     *                    email, birth_date, phone, address_line1, address_city,
     *                    address_postal_code
     * @throws \RuntimeException message affichable
     */
    public function syncIban(DelivererCompany $company, array $data, ?string $ip): void
    {
        if (!$this->stripe->isConfigured()) {
            throw new \RuntimeException(__('wallet.stripe_withdrawal_unavailable'));
        }

        $iban = strtoupper(preg_replace('/\s+/', '', $data['iban']));
        $country = strtoupper($data['country']);
        $kyc = [
            'country' => $country,
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'] ?? $company->email,
            'birth_date' => $data['birth_date'],
            'phone' => $data['phone'],
            'address_line1' => $data['address_line1'],
            'address_city' => $data['address_city'],
            'address_postal_code' => $data['address_postal_code'],
        ];

        // Compte créé avec d'autres clés (test → live) : on repart d'un nouveau.
        if (self::hasIban($company) && ($this->stripe->accountState($company->stripe_account_id)['missing'] ?? false)) {
            $company->stripe_account_id = null;
        }

        try {
            if (!self::hasIban($company)) {
                $result = $this->stripe->createCustomAccountWithBank($kyc + [
                    'iban' => $iban,
                    'account_holder_name' => $data['account_holder_name'],
                    'tos_ip' => $ip,
                    'tos_date' => time(),
                ]);
                $accountId = $result['id'];
            } else {
                $result = $this->stripe->replaceExternalAccount($company->stripe_account_id, [
                    'country' => $country,
                    'iban' => $iban,
                    'account_holder_name' => $data['account_holder_name'],
                ]);
                $this->stripe->updateAccountKyc($company->stripe_account_id, $kyc);
                $accountId = $company->stripe_account_id;
            }
        } catch (\Throwable $e) {
            Log::error('[PartnerPayout] Synchronisation IBAN refusée', ['company_id' => $company->id, 'error' => $e->getMessage()]);
            throw new \RuntimeException('Stripe a refusé ces informations : ' . $e->getMessage());
        }

        $company->update([
            'stripe_account_id' => $accountId,
            'stripe_external_last4' => $result['external_last4'] ?? substr($iban, -4),
            'stripe_bank_country' => $result['bank_country'] ?? $country,
            'stripe_account_holder_name' => $data['account_holder_name'],
            'stripe_submitted_at' => now(),
        ]);
    }

    /**
     * Vire $amountXaf (au plus le solde dû) sur l'IBAN du partenaire.
     *
     * @throws \RuntimeException message affichable, rien n'est enregistré
     */
    public function payout(DelivererCompany $company, float $amountXaf, ?int $adminId): PartnerLedgerEntry
    {
        if (!$this->stripe->isConfigured()) {
            throw new \RuntimeException(__('wallet.stripe_withdrawal_unavailable'));
        }
        if (!self::hasIban($company)) {
            throw new \RuntimeException("{$company->name} n'a pas encore d'IBAN synchronisé.");
        }

        // Verrou sur le partenaire : deux virements simultanés ne dépassent pas le dû.
        return DB::transaction(function () use ($company, $amountXaf, $adminId) {
            DelivererCompany::whereKey($company->id)->lockForUpdate()->first();

            return $this->payoutLocked($company, $amountXaf, $adminId);
        });
    }

    private function payoutLocked(DelivererCompany $company, float $amountXaf, ?int $adminId): PartnerLedgerEntry
    {
        $amountXaf = round($amountXaf, 2);
        $due = PartnerLedgerService::balance($company->id)['due'];
        if ($amountXaf <= 0 || $amountXaf > $due) {
            throw new \RuntimeException('Montant invalide : il doit être positif et ne pas dépasser le dû (' . number_format($due, 0, ',', ' ') . ' FCFA).');
        }

        $payoutCurrency = $this->withdrawals->payoutCurrency($company->stripe_bank_country);
        $payoutAmount = ExchangeRateService::convertAmount(PaymentMethodService::PIVOT, $payoutCurrency, $amountXaf);
        if ($payoutAmount === null) {
            throw new \RuntimeException(__('payments.rate_unavailable', ['currency' => PaymentMethodService::PIVOT, 'pivot' => $payoutCurrency]));
        }
        $payoutAmount = round($payoutAmount, 2);

        $min = (float) Setting::get('min_stripe_withdrawal_amount', 5);
        if ($payoutAmount < $min) {
            throw new \RuntimeException(__('wallet.amount_too_low', ['amount' => number_format($min, 2, ',', ' '), 'currency' => $payoutCurrency]));
        }

        try {
            $result = $this->stripe->payoutToVendor($company->stripe_account_id, $payoutAmount, $payoutCurrency);
        } catch (StripePayoutUnavailableException $e) {
            Log::error('[PartnerPayout] Virement impossible', ['company_id' => $company->id, 'reason' => $e->reason, 'detail' => $e->getMessage()]);
            throw new \RuntimeException($this->withdrawals->unavailableMessage($e->reason));
        } catch (\Throwable $e) {
            Log::error('[PartnerPayout] Virement refusé par Stripe', ['company_id' => $company->id, 'error' => $e->getMessage()]);
            throw new \RuntimeException('Virement refusé par Stripe : ' . $e->getMessage());
        }

        return PartnerLedgerEntry::create([
            'deliverer_company_id' => $company->id,
            'type' => PartnerLedgerEntry::TYPE_PAYOUT,
            'amount' => $amountXaf,
            'description' => "Virement IBAN ****{$company->stripe_external_last4}",
            'payment_method' => 'stripe_iban',
            'reference' => $result['payout_id'] ?? $result['transfer_id'] ?? null,
            'paid_on' => today(),
            'recorded_by' => $adminId,
            'status' => PartnerLedgerEntry::STATUS_PROCESSING,
            'payout_amount' => (float) ($result['payout_amount'] ?? $payoutAmount),
            'payout_currency' => $payoutCurrency,
            'stripe_transfer_id' => $result['transfer_id'] ?? null,
            'stripe_payout_id' => $result['payout_id'] ?? null,
        ]);
    }

    /** Webhook payout.paid / payout.failed d'un virement partenaire. Renvoie true si trouvé. */
    public static function settle(object $payout, bool $paid): bool
    {
        $entry = self::entryForPayout($payout);
        if (!$entry) {
            return false;
        }
        if ($entry->status === PartnerLedgerEntry::STATUS_PROCESSING) {
            $entry->update($paid
                ? ['status' => PartnerLedgerEntry::STATUS_COMPLETED]
                // Virement échoué : il ne compte plus, le dû remonte d'autant.
                : ['status' => PartnerLedgerEntry::STATUS_FAILED, 'failure_message' => (string) ($payout->failure_message ?? $payout->failure_code ?? 'payout_failed')]);
        }

        return true;
    }

    private static function entryForPayout(object $payout): ?PartnerLedgerEntry
    {
        $q = PartnerLedgerEntry::where('type', PartnerLedgerEntry::TYPE_PAYOUT);
        if (!empty($payout->id) && ($entry = (clone $q)->where('stripe_payout_id', $payout->id)->first())) {
            return $entry;
        }
        $transferId = $payout->metadata->transfer_id ?? null;

        return $transferId ? $q->where('stripe_transfer_id', $transferId)->first() : null;
    }
}
