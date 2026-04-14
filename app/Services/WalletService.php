<?php

namespace App\Services;

use App\Models\User;
use App\Models\Order;
use App\Models\WalletTransaction;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WalletService
{
    /**
     * Recharge le wallet d'un utilisateur
     *
     * @param User $user
     * @param float $amount
     * @param Transaction|null $transaction Transaction source (FreeMoPay, PayPal, etc.)
     * @param string $description
     * @param array $metadata
     * @param string $provider Provider (freemopay ou paypal)
     * @return WalletTransaction
     */
    public function credit(
        User $user,
        float $amount,
        ?Transaction $transaction = null,
        string $description = 'Recharge wallet',
        array $metadata = [],
        string $provider = 'freemopay'
    ): WalletTransaction {
        return DB::transaction(function () use ($user, $amount, $transaction, $description, $metadata, $provider) {
            // D�terminer quel wallet mettre � jour
            $walletField = $provider === 'paypal' ? 'paypal_wallet_balance' : 'freemopay_wallet_balance';

            $balanceBefore = $user->{$walletField} ?? 0;
            $balanceAfter = $balanceBefore + $amount;

            // Mettre � jour le solde du wallet sp�cifique
            $user->{$walletField} = $balanceAfter;
            $user->save();

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
     * @param string $provider Provider OBLIGATOIRE (freemopay ou paypal)
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
        string $provider
    ): WalletTransaction {
        return DB::transaction(function () use ($user, $amount, $description, $referenceType, $referenceId, $metadata, $provider) {
            // Valider le provider
            if (!in_array($provider, ['freemopay', 'paypal'])) {
                throw new \Exception("Provider invalide. Doit �tre 'freemopay' ou 'paypal'.");
            }

            // D�terminer quel wallet d�biter
            $walletField = $provider === 'paypal' ? 'paypal_wallet_balance' : 'freemopay_wallet_balance';
            $lockedField = $provider === 'paypal' ? 'locked_paypal_balance' : 'locked_freemopay_balance';

            $balanceBefore = $user->{$walletField} ?? 0;
            $locked = $user->{$lockedField} ?? 0;
            $available = $balanceBefore - $locked;

            // V�rifier le solde disponible (non bloqué)
            if ($available < $amount) {
                $providerName = $provider === 'paypal' ? 'PayPal' : 'FreeMoPay';
                throw new \Exception("Solde {$providerName} disponible insuffisant. Disponible: {$available} FCFA, Montant requis: {$amount} FCFA");
            }

            $balanceAfter = $balanceBefore - $amount;

            // Mettre � jour le solde du wallet sp�cifique
            $user->{$walletField} = $balanceAfter;
            $user->save();

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
                'status' => 'completed',
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
        string $provider = 'freemopay'
    ): WalletTransaction {
        return DB::transaction(function () use ($user, $amount, $description, $metadata, $provider) {
            $walletField = $provider === 'paypal' ? 'paypal_wallet_balance' : 'freemopay_wallet_balance';

            $balanceBefore = $user->{$walletField} ?? 0;
            $balanceAfter = $balanceBefore + $amount;

            $user->{$walletField} = $balanceAfter;
            $user->save();

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
        string $provider = 'freemopay'
    ): WalletTransaction {
        return DB::transaction(function () use ($user, $amount, $admin, $reason, $provider) {
            $walletField = $provider === 'paypal' ? 'paypal_wallet_balance' : 'freemopay_wallet_balance';

            $balanceBefore = $user->{$walletField} ?? 0;
            $balanceAfter = $balanceBefore + $amount;

            // Ne pas permettre de balance n�gative
            if ($balanceAfter < 0) {
                throw new \Exception("L'ajustement rendrait le solde n�gatif");
            }

            $user->{$walletField} = $balanceAfter;
            $user->save();

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

        // R�cup�rer les soldes s�par�s
        $freemopayBalance = $user->freemopay_wallet_balance ?? 0;
        $paypalBalance = $user->paypal_wallet_balance ?? 0;
        $totalBalance = $freemopayBalance + $paypalBalance;

        // Stats par provider
        $freemopayCredits = $transactions->clone()->where('provider', 'freemopay')->credits()->sum('amount');
        $freemopayDebits = abs($transactions->clone()->where('provider', 'freemopay')->debits()->sum('amount'));

        $paypalCredits = $transactions->clone()->where('provider', 'paypal')->credits()->sum('amount');
        $paypalDebits = abs($transactions->clone()->where('provider', 'paypal')->debits()->sum('amount'));

        $totalCredits = $freemopayCredits + $paypalCredits;
        $totalDebits = $freemopayDebits + $paypalDebits;

        // Soldes bloqués
        $lockedFreemopay = $user->locked_freemopay_balance ?? 0;
        $lockedPaypal = $user->locked_paypal_balance ?? 0;
        $totalLocked = $lockedFreemopay + $lockedPaypal;
        $availableTotal = $totalBalance - $totalLocked;

        return [
            // Soldes par provider
            'freemopay_balance' => $freemopayBalance,
            'paypal_balance' => $paypalBalance,
            'current_balance' => $totalBalance,
            'formatted_balance' => number_format($totalBalance, 0, ',', ' ') . ' FCFA',

            // Soldes bloqués (escrow)
            'locked_freemopay_balance' => $lockedFreemopay,
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
            'freemopay_credits' => $freemopayCredits,
            'freemopay_debits' => $freemopayDebits,
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
        string $provider = 'freemopay'
    ): WalletTransaction {
        return DB::transaction(function () use ($user, $amount, $description, $referenceType, $referenceId, $metadata, $provider) {
            $user->lockForUpdate();
            $user->refresh();

            if (!in_array($provider, ['freemopay', 'paypal'])) {
                throw new \Exception("Provider invalide. Doit être 'freemopay' ou 'paypal'.");
            }

            $walletField = $provider === 'paypal' ? 'paypal_wallet_balance' : 'freemopay_wallet_balance';
            $lockedField = $provider === 'paypal' ? 'locked_paypal_balance' : 'locked_freemopay_balance';

            $available = ($user->{$walletField} ?? 0) - ($user->{$lockedField} ?? 0);

            if ($available < $amount) {
                $providerName = $provider === 'paypal' ? 'PayPal' : 'FreeMoPay';
                throw new \Exception("Solde {$providerName} disponible insuffisant. Disponible: {$available} FCFA, Requis: {$amount} FCFA");
            }

            $lockedBefore = $user->{$lockedField} ?? 0;
            $user->{$lockedField} = $lockedBefore + $amount;
            $user->save();

            $walletTransaction = WalletTransaction::create([
                'user_id' => $user->id,
                'type' => 'lock',
                'amount' => $amount,
                'balance_before' => $user->{$walletField},
                'balance_after' => $user->{$walletField},
                'description' => $description,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'metadata' => array_merge($metadata, [
                    'locked_amount' => $amount,
                    'locked_balance_after' => $user->{$lockedField},
                ]),
                'status' => 'completed',
                'provider' => $provider,
            ]);

            Log::info("[WalletService] Funds locked (escrow)", [
                'user_id' => $user->id,
                'provider' => $provider,
                'amount' => $amount,
                'locked_total' => $user->{$lockedField},
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
        string $provider = 'freemopay'
    ): WalletTransaction {
        return DB::transaction(function () use ($user, $amount, $description, $referenceType, $referenceId, $metadata, $provider) {
            $user->lockForUpdate();
            $user->refresh();

            $lockedField = $provider === 'paypal' ? 'locked_paypal_balance' : 'locked_freemopay_balance';
            $walletField = $provider === 'paypal' ? 'paypal_wallet_balance' : 'freemopay_wallet_balance';

            $lockedBefore = $user->{$lockedField} ?? 0;

            if ($lockedBefore < $amount) {
                throw new \Exception("Impossible de débloquer {$amount} FCFA. Seulement {$lockedBefore} FCFA bloqué.");
            }

            $user->{$lockedField} = $lockedBefore - $amount;
            $user->save();

            $walletTransaction = WalletTransaction::create([
                'user_id' => $user->id,
                'type' => 'unlock',
                'amount' => $amount,
                'balance_before' => $user->{$walletField},
                'balance_after' => $user->{$walletField},
                'description' => $description,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'metadata' => array_merge($metadata, [
                    'unlocked_amount' => $amount,
                    'locked_balance_after' => $user->{$lockedField},
                ]),
                'status' => 'completed',
                'provider' => $provider,
            ]);

            Log::info("[WalletService] Funds unlocked", [
                'user_id' => $user->id,
                'provider' => $provider,
                'amount' => $amount,
                'locked_remaining' => $user->{$lockedField},
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
        string $provider = 'freemopay'
    ): WalletTransaction {
        return DB::transaction(function () use ($user, $amount, $description, $referenceType, $referenceId, $metadata, $provider) {
            $user->lockForUpdate();
            $user->refresh();

            $walletField = $provider === 'paypal' ? 'paypal_wallet_balance' : 'freemopay_wallet_balance';
            $lockedField = $provider === 'paypal' ? 'locked_paypal_balance' : 'locked_freemopay_balance';

            $lockedBefore = $user->{$lockedField} ?? 0;
            $balanceBefore = $user->{$walletField} ?? 0;

            if ($lockedBefore < $amount) {
                throw new \Exception("Montant bloqué insuffisant pour libération. Bloqué: {$lockedBefore} FCFA, Requis: {$amount} FCFA");
            }

            // Débloquer + débiter en même temps
            $user->{$lockedField} = $lockedBefore - $amount;
            $user->{$walletField} = $balanceBefore - $amount;
            $user->save();

            $walletTransaction = WalletTransaction::create([
                'user_id' => $user->id,
                'type' => 'escrow_release',
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $user->{$walletField},
                'description' => $description,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'metadata' => array_merge($metadata, [
                    'released_amount' => $amount,
                    'locked_balance_after' => $user->{$lockedField},
                ]),
                'status' => 'completed',
                'provider' => $provider,
            ]);

            Log::info("[WalletService] Escrow released", [
                'user_id' => $user->id,
                'provider' => $provider,
                'amount' => $amount,
                'balance_after' => $user->{$walletField},
                'locked_remaining' => $user->{$lockedField},
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
            $walletField = $provider === 'paypal' ? 'paypal_wallet_balance' : 'freemopay_wallet_balance';
            $lockedField = $provider === 'paypal' ? 'locked_paypal_balance' : 'locked_freemopay_balance';
            $totalBalance = $user->{$walletField} ?? 0;
            $locked = $user->{$lockedField} ?? 0;
            $available = $totalBalance - $locked;
            $providerName = $provider === 'paypal' ? 'PayPal' : 'FreeMoPay';

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
            $available = $user->available_total_balance;
            $totalBalance = ($user->freemopay_wallet_balance ?? 0) + ($user->paypal_wallet_balance ?? 0);
            $totalLocked = $user->total_locked_balance;

            $canPay = $available >= $amount;

            return [
                'can_pay' => $canPay,
                'freemopay_balance' => $user->freemopay_wallet_balance ?? 0,
                'paypal_balance' => $user->paypal_wallet_balance ?? 0,
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
