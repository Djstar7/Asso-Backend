<?php

namespace App\Services;

use App\Models\User;
use App\Models\Order;
use App\Models\WalletTransaction;
use App\Models\WalletBalance;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WalletService
{
    /**
     * Devise de base du wallet KPay.
     *
     * Source de vérité UNIQUE du solde KPay = table `wallet_balances` (multi-devise),
     * et non plus les colonnes legacy `users.kpay_wallet_balance` / `locked_kpay_balance`
     * (qui n'étaient plus lues par l'affichage ni les retraits → « split-brain »).
     * Les commandes étant libellées en XAF, on opère sur la ligne XAF.
     */
    private const KPAY_CURRENCY = 'XAF';

    /**
     * Récupère (ou crée) la ligne `wallet_balances` KPay de l'utilisateur, verrouillée
     * en base pour la durée de la transaction courante (anti double-écriture / course).
     */
    private function lockedKpayBalance(User $user): WalletBalance
    {
        WalletBalance::firstOrCreate(
            ['user_id' => $user->id, 'currency' => self::KPAY_CURRENCY],
            ['balance' => 0, 'locked_balance' => 0]
        );

        return WalletBalance::where('user_id', $user->id)
            ->where('currency', self::KPAY_CURRENCY)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Recharge le wallet d'un utilisateur
     *
     * @param User $user
     * @param float $amount
     * @param Transaction|null $transaction Transaction source (KPay, PayPal, etc.)
     * @param string $description
     * @param array $metadata
     * @param string $provider Provider (kpay ou paypal)
     * @return WalletTransaction
     */
    public function credit(
        User $user,
        float $amount,
        ?Transaction $transaction = null,
        string $description = 'Recharge wallet',
        array $metadata = [],
        string $provider = 'kpay'
    ): WalletTransaction {
        return DB::transaction(function () use ($user, $amount, $transaction, $description, $metadata, $provider) {
            // KPay → wallet_balances (source de vérité) ; PayPal → colonne users legacy.
            if ($provider === 'paypal') {
                $balanceBefore = $user->paypal_wallet_balance ?? 0;
                $balanceAfter = $balanceBefore + $amount;
                $user->paypal_wallet_balance = $balanceAfter;
                $user->save();
            } else {
                $row = $this->lockedKpayBalance($user);
                $balanceBefore = (float) $row->balance;
                $balanceAfter = $balanceBefore + $amount;
                $row->balance = $balanceAfter;
                $row->save();
            }

            // Cr�er la transaction
            $walletTransaction = WalletTransaction::create([
                'user_id' => $user->id,
                'type' => 'credit',
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'description' => $description,
                'payment_id' => $transaction?->id,
                'metadata' => $metadata,
                'status' => 'completed',
                'provider' => $provider,
            ]);

            Log::info("[WalletService] Wallet credited", [
                'user_id' => $user->id,
                'provider' => $provider,
                'amount' => $amount,
                'balance_after' => $balanceAfter,
                'transaction_id' => $walletTransaction->id,
            ]);

            return $walletTransaction;
        });
    }

    /**
     * D�bite le wallet d'un utilisateur
     *
     * @param User $user
     * @param float $amount
     * @param string $description
     * @param string|null $referenceType Type de r�f�rence (order, subscription, etc.)
     * @param int|null $referenceId ID de la r�f�rence
     * @param array $metadata
     * @param string $provider Provider OBLIGATOIRE (kpay ou paypal)
     * @return WalletTransaction
     * @throws \Exception Si solde insuffisant ou provider invalide
     */
    public function debit(
        User $user,
        float $amount,
        string $description,
        ?string $referenceType = null,
        ?int $referenceId = null,
        array $metadata = [],
        string $provider,
        string $status = 'completed'
    ): WalletTransaction {
        return DB::transaction(function () use ($user, $amount, $description, $referenceType, $referenceId, $metadata, $provider, $status) {
            // Valider le provider
            if (!in_array($provider, ['kpay', 'paypal'])) {
                throw new \Exception("Provider invalide. Doit �tre 'kpay' ou 'paypal'.");
            }

            // KPay → wallet_balances (source de vérité) ; PayPal → colonne users legacy.
            if ($provider === 'paypal') {
                $balanceBefore = $user->paypal_wallet_balance ?? 0;
                $locked = $user->locked_paypal_balance ?? 0;
            } else {
                $row = $this->lockedKpayBalance($user);
                $balanceBefore = (float) $row->balance;
                $locked = (float) $row->locked_balance;
            }
            $available = $balanceBefore - $locked;

            // V�rifier le solde disponible (non bloqué)
            if ($available < $amount) {
                $providerName = $provider === 'paypal' ? 'PayPal' : 'KPay';
                throw new \Exception("Solde {$providerName} disponible insuffisant. Disponible: {$available} FCFA, Montant requis: {$amount} FCFA");
            }

            $balanceAfter = $balanceBefore - $amount;

            // Mettre � jour le solde du wallet sp�cifique
            if ($provider === 'paypal') {
                $user->paypal_wallet_balance = $balanceAfter;
                $user->save();
            } else {
                $row->balance = $balanceAfter;
                $row->save();
            }

            // Cr�er la transaction
            $walletTransaction = WalletTransaction::create([
                'user_id' => $user->id,
                'type' => 'debit',
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'description' => $description,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'metadata' => $metadata,
                'status' => $status,
                'provider' => $provider,
            ]);

            Log::info("[WalletService] Wallet debited", [
                'user_id' => $user->id,
                'provider' => $provider,
                'amount' => $amount,
                'balance_after' => $balanceAfter,
                'transaction_id' => $walletTransaction->id,
                'reference' => "{$referenceType}:{$referenceId}",
            ]);

            return $walletTransaction;
        });
    }

    /**
     * Rembourse une transaction (remet l'argent dans le wallet)
     *
     * @param WalletTransaction $originalTransaction Transaction � rembourser
     * @param string|null $reason Raison du remboursement
     * @return WalletTransaction
     */
    public function refund(WalletTransaction $originalTransaction, ?string $reason = null): WalletTransaction
    {
        // On ne peut rembourser qu'un d�bit
        if ($originalTransaction->type !== 'debit') {
            throw new \Exception("Seuls les d�bits peuvent �tre rembours�s");
        }

        if ($originalTransaction->status !== 'completed') {
            throw new \Exception("Seules les transactions compl�t�es peuvent �tre rembours�es");
        }

        $user = $originalTransaction->user;
        $amount = abs($originalTransaction->amount);
        $description = "Remboursement: " . ($reason ?? $originalTransaction->description);

        return $this->credit(
            $user,
            $amount,
            null,
            $description,
            [
                'refund_of_transaction_id' => $originalTransaction->id,
                'refund_reason' => $reason,
                'original_description' => $originalTransaction->description,
            ],
            $originalTransaction->provider
        );
    }

    /**
     * Ajoute un bonus au wallet (promotion, parrainage, etc.)
     *
     * @param User $user
     * @param float $amount
     * @param string $description
     * @param array $metadata
     * @param string $provider
     * @return WalletTransaction
     */
    public function addBonus(
        User $user,
        float $amount,
        string $description = 'Bonus',
        array $metadata = [],
        string $provider = 'kpay'
    ): WalletTransaction {
        return DB::transaction(function () use ($user, $amount, $description, $metadata, $provider) {
            if ($provider === 'paypal') {
                $balanceBefore = $user->paypal_wallet_balance ?? 0;
                $balanceAfter = $balanceBefore + $amount;
                $user->paypal_wallet_balance = $balanceAfter;
                $user->save();
            } else {
                $row = $this->lockedKpayBalance($user);
                $balanceBefore = (float) $row->balance;
                $balanceAfter = $balanceBefore + $amount;
                $row->balance = $balanceAfter;
                $row->save();
            }

            $walletTransaction = WalletTransaction::create([
                'user_id' => $user->id,
                'type' => 'bonus',
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'description' => $description,
                'metadata' => $metadata,
                'status' => 'completed',
                'provider' => $provider,
            ]);

            Log::info("[WalletService] Bonus added", [
                'user_id' => $user->id,
                'amount' => $amount,
                'balance_after' => $balanceAfter,
                'provider' => $provider,
            ]);

            return $walletTransaction;
        });
    }

    /**
     * Ajustement manuel par un admin
     *
     * @param User $user
     * @param float $amount Positif pour ajouter, n�gatif pour retirer
     * @param User $admin Admin effectuant l'ajustement
     * @param string $reason Raison de l'ajustement
     * @param string $provider
     * @return WalletTransaction
     */
    public function adjustBalance(
        User $user,
        float $amount,
        User $admin,
        string $reason,
        string $provider = 'kpay'
    ): WalletTransaction {
        return DB::transaction(function () use ($user, $amount, $admin, $reason, $provider) {
            if ($provider === 'paypal') {
                $balanceBefore = $user->paypal_wallet_balance ?? 0;
            } else {
                $row = $this->lockedKpayBalance($user);
                $balanceBefore = (float) $row->balance;
            }
            $balanceAfter = $balanceBefore + $amount;

            // Ne pas permettre de balance n�gative
            if ($balanceAfter < 0) {
                throw new \Exception("L'ajustement rendrait le solde n�gatif");
            }

            if ($provider === 'paypal') {
                $user->paypal_wallet_balance = $balanceAfter;
                $user->save();
            } else {
                $row->balance = $balanceAfter;
                $row->save();
            }

            $walletTransaction = WalletTransaction::create([
                'user_id' => $user->id,
                'type' => 'adjustment',
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'description' => "Ajustement admin: {$reason}",
                'admin_id' => $admin->id,
                'metadata' => [
                    'admin_name' => $admin->name,
                    'reason' => $reason,
                ],
                'status' => 'completed',
                'provider' => $provider,
            ]);

            Log::warning("[WalletService] Balance adjusted by admin", [
                'user_id' => $user->id,
                'admin_id' => $admin->id,
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'reason' => $reason,
                'provider' => $provider,
            ]);

            return $walletTransaction;
        });
    }

    /**
     * R�cup�re l'historique des transactions avec pagination
     *
     * @param User $user
     * @param int $perPage
     * @param string|null $type Filtrer par type
     * @param string|null $provider Filtrer par provider
     * @return \Illuminate\Pagination\LengthAwarePaginator
     */
    public function getTransactionHistory(User $user, int $perPage = 20, ?string $type = null, ?string $provider = null)
    {
        $query = $user->walletTransactions()->with(['payment', 'admin'])->recent();

        if ($type) {
            $query->where('type', $type);
        }

        if ($provider) {
            $query->where('provider', $provider);
        }

        return $query->paginate($perPage);
    }

    /**
     * Statistiques du wallet pour un utilisateur
     *
     * @param User $user
     * @return array
     */
    public function getWalletStats(User $user): array
    {
        $transactions = $user->walletTransactions()->completed();

        // Soldes KPay multi-devise (source de vérité : wallet_balances)
        $kpayBalances = $user->walletBalances()->get()->map(fn($wb) => [
            'currency' => $wb->currency,
            'balance' => max(0, (float) $wb->balance),
            'locked' => max(0, (float) $wb->locked_balance),
            'available' => max(0, (float) $wb->balance - (float) $wb->locked_balance),
        ])->values();

        // Solde KPay principal = devise de base (XAF)
        $kpayBalance = $user->kpayBalanceFor('XAF');
        $lockedKPay = (float) ($user->walletBalances()->where('currency', 'XAF')->value('locked_balance') ?? 0);

        $paypalBalance = $user->paypal_wallet_balance ?? 0;
        $totalBalance = $kpayBalance + $paypalBalance;

        // Stats par provider
        $kpayCredits = $transactions->clone()->where('provider', 'kpay')->credits()->sum('amount');
        $kpayDebits = abs($transactions->clone()->where('provider', 'kpay')->debits()->sum('amount'));

        $paypalCredits = $transactions->clone()->where('provider', 'paypal')->credits()->sum('amount');
        $paypalDebits = abs($transactions->clone()->where('provider', 'paypal')->debits()->sum('amount'));

        $totalCredits = $kpayCredits + $paypalCredits;
        $totalDebits = $kpayDebits + $paypalDebits;

        // Soldes bloqués ($lockedKPay déjà calculé depuis wallet_balances plus haut)
        $lockedPaypal = $user->locked_paypal_balance ?? 0;
        $totalLocked = $lockedKPay + $lockedPaypal;
        $availableTotal = $totalBalance - $totalLocked;

        return [
            // Soldes par provider
            'kpay_wallet_balance' => $kpayBalance,
            'kpay_balances' => $kpayBalances, // multi-devise (par devise)
            'paypal_balance' => $paypalBalance,
            'current_balance' => $totalBalance,
            'formatted_balance' => number_format($totalBalance, 0, ',', ' ') . ' FCFA',

            // Soldes bloqués (escrow)
            'locked_kpay_balance' => $lockedKPay,
            'locked_paypal_balance' => $lockedPaypal,
            'total_locked_balance' => $totalLocked,
            'available_balance' => $availableTotal,
            'formatted_available_balance' => number_format($availableTotal, 0, ',', ' ') . ' FCFA',

            // Totaux
            'total_credits' => $totalCredits,
            'total_debits' => $totalDebits,
            'total_transactions' => $transactions->count(),
            'last_transaction' => $transactions->first(),

            // Par provider
            'kpay_credits' => $kpayCredits,
            'kpay_debits' => $kpayDebits,
            'paypal_credits' => $paypalCredits,
            'paypal_debits' => $paypalDebits,
        ];
    }

    // ================================
    // ESCROW (Solde bloqué)
    // ================================

    /**
     * Bloque un montant sur le wallet (escrow)
     * Le montant reste dans le wallet mais n'est plus disponible
     */
    public function lockFunds(
        User $user,
        float $amount,
        string $description,
        ?string $referenceType = null,
        ?int $referenceId = null,
        array $metadata = [],
        string $provider = 'kpay'
    ): WalletTransaction {
        return DB::transaction(function () use ($user, $amount, $description, $referenceType, $referenceId, $metadata, $provider) {
            if (!in_array($provider, ['kpay', 'paypal'])) {
                throw new \Exception("Provider invalide. Doit être 'kpay' ou 'paypal'.");
            }

            // KPay → wallet_balances (verrou de ligne) ; PayPal → colonne users legacy.
            if ($provider === 'paypal') {
                $user->lockForUpdate();
                $user->refresh();
                $balance = $user->paypal_wallet_balance ?? 0;
                $lockedBefore = $user->locked_paypal_balance ?? 0;
            } else {
                $row = $this->lockedKpayBalance($user);
                $balance = (float) $row->balance;
                $lockedBefore = (float) $row->locked_balance;
            }

            $available = $balance - $lockedBefore;

            if ($available < $amount) {
                $providerName = $provider === 'paypal' ? 'PayPal' : 'KPay';
                throw new \Exception("Solde {$providerName} disponible insuffisant. Disponible: {$available} FCFA, Requis: {$amount} FCFA");
            }

            $lockedAfter = $lockedBefore + $amount;
            if ($provider === 'paypal') {
                $user->locked_paypal_balance = $lockedAfter;
                $user->save();
            } else {
                $row->locked_balance = $lockedAfter;
                $row->save();
            }

            $walletTransaction = WalletTransaction::create([
                'user_id' => $user->id,
                'type' => 'lock',
                'amount' => $amount,
                'balance_before' => $balance,
                'balance_after' => $balance,
                'description' => $description,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'metadata' => array_merge($metadata, [
                    'locked_amount' => $amount,
                    'locked_balance_after' => $lockedAfter,
                ]),
                'status' => 'completed',
                'provider' => $provider,
            ]);

            Log::info("[WalletService] Funds locked (escrow)", [
                'user_id' => $user->id,
                'provider' => $provider,
                'amount' => $amount,
                'locked_total' => $lockedAfter,
                'transaction_id' => $walletTransaction->id,
                'reference' => "{$referenceType}:{$referenceId}",
            ]);

            return $walletTransaction;
        });
    }

    /**
     * Débloque un montant (annulation de l'escrow, remise à disposition)
     */
    public function unlockFunds(
        User $user,
        float $amount,
        string $description,
        ?string $referenceType = null,
        ?int $referenceId = null,
        array $metadata = [],
        string $provider = 'kpay'
    ): WalletTransaction {
        return DB::transaction(function () use ($user, $amount, $description, $referenceType, $referenceId, $metadata, $provider) {
            // KPay → wallet_balances (verrou de ligne) ; PayPal → colonne users legacy.
            if ($provider === 'paypal') {
                $user->lockForUpdate();
                $user->refresh();
                $balance = $user->paypal_wallet_balance ?? 0;
                $lockedBefore = $user->locked_paypal_balance ?? 0;
            } else {
                $row = $this->lockedKpayBalance($user);
                $balance = (float) $row->balance;
                $lockedBefore = (float) $row->locked_balance;
            }

            if ($lockedBefore < $amount) {
                throw new \Exception("Impossible de débloquer {$amount} FCFA. Seulement {$lockedBefore} FCFA bloqué.");
            }

            $lockedAfter = $lockedBefore - $amount;
            if ($provider === 'paypal') {
                $user->locked_paypal_balance = $lockedAfter;
                $user->save();
            } else {
                $row->locked_balance = $lockedAfter;
                $row->save();
            }

            $walletTransaction = WalletTransaction::create([
                'user_id' => $user->id,
                'type' => 'unlock',
                'amount' => $amount,
                'balance_before' => $balance,
                'balance_after' => $balance,
                'description' => $description,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'metadata' => array_merge($metadata, [
                    'unlocked_amount' => $amount,
                    'locked_balance_after' => $lockedAfter,
                ]),
                'status' => 'completed',
                'provider' => $provider,
            ]);

            Log::info("[WalletService] Funds unlocked", [
                'user_id' => $user->id,
                'provider' => $provider,
                'amount' => $amount,
                'locked_remaining' => $lockedAfter,
                'transaction_id' => $walletTransaction->id,
            ]);

            return $walletTransaction;
        });
    }

    /**
     * Libère l'escrow : débloque ET débite en une seule opération atomique.
     * Utilisé quand la commande est confirmée / livrée.
     */
    public function releaseEscrow(
        User $user,
        float $amount,
        string $description,
        ?string $referenceType = null,
        ?int $referenceId = null,
        array $metadata = [],
        string $provider = 'kpay'
    ): WalletTransaction {
        return DB::transaction(function () use ($user, $amount, $description, $referenceType, $referenceId, $metadata, $provider) {
            // KPay → wallet_balances (verrou de ligne) ; PayPal → colonne users legacy.
            if ($provider === 'paypal') {
                $user->lockForUpdate();
                $user->refresh();
                $balanceBefore = $user->paypal_wallet_balance ?? 0;
                $lockedBefore = $user->locked_paypal_balance ?? 0;
            } else {
                $row = $this->lockedKpayBalance($user);
                $balanceBefore = (float) $row->balance;
                $lockedBefore = (float) $row->locked_balance;
            }

            if ($lockedBefore < $amount) {
                throw new \Exception("Montant bloqué insuffisant pour libération. Bloqué: {$lockedBefore} FCFA, Requis: {$amount} FCFA");
            }

            // Débloquer + débiter en même temps
            $lockedAfter = $lockedBefore - $amount;
            $balanceAfter = $balanceBefore - $amount;
            if ($provider === 'paypal') {
                $user->locked_paypal_balance = $lockedAfter;
                $user->paypal_wallet_balance = $balanceAfter;
                $user->save();
            } else {
                $row->locked_balance = $lockedAfter;
                $row->balance = $balanceAfter;
                $row->save();
            }

            $walletTransaction = WalletTransaction::create([
                'user_id' => $user->id,
                'type' => 'escrow_release',
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'description' => $description,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'metadata' => array_merge($metadata, [
                    'released_amount' => $amount,
                    'locked_balance_after' => $lockedAfter,
                ]),
                'status' => 'completed',
                'provider' => $provider,
            ]);

            Log::info("[WalletService] Escrow released", [
                'user_id' => $user->id,
                'provider' => $provider,
                'amount' => $amount,
                'balance_after' => $balanceAfter,
                'locked_remaining' => $lockedAfter,
                'transaction_id' => $walletTransaction->id,
            ]);

            return $walletTransaction;
        });
    }

    /**
     * V�rifie si un user peut effectuer un paiement avec son wallet
     *
     * @param User $user
     * @param float $amount
     * @param string|null $provider Provider sp�cifique (null = total des deux wallets)
     * @return array ['can_pay' => bool, 'message' => string, 'missing_amount' => float]
     */
    public function canPayWithWallet(User $user, float $amount, ?string $provider = null): array
    {
        if ($provider) {
            // KPay → wallet_balances (source de vérité) ; PayPal → colonne users legacy.
            if ($provider === 'paypal') {
                $totalBalance = $user->paypal_wallet_balance ?? 0;
                $locked = $user->locked_paypal_balance ?? 0;
            } else {
                $totalBalance = $user->kpayBalanceFor(self::KPAY_CURRENCY);
                $locked = $totalBalance - $user->kpayAvailableFor(self::KPAY_CURRENCY);
            }
            $available = $totalBalance - $locked;
            $providerName = $provider === 'paypal' ? 'PayPal' : 'KPay';

            $canPay = $available >= $amount;

            return [
                'can_pay' => $canPay,
                'total_balance' => $totalBalance,
                'locked_balance' => $locked,
                'available_balance' => $available,
                'required_amount' => $amount,
                'missing_amount' => $canPay ? 0 : ($amount - $available),
                'provider' => $provider,
                'message' => $canPay
                    ? "Paiement possible avec wallet {$providerName}"
                    : "Solde {$providerName} disponible insuffisant. Il vous manque " . number_format($amount - $available, 0, ',', ' ') . " FCFA",
            ];
        } else {
            // KPay depuis wallet_balances (XAF), PayPal depuis la colonne users.
            $kpayBalance = $user->kpayBalanceFor(self::KPAY_CURRENCY);
            $kpayAvailable = $user->kpayAvailableFor(self::KPAY_CURRENCY);
            $paypalBalance = $user->paypal_wallet_balance ?? 0;
            $paypalLocked = $user->locked_paypal_balance ?? 0;

            $totalBalance = $kpayBalance + $paypalBalance;
            $totalLocked = ($kpayBalance - $kpayAvailable) + $paypalLocked;
            $available = $kpayAvailable + ($paypalBalance - $paypalLocked);

            $canPay = $available >= $amount;

            return [
                'can_pay' => $canPay,
                'kpay_wallet_balance' => $kpayBalance,
                'paypal_balance' => $paypalBalance,
                'total_balance' => $totalBalance,
                'locked_balance' => $totalLocked,
                'available_balance' => $available,
                'required_amount' => $amount,
                'missing_amount' => $canPay ? 0 : ($amount - $available),
                'message' => $canPay
                    ? "Paiement possible"
                    : "Solde disponible insuffisant. Il vous manque " . number_format($amount - $available, 0, ',', ' ') . " FCFA",
            ];
        }
    }
}
