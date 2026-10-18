<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WalletBalance;
use App\Models\WalletTransaction;
use App\Support\Translation\LocalizedText;
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
        DB::table('wallet_balances')->insertOrIgnore([
            'user_id' => $user->id,
            'currency' => self::KPAY_CURRENCY,
            'balance' => 0,
            'locked_balance' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return WalletBalance::where('user_id', $user->id)
            ->where('currency', self::KPAY_CURRENCY)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Recharge le wallet d'un utilisateur.
     *
     * @param  Transaction|null  $transaction  Transaction source (KPay, etc.)
     * @param  string  $provider  Provider (kpay)
     */
    public function credit(
        User $user,
        float $amount,
        ?Transaction $transaction = null,
        string|LocalizedText $description = 'Recharge wallet',
        array $metadata = [],
        string $provider = 'kpay'
    ): WalletTransaction {
        return DB::transaction(function () use ($user, $amount, $transaction, $description, $metadata, $provider) {
            // Solde KPay → wallet_balances (source de vérité, verrou de ligne).
            $row = $this->lockedKpayBalance($user);
            $balanceBefore = (float) $row->balance;
            $balanceAfter = $balanceBefore + $amount;
            $row->balance = $balanceAfter;
            $row->save();

            // Créer la transaction
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

            Log::info('[WalletService] Wallet credited', [
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
     * Débite le wallet d'un utilisateur.
     *
     * @param  string|null  $referenceType  Type de référence (order, subscription, etc.)
     * @param  int|null  $referenceId  ID de la référence
     * @param  string  $provider  Provider (kpay)
     *
     * @throws \Exception Si solde insuffisant
     */
    public function debit(
        User $user,
        float $amount,
        string|LocalizedText $description,
        ?string $referenceType = null,
        ?int $referenceId = null,
        array $metadata = [],
        string $provider = 'kpay',
        string $status = 'completed'
    ): WalletTransaction {
        return DB::transaction(function () use ($user, $amount, $description, $referenceType, $referenceId, $metadata, $provider, $status) {
            // Solde KPay → wallet_balances (source de vérité, verrou de ligne).
            $row = $this->lockedKpayBalance($user);
            $balanceBefore = (float) $row->balance;
            $locked = (float) $row->locked_balance;
            $available = $balanceBefore - $locked;

            // Vérifier le solde disponible (non bloqué)
            if ($available < $amount) {
                throw new \Exception(__('wallet.insufficient_available_balance', ['available' => $available, 'amount' => $amount]));
            }

            $balanceAfter = $balanceBefore - $amount;
            $row->balance = $balanceAfter;
            $row->save();

            // Créer la transaction
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

            Log::info('[WalletService] Wallet debited', [
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
     * Atomically moves XAF between two real WalletBalance rows and records both
     * ledger entries. The caller must wrap related business-state changes in the
     * same database transaction.
     *
     * @return array{debit: WalletTransaction, credit: WalletTransaction}
     */
    public function transferBetweenUsers(
        User $from,
        User $to,
        float $amount,
        string $referenceType,
        int $referenceId,
        string $operationReference,
        string $reason,
        int $adminId
    ): array {
        if ($from->is($to) || $amount <= 0 || ! is_finite($amount)) {
            throw new \InvalidArgumentException('A positive transfer between different wallet owners is required.');
        }

        $amount = round($amount, 2);

        return DB::transaction(function () use ($from, $to, $amount, $referenceType, $referenceId, $operationReference, $reason, $adminId) {
            $existing = WalletTransaction::where('reference_type', $referenceType)
                ->where('reference_id', $referenceId)
                ->get()
                ->filter(fn (WalletTransaction $transaction) => ($transaction->metadata['transfer_operation_reference'] ?? null) === $operationReference)
                ->keyBy(fn (WalletTransaction $transaction) => $transaction->metadata['transfer_direction'] ?? '');

            if ($existing->has('debit') && $existing->has('credit')) {
                return ['debit' => $existing->get('debit'), 'credit' => $existing->get('credit')];
            }
            if ($existing->isNotEmpty()) {
                throw new \RuntimeException('The wallet transfer ledger is incomplete.');
            }

            $users = [$from->id => $from, $to->id => $to];
            ksort($users);
            $balances = [];
            foreach ($users as $user) {
                $balances[$user->id] = $this->lockedKpayBalance($user);
            }

            $source = $balances[$from->id];
            $destination = $balances[$to->id];
            $sourceBefore = (float) $source->balance;
            $sourceAvailable = $sourceBefore - (float) $source->locked_balance;
            if ($sourceAvailable < $amount) {
                throw new \RuntimeException(__('wallet.insufficient_available_balance', [
                    'available' => $sourceAvailable,
                    'amount' => $amount,
                ]));
            }

            $destinationBefore = (float) $destination->balance;
            $sourceAfter = round($sourceBefore - $amount, 2);
            $destinationAfter = round($destinationBefore + $amount, 2);
            $source->balance = $sourceAfter;
            $destination->balance = $destinationAfter;
            $source->save();
            $destination->save();

            $metadata = [
                'transfer_operation_reference' => $operationReference,
                'reason' => $reason,
                'admin_id' => $adminId,
                'occurred_at' => now()->toIso8601String(),
            ];
            $debit = WalletTransaction::create([
                'user_id' => $from->id,
                'type' => 'debit',
                'amount' => $amount,
                'balance_before' => $sourceBefore,
                'balance_after' => $sourceAfter,
                'description' => "Indemnisation maritime — {$operationReference}",
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'metadata' => $metadata + ['transfer_direction' => 'debit'],
                'status' => 'completed',
                'admin_id' => $adminId,
                'provider' => 'kpay',
            ]);
            $credit = WalletTransaction::create([
                'user_id' => $to->id,
                'type' => 'credit',
                'amount' => $amount,
                'balance_before' => $destinationBefore,
                'balance_after' => $destinationAfter,
                'description' => "Indemnisation maritime — {$operationReference}",
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'metadata' => $metadata + ['transfer_direction' => 'credit'],
                'status' => 'completed',
                'admin_id' => $adminId,
                'provider' => 'kpay',
            ]);

            return ['debit' => $debit, 'credit' => $credit];
        });
    }

    /**
     * Rembourse une transaction (remet l'argent dans le wallet).
     *
     * @param  WalletTransaction  $originalTransaction  Transaction à rembourser
     * @param  string|null  $reason  Raison du remboursement
     */
    public function refund(WalletTransaction $originalTransaction, ?string $reason = null): WalletTransaction
    {
        // On ne peut rembourser qu'un débit
        if ($originalTransaction->type !== 'debit') {
            throw new \Exception(__('wallet.refund_debit_only'));
        }

        if ($originalTransaction->status !== 'completed') {
            throw new \Exception(__('wallet.refund_completed_only'));
        }

        $user = $originalTransaction->user;
        $amount = abs($originalTransaction->amount);
        $description = 'Remboursement: '.($reason ?? $originalTransaction->description);

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
     * Ajoute un bonus au wallet (promotion, parrainage, etc.).
     */
    public function addBonus(
        User $user,
        float $amount,
        string|LocalizedText $description = 'Bonus',
        array $metadata = [],
        string $provider = 'kpay'
    ): WalletTransaction {
        return DB::transaction(function () use ($user, $amount, $description, $metadata, $provider) {
            $row = $this->lockedKpayBalance($user);
            $balanceBefore = (float) $row->balance;
            $balanceAfter = $balanceBefore + $amount;
            $row->balance = $balanceAfter;
            $row->save();

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

            Log::info('[WalletService] Bonus added', [
                'user_id' => $user->id,
                'amount' => $amount,
                'balance_after' => $balanceAfter,
                'provider' => $provider,
            ]);

            return $walletTransaction;
        });
    }

    /**
     * Ajustement manuel par un admin.
     *
     * @param  float  $amount  Positif pour ajouter, négatif pour retirer
     * @param  User  $admin  Admin effectuant l'ajustement
     * @param  string  $reason  Raison de l'ajustement
     */
    public function adjustBalance(
        User $user,
        float $amount,
        User $admin,
        string $reason,
        string $provider = 'kpay'
    ): WalletTransaction {
        return DB::transaction(function () use ($user, $amount, $admin, $reason, $provider) {
            $row = $this->lockedKpayBalance($user);
            $balanceBefore = (float) $row->balance;
            $balanceAfter = $balanceBefore + $amount;

            // Ne pas permettre de balance négative
            if ($balanceAfter < 0) {
                throw new \Exception(__('wallet.adjustment_negative'));
            }

            $row->balance = $balanceAfter;
            $row->save();

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

            Log::warning('[WalletService] Balance adjusted by admin', [
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
     * Récupère l'historique des transactions avec pagination.
     *
     * @param  string|null  $type  Filtrer par type
     * @param  string|null  $provider  Filtrer par provider
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
     * Statistiques du wallet pour un utilisateur.
     */
    public function getWalletStats(User $user): array
    {
        $transactions = $user->walletTransactions()->completed();

        // Soldes KPay multi-devise (source de vérité : wallet_balances)
        $kpayBalances = $user->walletBalances()->get()->map(fn ($wb) => [
            'currency' => $wb->currency,
            'balance' => max(0, (float) $wb->balance),
            'locked' => max(0, (float) $wb->locked_balance),
            'available' => max(0, (float) $wb->balance - (float) $wb->locked_balance),
        ])->values();

        // Solde KPay principal = devise de base (XAF)
        $kpayBalance = $user->kpayBalanceFor('XAF');
        $lockedKPay = (float) ($user->walletBalances()->where('currency', 'XAF')->value('locked_balance') ?? 0);

        $totalBalance = $kpayBalance;

        // Stats par provider (historique des transactions)
        $kpayCredits = $transactions->clone()->where('provider', 'kpay')->credits()->sum('amount');
        $kpayDebits = abs($transactions->clone()->where('provider', 'kpay')->debits()->sum('amount'));

        $totalCredits = $kpayCredits;
        $totalDebits = $kpayDebits;

        // Solde bloqué ($lockedKPay déjà calculé depuis wallet_balances plus haut)
        $totalLocked = $lockedKPay;
        $availableTotal = $totalBalance - $totalLocked;

        return [
            // Soldes par provider
            'kpay_wallet_balance' => $kpayBalance,
            'kpay_balances' => $kpayBalances, // multi-devise (par devise)
            'current_balance' => $totalBalance,
            'formatted_balance' => number_format($totalBalance, 0, ',', ' ').' FCFA',

            // Soldes bloqués (escrow)
            'locked_kpay_balance' => $lockedKPay,
            'total_locked_balance' => $totalLocked,
            'available_balance' => $availableTotal,
            'formatted_available_balance' => number_format($availableTotal, 0, ',', ' ').' FCFA',

            // Totaux
            'total_credits' => $totalCredits,
            'total_debits' => $totalDebits,
            'total_transactions' => $transactions->count(),
            'last_transaction' => $transactions->first(),

            // Par provider
            'kpay_credits' => $kpayCredits,
            'kpay_debits' => $kpayDebits,
        ];
    }

    // ================================
    // ESCROW (Solde bloqué)
    // ================================

    /**
     * Bloque un montant sur le wallet (escrow).
     * Le montant reste dans le wallet mais n'est plus disponible.
     */
    public function lockFunds(
        User $user,
        float $amount,
        string|LocalizedText $description,
        ?string $referenceType = null,
        ?int $referenceId = null,
        array $metadata = [],
        string $provider = 'kpay'
    ): WalletTransaction {
        return DB::transaction(function () use ($user, $amount, $description, $referenceType, $referenceId, $metadata, $provider) {
            // Solde KPay → wallet_balances (verrou de ligne).
            $row = $this->lockedKpayBalance($user);
            $balance = (float) $row->balance;
            $lockedBefore = (float) $row->locked_balance;

            $available = $balance - $lockedBefore;

            if ($available < $amount) {
                throw new \Exception(__('wallet.insufficient_available_balance_to_lock', ['available' => $available, 'amount' => $amount]));
            }

            $lockedAfter = $lockedBefore + $amount;
            $row->locked_balance = $lockedAfter;
            $row->save();

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

            Log::info('[WalletService] Funds locked (escrow)', [
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
     * Débloque un montant (annulation de l'escrow, remise à disposition).
     */
    public function unlockFunds(
        User $user,
        float $amount,
        string|LocalizedText $description,
        ?string $referenceType = null,
        ?int $referenceId = null,
        array $metadata = [],
        string $provider = 'kpay'
    ): WalletTransaction {
        return DB::transaction(function () use ($user, $amount, $description, $referenceType, $referenceId, $metadata, $provider) {
            // Solde KPay → wallet_balances (verrou de ligne).
            $row = $this->lockedKpayBalance($user);
            $balance = (float) $row->balance;
            $lockedBefore = (float) $row->locked_balance;

            if ($lockedBefore < $amount) {
                throw new \Exception(__('wallet.unlock_insufficient', ['amount' => $amount, 'locked' => $lockedBefore]));
            }

            $lockedAfter = $lockedBefore - $amount;
            $row->locked_balance = $lockedAfter;
            $row->save();

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

            Log::info('[WalletService] Funds unlocked', [
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
        string|LocalizedText $description,
        ?string $referenceType = null,
        ?int $referenceId = null,
        array $metadata = [],
        string $provider = 'kpay'
    ): WalletTransaction {
        return DB::transaction(function () use ($user, $amount, $description, $referenceType, $referenceId, $metadata, $provider) {
            // Solde KPay → wallet_balances (verrou de ligne).
            $row = $this->lockedKpayBalance($user);
            $balanceBefore = (float) $row->balance;
            $lockedBefore = (float) $row->locked_balance;

            if ($lockedBefore < $amount) {
                throw new \Exception(__('wallet.release_insufficient', ['locked' => $lockedBefore, 'amount' => $amount]));
            }

            // Débloquer + débiter en même temps
            $lockedAfter = $lockedBefore - $amount;
            $balanceAfter = $balanceBefore - $amount;
            $row->locked_balance = $lockedAfter;
            $row->balance = $balanceAfter;
            $row->save();

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

            Log::info('[WalletService] Escrow released', [
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
     * Vérifie si un user peut effectuer un paiement avec son wallet KPay.
     *
     * @param  string|null  $provider  Provider spécifique (kpay)
     * @return array ['can_pay' => bool, 'message' => string, 'missing_amount' => float]
     */
    public function canPayWithWallet(User $user, float $amount, ?string $provider = null): array
    {
        // Solde KPay (source de vérité : wallet_balances, devise de base XAF).
        $totalBalance = $user->kpayBalanceFor(self::KPAY_CURRENCY);
        $available = $user->kpayAvailableFor(self::KPAY_CURRENCY);
        $locked = $totalBalance - $available;

        $canPay = $available >= $amount;

        return [
            'can_pay' => $canPay,
            'kpay_wallet_balance' => $totalBalance,
            'total_balance' => $totalBalance,
            'locked_balance' => $locked,
            'available_balance' => $available,
            'required_amount' => $amount,
            'missing_amount' => $canPay ? 0 : ($amount - $available),
            'provider' => $provider ?? 'kpay',
            'message' => $canPay
                ? __('wallet.can_pay')
                : __('wallet.insufficient_balance_missing', ['missing' => number_format($amount - $available, 0, ',', ' ')]),
        ];
    }
}
