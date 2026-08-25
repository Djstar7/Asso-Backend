<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\StripeService;
use Illuminate\Console\Command;

/**
 * Diagnostic complet de la chaîne « virement IBAN » (Stripe Connect).
 *
 * Rejoue, en lecture seule, les vérifications qui déterminent si un retrait vendeur
 * peut aboutir — chacune correspond à une panne réellement observée :
 *   1. clés API présentes et valides ;
 *   2. compte plateforme actif ;
 *   3. solde plateforme DANS la devise des virements (un compte CAD ne peut pas
 *      transférer en EUR, même avec un solde positif) ;
 *   4. endpoints webhook déclarés, dont l'endpoint **Connect** sans lequel les
 *      retraits restent bloqués en `processing` ;
 *   5. état réel des comptes vendeurs validés (capability `transfers`).
 *
 *   php artisan stripe:doctor
 *   php artisan stripe:doctor --currency=EUR --user=42
 */
class StripeDoctor extends Command
{
    protected $signature = 'stripe:doctor
        {--currency=EUR : Devise des virements à contrôler}
        {--user= : Ne contrôler que ce vendeur (id)}
        {--fix : Repasse en attente les comptes validés chez nous mais refusés par Stripe}';

    protected $description = 'Diagnostique la chaîne de virement IBAN (Stripe Connect)';

    private int $problems = 0;

    public function handle(StripeService $stripe): int
    {
        $currency = strtoupper((string) $this->option('currency'));

        $this->line('');
        $this->line('<comment>═══ Diagnostic Stripe (virements IBAN) ═══</comment>');

        // 1. Clés API
        if (!$stripe->isConfigured()) {
            $this->fail_('Clés API Stripe absentes.', 'php artisan stripe:keys --secret=sk_... --publishable=pk_...');
            return self::FAILURE;
        }

        $test = $stripe->testConnection();
        if (!($test['success'] ?? false)) {
            $this->fail_($test['message'] ?? 'Connexion Stripe impossible.', 'Vérifiez la clé secrète.');
            return self::FAILURE;
        }
        $this->ok("Connexion API OK — mode {$stripe->mode()}, compte {$test['data']['account_id']} ({$test['data']['country']}).");

        // 2 & 3. Soldes plateforme par devise
        $balances = $stripe->platformBalanceCurrencies();
        if (empty($balances)) {
            $this->warn_('Solde plateforme illisible ou vide.');
        } else {
            $this->line('   Soldes disponibles : ' . implode(', ', array_map(
                fn ($cur, $amt) => sprintf('%s %.2f', $cur, $amt),
                array_keys($balances),
                $balances,
            )));
        }

        if (!array_key_exists($currency, $balances)) {
            $this->fail_(
                "Aucun solde en {$currency} : tout virement en {$currency} sera refusé "
                    . '(« insufficient available funds »), même si le solde global est positif.',
                "Activez le règlement multi-devises {$currency} sur le compte Stripe, "
                    . "ou encaissez en {$currency}.",
            );
        } else {
            $this->ok(sprintf('Solde %s disponible : %.2f.', $currency, $balances[$currency]));
        }

        // 4. Webhooks
        $endpoints = $stripe->listWebhookEndpoints();
        $connect = array_filter($endpoints, fn ($e) => $e['connect']);
        $payoutEvents = array_filter(
            $endpoints,
            fn ($e) => in_array('payout.paid', $e['events'], true) || in_array('*', $e['events'], true),
        );

        if (empty($endpoints)) {
            $this->fail_(
                'Aucun endpoint webhook déclaré : les retraits resteront bloqués en « processing ».',
                'php artisan stripe:webhook',
            );
        } elseif (empty($connect) && empty($payoutEvents)) {
            $this->fail_(
                'Aucun endpoint Connect (payout.paid / payout.failed) : les événements de '
                    . 'virement des comptes vendeurs ne parviennent pas à la plateforme.',
                'php artisan stripe:webhook',
            );
        } else {
            $this->ok(count($endpoints) . ' endpoint(s) webhook déclaré(s), dont les événements de virement.');
        }

        if (empty($stripe->webhookSecretConnect())) {
            $this->warn_('Secret de signature Connect absent : les événements payout.* seront rejetés '
                . '(signature invalide). → php artisan stripe:keys --webhook-connect=whsec_...');
        }

        // 5. Comptes vendeurs
        $query = User::whereNotNull('stripe_account_id');
        if ($this->option('user')) {
            $query->where('id', (int) $this->option('user'));
        } else {
            $query->where('stripe_account_status', 'approved');
        }

        $vendors = $query->limit(25)->get();

        if ($vendors->isEmpty()) {
            $this->line('   Aucun compte vendeur à contrôler.');
        } else {
            $rows = [];
            $toFix = [];
            foreach ($vendors as $vendor) {
                $state = $stripe->accountState($vendor->stripe_account_id);
                if ($vendor->stripe_account_status === 'approved' && $state['exists'] && !$state['ready']) {
                    $toFix[] = [$vendor, $state];
                }
                $rows[] = [
                    $vendor->id,
                    trim(($vendor->first_name ?? '') . ' ' . ($vendor->last_name ?? '')),
                    $vendor->stripe_account_status,
                    $state['transfers'],
                    $state['ready'] ? 'oui' : 'NON',
                    implode(', ', array_slice($state['requirements_due'], 0, 3)) ?: '—',
                ];

                if ($vendor->stripe_account_status === 'approved' && !$state['ready']) {
                    $this->problems++;
                }
            }

            $this->table(['id', 'vendeur', 'statut ASSO', 'transfers', 'virement possible', 'manquants'], $rows);

            $blocked = collect($rows)->where(4, 'NON')->count();
            if ($blocked > 0) {
                $this->warn_("{$blocked} compte(s) validé(s) côté ASSO mais NON activé(s) par Stripe : "
                    . 'leurs virements échoueraient. Le vendeur doit renvoyer son dossier.');
            }

            if (!empty($toFix)) {
                if ($this->option('fix')) {
                    $this->repairApprovedButBlocked($toFix);
                } else {
                    $this->line('   <info>→ php artisan stripe:doctor --fix</info> remet ces comptes en attente '
                        . 'et invite les vendeurs à renvoyer leur dossier.');
                }
            }
        }

        $this->line('');
        if ($this->problems > 0) {
            $this->error("❌ {$this->problems} problème(s) détecté(s) — voir ci-dessus.");
            return self::FAILURE;
        }

        $this->info('✅ Chaîne de virement IBAN opérationnelle.');
        return self::SUCCESS;
    }

