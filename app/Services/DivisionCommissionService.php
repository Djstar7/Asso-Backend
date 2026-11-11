<?php

namespace App\Services;

use App\Models\Dispute;
use App\Models\Division;
use App\Models\DivisionCommission;
use App\Models\DivisionHead;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Commission des chefs de rubrique.
 *
 * Règles validées par le propriétaire :
 * - base = commission ASSO de la ligne (prix acheteur − part vendeur), jamais le
 *   chiffre d'affaires : ASSO ne peut pas verser plus qu'elle ne gagne ;
 * - taux et chef figés à la date de la vente (chef en poste ce jour-là) ;
 * - commission acquise quand la vente l'est (part vendeur débloquée, aucun litige
 *   ouvert sur la ligne), versée alors sur le Wallet du chef ;
 * - vente remboursée avant : annulée ; après : reprise (débit du Wallet, ou
 *   retenue sur les commissions suivantes si le solde ne suffit pas) ;
 * - pas de rétroactivité : une vente antérieure à la prise de poste n'a pas de chef.
 *
 * Tout est idempotent (une ligne par source, verrou sur les lignes) et rejoué par
 * `division:sync-commissions` : un échec ici ne bloque jamais le règlement d'une
 * commande, il est rattrapé au passage suivant.
 */
class DivisionCommissionService
{
    public function __construct(private WalletService $wallet) {}

