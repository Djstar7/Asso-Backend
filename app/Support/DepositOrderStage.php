<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;

/**
 * Où en est une commande avec acompte, du paiement de l'acompte à la remise : sert
 * aux filtres, aux compteurs et aux badges de l'admin (Commandes avec acompte).
 */
class DepositOrderStage
{
    /** Étapes dans l'ordre du parcours : [libellé, classes du badge]. */
    public const STAGES = [
        'awaiting_deposit' => ['Acompte en attente', 'bg-gray-500/20 text-gray-300 border-gray-500/50'],
        'in_progress' => ['Commande en cours', 'bg-blue-500/20 text-blue-300 border-blue-500/50'],
        'to_contact' => ['À contacter', 'bg-yellow-500/20 text-yellow-300 border-yellow-500/50'],
        'contacted' => ['Client contacté', 'bg-amber-500/20 text-amber-300 border-amber-500/50'],
        'issue' => ['Problème signalé', 'bg-red-500/20 text-red-300 border-red-500/50'],
        'balance_due' => ['Solde débloqué', 'bg-indigo-500/20 text-indigo-300 border-indigo-500/50'],
        'balance_paid' => ['Solde payé · à remettre', 'bg-purple-500/20 text-purple-300 border-purple-500/50'],
        'delivered' => ['Remise au client', 'bg-green-500/20 text-green-300 border-green-500/50'],
        'cancelled' => ['Clôturée / annulée', 'bg-red-500/20 text-red-300 border-red-500/50'],
    ];

    public static function of(Order $order): string
    {
        return match (true) {
            $order->status === 'cancelled' => 'cancelled',
            $order->status === 'delivered' => 'delivered',
            $order->payment_status !== Order::PAYMENT_PAID => 'awaiting_deposit',
            $order->balance_status === Order::BALANCE_PAID => 'balance_paid',
            $order->balance_status === Order::BALANCE_UNLOCKED => 'balance_due',
            $order->verification_status === Order::VERIFICATION_ISSUE => 'issue',
            $order->verification_status === Order::VERIFICATION_CONTACTED => 'contacted',
            $order->verification_status === Order::VERIFICATION_TO_CONTACT => 'to_contact',
            default => 'in_progress',
        };
    }

    public static function label(string $stage): string
    {
        return self::STAGES[$stage][0] ?? $stage;
    }

    public static function badge(string $stage): string
    {
        return self::STAGES[$stage][1] ?? self::STAGES['awaiting_deposit'][1];
    }

    /** Commandes avec acompte, filtrées sur une étape. */
    public static function apply(Builder $query, string $stage): Builder
    {
        $query->where('payment_plan', Order::PLAN_DEPOSIT);
        $open = fn (Builder $q) => $q->whereNotIn('status', ['cancelled', 'delivered'])->where('payment_status', Order::PAYMENT_PAID);

        return match ($stage) {
            'awaiting_deposit' => $query->whereNotIn('status', ['cancelled', 'delivered'])->where('payment_status', '!=', Order::PAYMENT_PAID),
            'in_progress' => $open($query)->where('balance_status', Order::BALANCE_LOCKED)->where('verification_status', Order::VERIFICATION_PENDING),
            'to_contact', 'contacted', 'issue' => $open($query)->where('balance_status', Order::BALANCE_LOCKED)->where('verification_status', $stage),
            'balance_due' => $open($query)->where('balance_status', Order::BALANCE_UNLOCKED),
            'balance_paid' => $open($query)->where('balance_status', Order::BALANCE_PAID),
            'delivered', 'cancelled' => $query->where('status', $stage),
            default => $query,
        };
    }
}