    /**
     * Remet en attente les comptes validés chez nous que Stripe refuse, et prévient
     * les vendeurs : sans nouveau dossier (KYC), leurs virements resteront refusés.
     *
     * @param array<int, array{0: User, 1: array}> $entries
     */
    private function repairApprovedButBlocked(array $entries): void
    {
        if (!$this->confirm(count($entries) . ' compte(s) vont repasser en attente et les vendeurs seront notifiés. Continuer ?', true)) {
            return;
        }

        $fcm = app(\App\Services\FirebaseMessagingService::class);

        foreach ($entries as [$vendor, $state]) {
            $due = $state['requirements_due'] ?? [];

            $vendor->update([
                'stripe_account_status' => 'pending',
                'stripe_verified_at' => null,
                'stripe_rejection_reason' => 'Informations complémentaires demandées par notre partenaire bancaire'
                    . (empty($due) ? '.' : ' : ' . implode(', ', array_slice($due, 0, 6)) . '.'),
            ]);

            try {
                $fcm->sendToUser(
                    $vendor,
                    'Compte de virement à compléter',
                    'Renvoyez vos informations bancaires (identité, adresse, date de naissance) '
                        . 'pour activer vos virements.',
                    ['type' => 'stripe_account_requirements', 'action' => 'open_stripe_connect'],
                );
            } catch (\Throwable $e) {
                $this->line("   (notification non envoyée au vendeur {$vendor->id})");
            }

            $this->line("   • Vendeur {$vendor->id} repassé en attente.");
        }

        $this->info('✅ Comptes corrigés.');
    }

    private function ok(string $message): void
    {
        $this->line("   <info>✔</info> {$message}");
    }

    private function warn_(string $message): void
    {
        $this->problems++;
        $this->line("   <comment>⚠ {$message}</comment>");
    }

    private function fail_(string $message, string $fix = ''): void
    {
        $this->problems++;
        $this->line("   <fg=red>✖ {$message}</>");
        if ($fix !== '') {
            $this->line("     → <info>{$fix}</info>");
        }
    }
}
