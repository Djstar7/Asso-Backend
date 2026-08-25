<?php

namespace App\Console\Commands;

use App\Services\StripeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Alimente le solde Stripe de la PLATEFORME en mode test.
 *
 * Sans fonds sur le compte Stripe, aucun virement vendeur ne peut aboutir : les
 * retraits sont refusés (proprement) faute de trésorerie. Cette commande crée un
 * encaissement avec la carte de test « fonds immédiatement disponibles »
 * (4000 0000 0000 0077), la seule qui crédite le solde DISPONIBLE sans délai.
 *
 *   php artisan stripe:fund-test-balance             (600 dans la devise du compte)
 *   php artisan stripe:fund-test-balance --amount=2000
 *
 * Refuse de s'exécuter en mode live : il s'agirait d'un vrai paiement.
 */
class StripeFundTestBalance extends Command
{
    protected $signature = 'stripe:fund-test-balance
        {--amount=600 : Montant à créditer}
        {--currency= : Devise (défaut : celle du compte Stripe)}';

    protected $description = 'Crédite le solde Stripe de la plateforme (mode test uniquement)';

    public function handle(StripeService $stripe): int
    {
        if (!$stripe->isConfigured()) {
            $this->error('❌ Clés API Stripe manquantes.');
            return self::FAILURE;
        }

        if ($stripe->mode() !== 'test') {
            $this->error('❌ Refusé : le compte Stripe est en mode LIVE, ce serait un vrai paiement.');
            return self::FAILURE;
        }

        $amount = (float) $this->option('amount');
        if ($amount <= 0) {
            $this->error('❌ Montant invalide.');
            return self::FAILURE;
        }

        $currency = $this->option('currency');
        if (!$currency) {
            $balances = $stripe->platformBalanceCurrencies();
            $currency = array_key_first($balances) ?: 'usd';
        }

        $this->line('Soldes avant : ' . json_encode($stripe->platformBalanceCurrencies()));

        $result = $stripe->fundTestBalance($amount, $currency);

        if (!($result['success'] ?? false)) {
            $this->error('❌ ' . ($result['message'] ?? 'Échec de l\'alimentation.'));
            return self::FAILURE;
        }

        // Le rail « virement bancaire » est grisé d'après un cache de capacité.
        Cache::forget('stripe:platform_capacity_EUR');
        Cache::forget('stripe:platform_capacity_' . strtoupper($currency));

        $this->info("✅ {$amount} " . strtoupper($currency) . " crédités ({$result['charge_id']}).");
        $this->line('Soldes après : ' . json_encode($stripe->platformBalanceCurrencies()));
        $this->line('Capacité de virement : ' . $stripe->platformPayoutCapacity('EUR') . ' EUR');

        return self::SUCCESS;
    }
}
