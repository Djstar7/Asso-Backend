<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;

/**
 * Où en est une commande en gros, du paiement à la livraison SOLEX : sert aux
 * filtres, aux compteurs et aux badges de l'admin (Commandes en gros).
 */
class WholesaleOrderStage
{
    /** Étapes dans l'ordre du parcours : [libellé, classes du badge]. */
    public const STAGES = [
        'awaiting_payment' => ['Paiement en attente', 'bg-gray-500/20 text-gray-300 border-gray-500/50'],
        'to_validate' => ['À valider', 'bg-yellow-500/20 text-yellow-300 border-yellow-500/50'],
        'to_ship' => ['À expédier', 'bg-blue-500/20 text-blue-300 border-blue-500/50'],
        'in_transit' => ['En route vers Douala', 'bg-indigo-500/20 text-indigo-300 border-indigo-500/50'],
        'last_mile' => ['À Douala · livraison SOLEX', 'bg-purple-500/20 text-purple-300 border-purple-500/50'],
        'delivered' => ['Livrée', 'bg-green-500/20 text-green-300 border-green-500/50'],
        'cancelled' => ['Annulée', 'bg-red-500/20 text-red-300 border-red-500/50'],
    ];

    /** Étapes de suivi atteintes une fois le colis à Douala. */
    private const AT_DOUALA = ['arrived_hub', 'arrived', 'ready_for_pickup', 'out_for_delivery'];

    public static function of(Order $order): string
    {
        return match (true) {
            $order->status === 'cancelled' => 'cancelled',
            $order->status === 'delivered' => 'delivered',
            $order->status === 'pending' => $order->payment_status === Order::PAYMENT_PAID ? 'to_validate' : 'awaiting_payment',
            in_array($order->status, ['confirmed', 'preparing'], true) => 'to_ship',
            in_array($order->tracking_status, self::AT_DOUALA, true) => 'last_mile',
            default => 'in_transit',
        };
    }

    public static function label(string $stage): string
    {
        return self::STAGES[$stage][0] ?? $stage;
    }

    public static function badge(string $stage): string
    {
        return self::STAGES[$stage][1] ?? self::STAGES['awaiting_payment'][1];
    }

    /** Filtre une requête de commandes sur une étape. */
    public static function apply(Builder $query, string $stage): Builder
    {
        return match ($stage) {
            'awaiting_payment' => $query->where('status', 'pending')->where('payment_status', '!=', Order::PAYMENT_PAID),
            'to_validate' => $query->where('status', 'pending')->where('payment_status', Order::PAYMENT_PAID),
            'to_ship' => $query->whereIn('status', ['confirmed', 'preparing']),
            'in_transit' => $query->where('status', 'shipped')
                ->where(fn ($q) => $q->whereNull('tracking_status')->orWhereNotIn('tracking_status', self::AT_DOUALA)),
            'last_mile' => $query->where('status', 'shipped')->whereIn('tracking_status', self::AT_DOUALA),
            'delivered', 'cancelled' => $query->where('status', $stage),
            default => $query,
        };
    }
}
