<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Console\Command;

/**
 * Filet de sécurité des commandes payées par rail direct (Mobile Money / carte).
 *
 * Le stock est décrémenté dès la création de la commande, avant le paiement. Le
 * mobile confirme par polling tant que l'écran de paiement est ouvert ; si
 * l'acheteur le quitte, la commande reste « pending » et le stock demeure
 * immobilisé indéfiniment. Ce passage régulier re-vérifie chez le PSP, confirme
 * les paiements aboutis et, au-delà du délai de grâce, annule les tentatives
 * abandonnées en restituant le stock.
 *
 * Pendant du `packages:reconcile-pending` pour les forfaits.
 */
class ReconcilePendingOrders extends Command
{
    protected $signature = 'orders:reconcile-pending';

    protected $description = 'Confirme ou clôt les commandes restées en attente de paiement direct (KPay / Stripe)';

    /**
     * Au-delà, une tentative de paiement non aboutie est close et le stock rendu.
     * Court : le stock d'un paiement abandonné ne doit pas rester bloqué longtemps
     * (l'app annule déjà elle-même une fenêtre carte fermée sans payer).
     */
    private const STALE_AFTER_MINUTES = 30;

    public function handle(OrderService $orderService): int
    {
        $confirmed = 0;
        $cancelled = 0;

        Order::whereIn('payment_method', ['kpay_direct', 'stripe_direct'])
            ->where('payment_status', 'pending')
            ->where('status', '!=', 'cancelled')
            // On laisse au polling du mobile le temps de faire son travail.
            ->where('created_at', '<', now()->subMinutes(5))
            ->orderBy('id')
            ->each(function (Order $order) use ($orderService, &$confirmed, &$cancelled) {
                try {
                    if ($order->payment_method === 'stripe_direct') {
                        $orderService->syncStripeOrder($order);
                    } else {
                        $orderService->confirmKpayOrderPayment($order);
                    }
                } catch (\Throwable $e) {
                    $this->warn("Commande #{$order->id} : {$e->getMessage()}");
                }

                $order->refresh();

                if ($order->payment_status === 'paid') {
                    $confirmed++;
                    return;
                }

                if ($order->payment_status === 'pending'
                    && $order->created_at->lt(now()->subMinutes(self::STALE_AFTER_MINUTES))) {
                    $order->payment_method === 'stripe_direct'
                        ? $orderService->failStripeOrderPayment($order)
                        : $orderService->failKpayOrderPayment($order);

                    $order->refresh();
                }

                if ($order->payment_status === 'failed') {
                    $cancelled++;
                }
            });

        $this->info("Commandes confirmées : {$confirmed} — clôturées (stock rendu) : {$cancelled}");

        return self::SUCCESS;
    }
}
