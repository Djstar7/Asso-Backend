<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;

/**
 * Où en est une commande locale (au moins un produit fabriqué au Cameroun) : sert aux
 * filtres, aux compteurs et aux badges du menu « Commandes locales ». Le vendeur gère
 * sa commande comme une commande classique ; ASSO suit.
 */
class LocalOrderStage
{
    /** Étapes dans l'ordre du parcours : [libellé, classes du badge]. */
    public const STAGES = [
        'awaiting_payment' => ['Paiement en attente', 'bg-gray-500/20 text-gray-300 border-gray-500/50'],
        'to_confirm' => ['À confirmer par le vendeur', 'bg-yellow-500/20 text-yellow-300 border-yellow-500/50'],
        'preparing' => ['En préparation', 'bg-blue-500/20 text-blue-300 border-blue-500/50'],
        'shipped' => ['En livraison', 'bg-purple-500/20 text-purple-300 border-purple-500/50'],
        'delivered' => ['Livrée', 'bg-green-500/20 text-green-300 border-green-500/50'],
        'cancelled' => ['Annulée', 'bg-red-500/20 text-red-300 border-red-500/50'],
    ];

    public static function of(Order $order): string
    {
        return match (true) {
            $order->status === 'cancelled' => 'cancelled',
            $order->status === 'delivered' => 'delivered',
            $order->status === 'shipped' => 'shipped',
            $order->status === 'pending' => $order->payment_status === Order::PAYMENT_PAID ? 'to_confirm' : 'awaiting_payment',
            default => 'preparing',
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
            'to_confirm' => $query->where('status', 'pending')->where('payment_status', Order::PAYMENT_PAID),
            'preparing' => $query->whereIn('status', ['confirmed', 'preparing']),
            'shipped', 'delivered', 'cancelled' => $query->where('status', $stage),
            default => $query,
        };
    }
}
