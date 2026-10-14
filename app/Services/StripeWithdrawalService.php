<?php

namespace App\Services;

use App\Exceptions\StripePayoutUnavailableException;
use App\Exceptions\StripeWithdrawalException;
use App\Models\PlatformWithdrawal;
use App\Models\Setting;
use App\Models\User;
use App\Models\WalletBalance;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Virement du Wallet ASSO vers l'IBAN validé (Stripe Connect) d'un utilisateur,
 * vendeur comme acheteur.
 *
 * Deux déclencheurs : le retrait demandé depuis l'application
 * (WalletController::initiateStripeWithdrawal) et le virement fait par ASSO
 * depuis le back-office à l'issue d'un litige (remboursement client payé par
 * carte, ou part du vendeur). Dans les deux cas le Wallet est débité du montant
 * viré : c'est lui qui reste la source de vérité du solde.
 */
class StripeWithdrawalService
{
    public function __construct(
        private StripeService $stripe,
        private FirebaseMessagingService $fcm,
    ) {
    }

    /** IBAN validé par ASSO et compte Stripe créé : le virement est possible. */
    public static function isReady(User $user): bool
    {
        return $user->stripe_account_status === 'approved' && !empty($user->stripe_account_id);
    }

    /** Devise de payout Stripe selon le pays de la banque (défaut EUR). */
    public function payoutCurrency(?string $bankCountry): string
    {
        $country = strtoupper((string) $bankCountry);
        $eur = ['FR', 'DE', 'ES', 'IT', 'BE', 'NL', 'PT', 'IE', 'FI', 'AT', 'LU', 'GR'];
        return match (true) {
            in_array($country, $eur, true) => 'EUR',
            $country === 'GB' => 'GBP',
            $country === 'US' => 'USD',
            default => 'EUR',
        };
    }

    /**
     * Soldes de l'utilisateur convertibles vers la devise de son compte bancaire.
     *
     * Chaque entrée porte le solde disponible, le taux appliqué et l'équivalent
     * versable — c'est ce que l'application affiche (« 12 000 FCFA ≈ 18,29 EUR »).
     * Une devise sans taux connu est ignorée plutôt que devinée.
     *
     * @return array<string, array{currency:string, available:float, rate:float, payout_equivalent:float}>
     */
    public function sources(User $user, string $payoutCurrency): array
    {
        $sources = [];

        foreach ($user->walletBalances()->get() as $balance) {
            $available = max(0, (float) $balance->balance - (float) $balance->locked_balance);
            if ($available <= 0) {
                continue;
            }

            $currency = strtoupper($balance->currency);

            if ($currency === $payoutCurrency) {
                $rate = 1.0;
            } else {
                $conversion = ExchangeRateService::convert($currency, $payoutCurrency, 1);
                if (!($conversion['success'] ?? false)) {
                    continue; // taux inconnu : on ne propose pas cette devise.
                }
                $rate = (float) $conversion['rate'];
            }

            $sources[$currency] = [
                'currency' => $currency,
                'available' => round($available, 2),
                'rate' => $rate,
                'payout_equivalent' => round($available * $rate, 2),
            ];
        }

        return $sources;
    }

    /**
     * Solde à débiter : celui demandé s'il existe, sinon celui qui permet le plus
     * gros virement (l'utilisateur n'a pas à choisir dans le cas courant).
     */
    public function pickSource(array $sources, ?string $requested): ?array
    {
        if ($requested !== null) {
            return $sources[$requested] ?? null;
        }

        if (empty($sources)) {
            return null;
        }

        usort($sources, fn ($a, $b) => $b['payout_equivalent'] <=> $a['payout_equivalent']);

        return $sources[0];
    }

    /**
     * Message utilisateur pour un virement IBAN impossible. Le détail technique
     * (devise du solde plateforme, requirements Stripe) reste dans les logs.
     */
    public function unavailableMessage(string $reason): string
    {
        return match ($reason) {
            'account_not_ready' => __('wallet.stripe_unavailable.account_not_ready'),
            'payout_refused', 'transfer_refused' => __('wallet.stripe_unavailable.refused'),
            'platform_funds' => __('wallet.stripe_unavailable.platform_funds'),
            default => __('wallet.stripe_unavailable.default'),
        };
    }

