<?php

namespace App\Console\Commands;

use App\Models\PlatformWithdrawal;
use App\Services\StripePayoutSettlementService;
use App\Services\StripeService;
use Illuminate\Console\Command;

/**
 * Clôture les virements IBAN restés en attente, en interrogeant Stripe.
 *
 * Le chemin nominal est le webhook `payout.paid` / `payout.failed`. Cette commande
 * est le filet de sécurité : elle rattrape les retraits bloqués en « en cours »
 * quand aucun endpoint public n'est déclaré (développement, tunnel expiré) ou quand
 * un événement s'est perdu. Elle applique exactement les mêmes règles que le webhook
 * (même service), y compris le remboursement en cas d'échec.
 *
 *   php artisan stripe:reconcile-payouts
 *   php artisan stripe:reconcile-payouts --hours=48   (fenêtre élargie)
 *
 * Planifiable (ex. toutes les 15 minutes) pour ne jamais laisser un vendeur devant
 * un virement « en cours » alors que l'argent est arrivé.
 */
class StripeReconcilePayouts extends Command
{
    protected $signature = 'stripe:reconcile-payouts
        {--hours=168 : Ne traiter que les retraits des N dernières heures}
        {--withdrawal= : Ne traiter que ce retrait (id)}';

    protected $description = 'Clôture les virements IBAN en attente selon leur statut réel chez Stripe';

    public function handle(StripeService $stripe, StripePayoutSettlementService $settlement): int
    {
        if (!$stripe->isConfigured()) {
            $this->error('❌ Clés API Stripe manquantes.');
            return self::FAILURE;
        }

        $query = PlatformWithdrawal::where('provider', 'stripe')
            ->whereIn('status', ['pending', 'processing'])
            ->whereNotNull('stripe_payout_id');

        if ($this->option('withdrawal')) {
            $query->where('id', (int) $this->option('withdrawal'));
        } else {
            $query->where('created_at', '>=', now()->subHours((int) $this->option('hours')));
        }

        $withdrawals = $query->with('user')->get();

        if ($withdrawals->isEmpty()) {
            $this->info('✅ Aucun virement en attente.');
            return self::SUCCESS;
        }

        $this->line($withdrawals->count() . ' virement(s) à vérifier…');

        $rows = [];
        $settled = 0;

        foreach ($withdrawals as $withdrawal) {
            $accountId = $withdrawal->user->stripe_account_id ?? null;
            if (!$accountId) {
                $rows[] = [$withdrawal->id, '—', $withdrawal->status, 'compte vendeur introuvable'];
                continue;
            }

            $payout = $stripe->retrievePayout($accountId, $withdrawal->stripe_payout_id);
            if (!$payout) {
                $rows[] = [$withdrawal->id, '—', $withdrawal->status, 'payout introuvable chez Stripe'];
                continue;
            }

            $action = match ($payout->status) {
                'paid' => $settlement->settlePaid($payout, 'StripeReconcile') ? 'clôturé (completed)' : 'déjà à jour',
                'failed', 'canceled' => $settlement->settleFailed($payout, 'StripeReconcile')
                    ? 'échec + remboursement'
                    : 'déjà à jour',
                default => 'en transit, rien à faire',
            };

            if (str_starts_with($action, 'clôturé') || str_starts_with($action, 'échec')) {
                $settled++;
            }

            $rows[] = [$withdrawal->id, $payout->status, $withdrawal->fresh()->status, $action];
        }

        $this->table(['retrait', 'statut Stripe', 'statut ASSO', 'action'], $rows);
        $this->info("✅ {$settled} virement(s) clôturé(s).");

        return self::SUCCESS;
    }
}