    /**
     * À appeler depuis un flux financier : enregistre puis met à jour les
     * commissions de la commande une fois la transaction en cours validée, sans
     * jamais propager d'erreur au flux appelant.
     */
    public static function syncOrderAfterCommit(Order|int $order): void
    {
        $orderId = $order instanceof Order ? $order->id : $order;
        if (! self::hasHeads()) {
            return;
        }

        DB::afterCommit(function () use ($orderId) {
            try {
                $service = app(self::class);
                $service->recordForOrder($orderId);
                $service->reconcileOrder($orderId);
            } catch (\Throwable $e) {
                Log::error('[DivisionCommission] Synchronisation de la commande impossible', [
                    'order_id' => $orderId,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        });
    }

    /** Au moins un chef de rubrique nommé (sinon rien à calculer). */
    private static function hasHeads(): bool
    {
        try {
            return DivisionHead::query()->exists();
        } catch (\Throwable) {
            return false; // table absente (migration pas encore jouée)
        }
    }

    /** Taux appliqué : pourcentage de la commission ASSO. */
    public static function compute(float $base, float $rate): float
    {
        return round(max(0, $base) * max(0, $rate) / 100, 2);
    }

    /** Commission ASSO d'une ligne : prix acheteur − part vendeur figés à la commande. */
    public static function itemBase(OrderItem $item): float
    {
        $seller = $item->seller_total_price ?? $item->total_price;

        return round(max(0, (float) $item->total_price - (float) $seller), 2);
    }

    /** Rubrique active de chaque catégorie : [category_id => division_id]. */
    public function divisionsByCategory(): array
    {
        return DB::table('division_category')
            ->join('divisions', 'divisions.id', '=', 'division_category.division_id')
            ->where('divisions.is_active', true)
            ->where('divisions.type', Division::TYPE_CATEGORY)
            ->pluck('division_category.division_id', 'division_category.category_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Crée les commissions des lignes d'une commande réglée (idempotent).
     *
     * @return int nombre de commissions créées
     */
    public function recordForOrder(Order|int $order): int
    {
        $order = Order::withoutGlobalScopes()->with('items')->find($order instanceof Order ? $order->id : $order);
        if (! $order || ! $order->settled_at || $this->orderIsVoid($order)) {
            return 0;
        }

        $divisions = $this->divisionsByCategory();
        $soldAt = Carbon::parse($order->created_at);
        $created = 0;

        foreach ($order->items as $item) {
            $divisionId = $divisions[(int) $item->category_id] ?? null;
            if (! $divisionId) {
                continue;
            }
            $head = DivisionHead::where('division_id', $divisionId)->at($soldAt)->latest('started_at')->first();
            if (! $head) {
                continue;
            }
            $base = self::itemBase($item);
            $amount = self::compute($base, (float) $head->commission_rate);
            if ($amount <= 0) {
                continue;
            }

            $commission = DivisionCommission::firstOrCreate(
                ['source_type' => DivisionCommission::SOURCE_ORDER_ITEM, 'source_id' => $item->id],
                [
                    'division_id' => $divisionId,
                    'division_head_id' => $head->id,
                    'user_id' => $head->user_id,
                    'order_id' => $order->id,
                    'reference' => $order->order_number,
                    'sold_at' => $soldAt,
                    'sale_amount' => (float) $item->total_price,
                    'base_amount' => $base,
                    'rate' => (float) $head->commission_rate,
                    'amount' => $amount,
                    'currency' => 'XAF',
                    'status' => DivisionCommission::STATUS_PENDING,
                ]
            );
            $created += $commission->wasRecentlyCreated ? 1 : 0;
        }

        return $created;
    }

    /**
     * Met les commissions de la commande dans l'état que dicte la commande :
     * versée si la vente est acquise, annulée ou reprise si elle est remboursée.
     */
    public function reconcileOrder(Order|int $order): void
    {
        $orderId = $order instanceof Order ? $order->id : $order;

        DB::transaction(function () use ($orderId) {
            $rows = DivisionCommission::where('source_type', DivisionCommission::SOURCE_ORDER_ITEM)
                ->where('order_id', $orderId)
                ->whereIn('status', [DivisionCommission::STATUS_PENDING, DivisionCommission::STATUS_AVAILABLE])
                ->lockForUpdate()
                ->get();
            if ($rows->isEmpty()) {
                return;
            }

            $order = Order::withoutGlobalScopes()->find($orderId);
            $items = OrderItem::whereIn('id', $rows->pluck('source_id'))->get()->keyBy('id');
            $disputes = Dispute::withoutGlobalScopes()->where('order_id', $orderId)->get()->groupBy('order_item_id');

            foreach ($rows as $row) {
                $item = $items->get($row->source_id);
                $itemDisputes = $disputes->get($row->source_id, collect());

                // Part de la ligne conservée par le client après remboursements.
                $refunded = (float) $itemDisputes->whereNotNull('refunded_at')->sum('refund_amount');
                $total = (float) ($item?->total_price ?? 0);
                $kept = ! $order || ! $item || $this->orderIsVoid($order) || $total <= 0
                    ? 0.0
                    : max(0.0, min(1.0, ($total - $refunded) / $total));
                $target = $kept <= 0 ? 0.0 : self::compute((float) $row->base_amount * $kept, (float) $row->rate);

                if ($row->status === DivisionCommission::STATUS_AVAILABLE) {
                    if ($target + 0.009 < (float) $row->amount) {
                        $this->clawBack($row, round((float) $row->amount - $target, 2));
                    }

                    continue;
                }

                if ($target <= 0) {
                    $row->update([
                        'status' => DivisionCommission::STATUS_CANCELLED,
                        'amount' => 0,
                        'cancelled_at' => now(),
                        'cancel_reason' => 'Vente remboursée ou annulée',
                    ]);

                    continue;
                }

                $hasOpenDispute = $itemDisputes->contains(fn (Dispute $d) => $d->isOpen());
                if (! $hasOpenDispute && $this->saleIsAcquired($order)) {
                    $this->makeAvailable($row, $target);
                } elseif ($target + 0.009 < (float) $row->amount) {
                    $row->update(['amount' => $target]);
                }
            }
        });
    }

    /**
     * Réservation immobilière (rubrique de type real_estate) : base = commission
     * ASSO de la réservation (platform_amount), chef en poste au paiement ; versée
     * au déblocage de la part du bailleur, annulée si la réservation est remboursée.
     */
    public static function syncReservationAfterCommit(int $reservationId): void
    {
        if (! self::hasHeads()) {
            return;
        }

        DB::afterCommit(function () use ($reservationId) {
            try {
                app(self::class)->syncReservation($reservationId);
            } catch (\Throwable $e) {
                Log::error('[DivisionCommission] Synchronisation de la réservation impossible', [
                    'reservation_id' => $reservationId,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        });
    }

    public function syncReservation(int $reservationId): void
    {
        $reservation = \App\Models\RealEstate\Reservation::find($reservationId);
        if (! $reservation || ! $reservation->paid_at) {
            return;
        }

        $division = Division::active()->where('type', Division::TYPE_REAL_ESTATE)->orderBy('id')->first();
        $paidAt = Carbon::parse($reservation->paid_at);
        $head = $division ? DivisionHead::where('division_id', $division->id)->at($paidAt)->latest('started_at')->first() : null;
        $base = round((float) $reservation->platform_amount, 2);

        DB::transaction(function () use ($reservation, $division, $head, $base, $paidAt) {
            $row = DivisionCommission::where('source_type', DivisionCommission::SOURCE_REAL_ESTATE)
                ->where('source_id', $reservation->id)->lockForUpdate()->first();

            if (! $row) {
                $amount = $head ? self::compute($base, (float) $head->commission_rate) : 0;
                if (! $head || $amount <= 0 || ! in_array($reservation->status, \App\Models\RealEstate\Reservation::PAID_STATUSES, true)) {
                    return;
                }
                $row = DivisionCommission::create([
                    'division_id' => $division->id,
                    'division_head_id' => $head->id,
                    'user_id' => $head->user_id,
                    'source_type' => DivisionCommission::SOURCE_REAL_ESTATE,
                    'source_id' => $reservation->id,
                    'reference' => $reservation->reference,
                    'sold_at' => $paidAt,
                    'sale_amount' => (float) $reservation->total_amount,
                    'base_amount' => $base,
                    'rate' => (float) $head->commission_rate,
                    'amount' => $amount,
                    'currency' => 'XAF',
                    'status' => DivisionCommission::STATUS_PENDING,
                ]);
            }

            $refunded = $reservation->status === 'refunded';
            if ($row->status === DivisionCommission::STATUS_PENDING) {
                if ($refunded) {
                    $row->update(['status' => DivisionCommission::STATUS_CANCELLED, 'amount' => 0,
                        'cancelled_at' => now(), 'cancel_reason' => 'Réservation remboursée']);
                } elseif ($reservation->status === 'released') {
                    $this->makeAvailable($row, (float) $row->amount);
                }
            } elseif ($row->status === DivisionCommission::STATUS_AVAILABLE && $refunded) {
                $this->clawBack($row, (float) $row->amount);
            }
        });
    }

    /** Commandes à rejouer : à créer (réglées depuis la première prise de poste) ou à mettre à jour. */
    public function sync(?Carbon $since = null): array
    {
        $firstStart = DivisionHead::min('started_at');
        $recorded = 0;
        $reconciled = 0;

        if ($firstStart) {
            $from = max(Carbon::parse($firstStart), $since ?? Carbon::parse($firstStart));
            Order::withoutGlobalScopes()
                ->whereNotNull('settled_at')
                ->where('created_at', '>=', $from)
                ->whereDoesntHave('items', fn ($q) => $q->whereIn('id', DivisionCommission::where('source_type', DivisionCommission::SOURCE_ORDER_ITEM)->select('source_id')))
                ->select('id')
                ->chunkById(200, function (Collection $orders) use (&$recorded) {
                    foreach ($orders as $order) {
                        $recorded += $this->recordForOrder($order->id);
                    }
                });
        }

        // Ventes en attente, et ventes versées récemment (remboursement tardif possible).
        DivisionCommission::where('source_type', DivisionCommission::SOURCE_ORDER_ITEM)
            ->where(fn ($q) => $q->where('status', DivisionCommission::STATUS_PENDING)
                ->orWhere(fn ($w) => $w->where('status', DivisionCommission::STATUS_AVAILABLE)->where('available_at', '>=', now()->subDays(90))))
            ->distinct()
            ->pluck('order_id')
            ->filter()
            ->each(function ($orderId) use (&$reconciled) {
                $this->reconcileOrder((int) $orderId);
                $reconciled++;
            });

        // Immobilier : réservations payées depuis la première prise de poste.
        if ($firstStart && Division::active()->where('type', Division::TYPE_REAL_ESTATE)->exists()) {
            \App\Models\RealEstate\Reservation::whereNotNull('paid_at')
                ->where('paid_at', '>=', max(Carbon::parse($firstStart), $since ?? Carbon::parse($firstStart)))
                ->pluck('id')
                ->each(function ($id) use (&$reconciled) {
                    $this->syncReservation((int) $id);
                    $reconciled++;
                });
        }

        return ['recorded' => $recorded, 'reconciled' => $reconciled];
    }

    /** Totaux d'un chef : en attente, versé, repris restant à retenir. */
    public function summaryFor(User $user, ?int $divisionId = null): array
    {
        $base = DivisionCommission::where('user_id', $user->id)
            ->when($divisionId, fn ($q) => $q->where('division_id', $divisionId));

        return [
            'pending' => round((float) (clone $base)->where('status', DivisionCommission::STATUS_PENDING)->sum('amount'), 2),
            'available' => round((float) (clone $base)->where('status', DivisionCommission::STATUS_AVAILABLE)->sum('amount'), 2),
            'clawback_due' => round((float) DivisionCommission::where('user_id', $user->id)->sum('clawback_due'), 2),
            'count' => (clone $base)->whereIn('status', [DivisionCommission::STATUS_PENDING, DivisionCommission::STATUS_AVAILABLE])->count(),
        ];
    }

    private function orderIsVoid(Order $order): bool
    {
        return $order->status === 'cancelled'
            || $order->refunded_at !== null
            || $order->payment_status === Order::PAYMENT_REFUNDED;
    }

    /** La vente est-elle définitivement acquise (fin de la fenêtre de contrôle du client) ? */
    private function saleIsAcquired(?Order $order): bool
    {
        if (! $order || ! $order->settled_at || $this->orderIsVoid($order)) {
            return false;
        }
        if ($order->vendor_funds_status === Order::VENDOR_FUNDS_RELEASED) {
            return true;
        }

        // Aucune part vendeur bloquée (montant nul) : acquise 48 h après la livraison.
        return $order->vendor_funds_status === null
            && $order->status === 'delivered'
            && $order->delivered_at
            && Carbon::parse($order->delivered_at)->addHours(Order::CONTROL_WINDOW_HOURS)->isPast();
    }

    /** Verse la commission sur le Wallet du chef, diminuée des reprises encore dues. */
    private function makeAvailable(DivisionCommission $row, float $amount): void
    {
        $net = $amount;
        $debts = DivisionCommission::where('user_id', $row->user_id)
            ->where('clawback_due', '>', 0)
            ->whereKeyNot($row->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        foreach ($debts as $debt) {
            if ($net <= 0) {
                break;
            }
            $take = min($net, (float) $debt->clawback_due);
            $debt->update(['clawback_due' => round((float) $debt->clawback_due - $take, 2)]);
            $net = round($net - $take, 2);
        }

        $transaction = null;
        if ($net > 0 && ($user = User::find($row->user_id))) {
            $transaction = $this->wallet->credit(
                $user,
                $net,
                null,
                WalletTransaction::label('division_commission', [
                    'division' => $row->division?->name ?? '',
                    'order_number' => $row->reference,
                ]),
                [
                    'kind' => 'division_commission',
                    'division_commission_id' => $row->id,
                    'division_id' => $row->division_id,
                    'order_id' => $row->order_id,
                    'rate' => (float) $row->rate,
                    'offset_clawback' => round($amount - $net, 2),
                ],
                'kpay'
            );
        }

        $row->update([
            'status' => DivisionCommission::STATUS_AVAILABLE,
            'amount' => $amount,
            'available_at' => now(),
            'wallet_transaction_id' => $transaction?->id,
        ]);
    }

    /** Reprend une commission déjà versée (remboursement tardif de la vente). */
    private function clawBack(DivisionCommission $row, float $diff): void
    {
        $user = User::find($row->user_id);
        $remaining = round((float) $row->amount - $diff, 2);
        $due = $diff;

        if ($user) {
            $available = $user->kpayAvailableFor('XAF');
            $take = round(min($diff, max(0, $available)), 2);
            if ($take > 0) {
                $this->wallet->debit(
                    $user,
                    $take,
                    WalletTransaction::label('division_commission_clawback', [
                        'division' => $row->division?->name ?? '',
                        'order_number' => $row->reference,
                    ]),
                    'division_commission',
                    $row->id,
                    ['kind' => 'division_commission_clawback', 'order_id' => $row->order_id],
                    'kpay'
                );
                $due = round($diff - $take, 2);
            }
        }

        $row->update([
            'status' => $remaining <= 0 ? DivisionCommission::STATUS_CLAWED_BACK : DivisionCommission::STATUS_AVAILABLE,
            'amount' => max(0, $remaining),
            'clawback_due' => round((float) $row->clawback_due + $due, 2),
            'cancel_reason' => 'Vente remboursée après versement',
        ]);
    }
}