    /**
     * Débite le Wallet et vire l'équivalent sur l'IBAN de l'utilisateur.
     *
     * `$amount` est exprimé dans la devise du PORTEFEUILLE (`$currency`, ex. XAF) ;
     * la conversion vers la devise du compte bancaire (ex. EUR) est faite ici, au
     * taux du moment, et conservée sur le retrait pour l'audit.
     *
     * @param array{notes?:?string, ip?:?string, user_agent?:?string, admin_id?:?int, metadata?:array} $context
     * @return array{withdrawal:PlatformWithdrawal, wallet_transaction:WalletTransaction, currency:string, payout_amount:float, payout_currency:string, rate:float, iban_last4:string, balance_before:float}
     *
     * @throws StripeWithdrawalException message prêt à afficher + statut HTTP
     */
    public function withdraw(User $user, float $amount, ?string $currency = null, array $context = []): array
    {
        // 1) Stripe doit être configuré.
        if (!$this->stripe->isConfigured()) {
            throw new StripeWithdrawalException(__('wallet.stripe_withdrawal_unavailable'), 503);
        }

        // 2) L'utilisateur doit avoir un compte de virement VALIDÉ.
        if (!self::isReady($user)) {
            throw new StripeWithdrawalException(__('wallet.iban_not_validated'), 422);
        }

        // Devise versée sur l'IBAN, déduite du pays de la banque (ex. FR → EUR).
        $payoutCurrency = $this->payoutCurrency($user->stripe_bank_country);

        // 3) Choix du solde débité + taux de conversion vers la devise du virement.
        $requestedCurrency = $currency !== null ? strtoupper($currency) : null;
        $source = $this->pickSource($this->sources($user, $payoutCurrency), $requestedCurrency);

        if (!$source) {
            throw new StripeWithdrawalException($requestedCurrency
                ? __('wallet.no_convertible_balance_in_currency', ['currency' => $requestedCurrency, 'payout_currency' => $payoutCurrency])
                : __('wallet.no_convertible_balance'), 400);
        }

        $sourceCurrency = $source['currency'];
        $rate = $source['rate'];
        $amount = round($amount, 2);                                  // débité au Wallet
        $payoutAmount = round($amount * $rate, 2);                    // versé sur l'IBAN

        // 4) Minimum, exprimé dans la devise du virement.
        $minWithdrawalAmount = (float) Setting::get('min_stripe_withdrawal_amount', 5);
        if ($payoutAmount < $minWithdrawalAmount) {
            $minSource = $rate > 0 ? ceil($minWithdrawalAmount / $rate) : null;
            throw new StripeWithdrawalException($minSource
                ? __('wallet.amount_too_low_with_source', [
                    'amount' => number_format($minWithdrawalAmount, 2, ',', ' '),
                    'currency' => $payoutCurrency,
                    'source_amount' => number_format($minSource, 0, ',', ' '),
                    'source_currency' => $sourceCurrency,
                ])
                : __('wallet.amount_too_low', [
                    'amount' => number_format($minWithdrawalAmount, 2, ',', ' '),
                    'currency' => $payoutCurrency,
                ]), 422);
        }

        // Pré-contrôle rapide non autoritatif (la vérif autoritative est sous verrou).
        if ($amount > $source['available']) {
            Log::warning('[StripeWithdrawal] Solde insuffisant', [
                'user_id' => $user->id,
                'currency' => $sourceCurrency,
                'available' => $source['available'],
                'requested_amount' => $amount,
            ]);
            throw new StripeWithdrawalException(__('wallet.insufficient_currency_balance', [
                'currency' => $sourceCurrency,
                'available' => number_format($source['available'], 2, ',', ' '),
            ]), 400);
        }

        // Pré-contrôle Stripe AVANT toute écriture : compte réellement activé et
        // solde plateforme disponible DANS CETTE DEVISE. Sans lui, on débitait le
        // wallet pour ensuite tout annuler par rollback sur une erreur Stripe opaque.
        try {
            $this->stripe->assertPayoutPossible($user->stripe_account_id, $payoutAmount, $payoutCurrency);
        } catch (StripePayoutUnavailableException $e) {
            Log::error('[StripeWithdrawal] Virement IBAN impossible (pré-contrôle)', [
                'user_id' => $user->id,
                'reason' => $e->reason,
                'detail' => $e->getMessage(),
                'context' => $e->context,
            ]);
            throw $this->unavailable($e);
        }

        try {
            DB::beginTransaction();

            // Verrou de ligne + re-vérification À L'INTÉRIEUR de la transaction (anti double-retrait).
            $walletBalance = WalletBalance::where('user_id', $user->id)
                ->where('currency', $sourceCurrency)
                ->lockForUpdate()
                ->first();

            $lockedAvailable = $walletBalance
                ? ((float) $walletBalance->balance - (float) $walletBalance->locked_balance)
                : 0.0;

            if ($amount > $lockedAvailable) {
                DB::rollBack();
                throw new StripeWithdrawalException(__('wallet.insufficient_currency_balance', [
                    'currency' => $sourceCurrency,
                    'available' => number_format($lockedAvailable, 2, ',', ' '),
                ]), 400);
            }

            $currentBalance = $walletBalance ? (float) $walletBalance->balance : 0.0;
            $ibanLast4 = $user->stripe_external_last4 ?? '****';
            $holder = $user->stripe_account_holder_name ?? $user->name;

            // Transaction wallet (débit immédiat pour bloquer les fonds), dans la
            // devise du portefeuille — c'est ce que l'utilisateur voit dans son historique.
            $walletTransaction = WalletTransaction::create([
                'user_id' => $user->id,
                'type' => 'debit',
                'amount' => $amount,
                'balance_before' => $currentBalance,
                'balance_after' => $currentBalance - $amount,
                'description' => $sourceCurrency !== $payoutCurrency
                    ? WalletTransaction::label('bank_transfer_converted', [
                        'last4' => $ibanLast4,
                        'amount' => number_format($payoutAmount, 2, ',', ' '),
                        'currency' => $payoutCurrency,
                    ])
                    : WalletTransaction::label('bank_transfer', ['last4' => $ibanLast4]),
                'status' => 'pending',
                'provider' => 'stripe',
                'reference_type' => 'platform_withdrawal',
                'reference_id' => null,
                'metadata' => array_merge([
                    'iban_last4' => $ibanLast4,
                    'currency' => $sourceCurrency,
                    'payout_currency' => $payoutCurrency,
                    'payout_amount' => $payoutAmount,
                    'exchange_rate' => $rate,
                    'stripe_account_id' => $user->stripe_account_id,
                    'initiated_at' => now()->toIso8601String(),
                ], $context['metadata'] ?? []),
            ]);

            // Débit immédiat (fonds bloqués).
            $user->debitKpay($sourceCurrency, $amount);

            $withdrawal = PlatformWithdrawal::create([
                'user_id' => $user->id,
                'admin_id' => $context['admin_id'] ?? null,
                'amount_requested' => $amount,
                'commission_rate' => 0,
                'commission_amount' => 0,
                'amount_sent' => $amount,
                'currency' => $sourceCurrency,
                'payout_amount' => $payoutAmount,
                'payout_currency' => $payoutCurrency,
                'exchange_rate' => $rate,
                'provider' => 'stripe',
                'payment_method' => 'stripe_connect',
                'payment_account' => "IBAN ****{$ibanLast4}",
                'payment_account_name' => $holder,
                'status' => 'pending',
                'transaction_reference' => $this->transactionReference(),
                'admin_notes' => $context['notes'] ?? null,
                'ip_address' => $context['ip'] ?? null,
                'user_agent' => $context['user_agent'] ?? null,
            ]);

            $walletTransaction->reference_id = $withdrawal->id;
            $walletTransaction->save();

            // Appel Stripe : transfer (autoritatif) + payout IBAN, dans la devise du compte.
            $result = $this->stripe->payoutToVendor($user->stripe_account_id, $payoutAmount, $payoutCurrency);

            // Le virement peut être financé depuis une autre devise (ex. solde CAD
            // pour un IBAN en EUR) : c'est le montant RÉELLEMENT versé qui fait foi.
            $paidAmount = (float) ($result['payout_amount'] ?? $payoutAmount);
            if (abs($paidAmount - $payoutAmount) > 0.001) {
                Log::info('[StripeWithdrawal] Montant versé ajusté après conversion', [
                    'withdrawal_id' => $withdrawal->id,
                    'attendu' => $payoutAmount,
                    'versé' => $paidAmount,
                    'financement' => $result['funding_currency'] ?? null,
                ]);
                $withdrawal->payout_amount = $paidAmount;
            }
            $payoutAmount = $paidAmount;

            $withdrawal->stripe_transfer_id = $result['transfer_id'] ?? null;
            $withdrawal->stripe_payout_id = $result['payout_id'] ?? null;
            $withdrawal->stripe_response = $result;
            $withdrawal->markAsProcessing();
            $withdrawal->save();

            DB::commit();
        } catch (StripeWithdrawalException $e) {
            throw $e;
        } catch (StripePayoutUnavailableException $e) {
            // Refusé sans mouvement d'argent net (transfer contre-passé le cas
            // échéant) : on annule l'écriture du débit et on explique pourquoi.
            DB::rollBack();
            Log::error('[StripeWithdrawal] Virement IBAN refusé par Stripe', [
                'user_id' => $user->id,
                'reason' => $e->reason,
                'detail' => $e->getMessage(),
                'context' => $e->context,
            ]);
            throw $this->unavailable($e);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[StripeWithdrawal] Erreur virement IBAN : ' . $e->getMessage(), ['user_id' => $user->id]);

            // Ne pas exposer le détail technique Stripe (reste tracé ci-dessus).
            throw new StripeWithdrawalException(__('wallet.bank_withdrawal_initiation_failed'), 500);
        }

        $user->refresh();

        Log::info('[StripeWithdrawal] Virement IBAN initié', [
            'withdrawal_id' => $withdrawal->id,
            'user_id' => $user->id,
            'admin_id' => $context['admin_id'] ?? null,
            'amount' => $amount,
            'currency' => $sourceCurrency,
            'payout_amount' => $payoutAmount,
            'payout_currency' => $payoutCurrency,
            'transfer_id' => $result['transfer_id'] ?? null,
            'payout_id' => $result['payout_id'] ?? null,
        ]);

        try {
            $this->fcm->sendToUser(
                $user,
                $user->localized('notifications.wallet_withdrawal_processing_bank.title'),
                $user->localized('notifications.wallet_withdrawal_processing_bank.body', [
                    'amount' => number_format($payoutAmount, 2, ',', ' '),
                    'currency' => $payoutCurrency,
                    'iban_last4' => $ibanLast4,
                ]),
                [
                    'type' => 'wallet_withdrawal_processing',
                    'provider' => 'stripe',
                    'currency' => $sourceCurrency,
                    'amount' => $amount,
                    'payout_currency' => $payoutCurrency,
                    'payout_amount' => $payoutAmount,
                    'withdrawal_id' => $withdrawal->id,
                    'transaction_reference' => $withdrawal->transaction_reference,
                    'new_balance' => $user->kpayBalanceFor($sourceCurrency),
                ]
            );
        } catch (\Exception $e) {
            Log::error('[StripeWithdrawal] Notification FCM non envoyée : ' . $e->getMessage());
        }

        return [
            'withdrawal' => $withdrawal,
            'wallet_transaction' => $walletTransaction,
            'currency' => $sourceCurrency,
            'payout_amount' => $payoutAmount,
            'payout_currency' => $payoutCurrency,
            'rate' => $rate,
            'iban_last4' => $ibanLast4,
            'balance_before' => $currentBalance,
        ];
    }

    private function unavailable(StripePayoutUnavailableException $e): StripeWithdrawalException
    {
        return new StripeWithdrawalException(
            $this->unavailableMessage($e->reason),
            $e->reason === 'account_not_ready' ? 422 : 503,
        );
    }

    private function transactionReference(): string
    {
        return 'WTH-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(4));
    }
}
