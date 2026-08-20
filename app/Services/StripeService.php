<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;

/**
 * Stripe Connect (Custom Accounts) + paiements carte.
 *
 * Wrapper mince du SDK Stripe : TOUT appel `\Stripe\*` reste encapsulé ici, afin
 * que les contrôleurs restent testables en mockant ce service (les tests n'ont
 * alors pas besoin de clés Stripe réelles).
 *
 * Config lue depuis `service_configurations` (service 'stripe'), avec fallback env :
 *   { publishable_key, secret_key, webhook_secret, mode }
 * Le mode (test|live) est déduit du préfixe de la clé secrète (sk_live_ / sk_test_).
 */
class StripeService
{
    private ?string $secretKey;
    private ?string $publishableKey;
    private ?string $webhookSecret;
    private string $mode;
    private ?StripeClient $client = null;

    public function __construct()
    {
        $config = \App\Models\ServiceConfiguration::getConfig('stripe') ?? [];

        $this->secretKey = $config['secret_key'] ?? env('STRIPE_SECRET');
        $this->publishableKey = $config['publishable_key'] ?? env('STRIPE_KEY');
        $this->webhookSecret = $config['webhook_secret'] ?? env('STRIPE_WEBHOOK_SECRET');
        $this->mode = $config['mode'] ?? (str_starts_with((string) $this->secretKey, 'sk_live_') ? 'live' : 'test');

        Log::debug('[StripeService] Initialized', [
            'mode' => $this->mode,
            'secret_key' => $this->secretKey ? 'SET' : 'NULL',
            'publishable_key' => $this->publishableKey ? 'SET' : 'NULL',
        ]);
    }

    /** Whether credentials are configured. */
    public function isConfigured(): bool
    {
        return !empty($this->secretKey);
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function publishableKey(): ?string
    {
        return $this->publishableKey;
    }

    public function webhookSecret(): ?string
    {
        return $this->webhookSecret;
    }

    /** Lazily build the Stripe SDK client. */
    private function client(): StripeClient
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Clés API Stripe manquantes.');
        }
        return $this->client ??= new StripeClient($this->secretKey);
    }

    /**
     * Test the API credentials (used by the admin config panel).
     * Retrieves the platform account — echoes back the account id / country.
     */
    public function testConnection(): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'Clés API Stripe manquantes.'];
        }
        try {
            $account = $this->client()->accounts->retrieve();
            return [
                'success' => true,
                'message' => "Connexion Stripe réussie (mode {$this->mode}, compte {$account->id}).",
                'data' => ['account_id' => $account->id, 'country' => $account->country ?? null],
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Erreur Stripe: ' . $e->getMessage()];
        }
    }

    /**
     * Crée un compte Stripe Connect « Custom-equivalent » pour un vendeur, avec
     * son IBAN comme compte externe (payout).
     *
     * On n'utilise PAS le `type: 'custom'` (déprécié pour les nouvelles
     * intégrations) mais les `controller` properties recommandées, qui décrivent
     * exactement le même comportement : pas de dashboard Stripe côté vendeur, la
     * plateforme (ASSO) collecte les informations (KYC) et porte la responsabilité.
     * Réf. https://docs.stripe.com/connect/migrate-to-controller-properties
     *
     * Les données bancaires brutes (IBAN) sont autorisées côté serveur pour ces
     * comptes contrôlés par la plateforme (hors France/PSD2 où un token est requis).
     *
     * @param array $data country(2), email, first_name, last_name, iban, account_holder_name, currency?, user_id?, tos_ip?, tos_date?
     * @return array { id, external_last4, bank_country, account_holder_name }
     */
    public function createCustomAccountWithBank(array $data): array
    {
        $country = strtoupper($data['country']);
        $currency = strtolower($data['currency'] ?? $this->defaultCurrencyForCountry($country));
        $holder = $data['account_holder_name'];

        $params = [
            // Controller properties = équivalent moderne d'un compte "Custom".
            'controller' => [
                'stripe_dashboard' => ['type' => 'none'],
                'requirement_collection' => 'application',
                'losses' => ['payments' => 'application'],
                'fees' => ['payer' => 'application'],
            ],
            'country' => $country,
            'email' => $data['email'] ?? null,
            'business_type' => 'individual',
            'capabilities' => [
                'transfers' => ['requested' => true],
            ],
            'individual' => array_filter([
                'first_name' => $data['first_name'] ?? null,
                'last_name' => $data['last_name'] ?? null,
                'email' => $data['email'] ?? null,
            ]),
            'external_account' => [
                'object' => 'bank_account',
                'country' => $country,
                'currency' => $currency,
                'account_holder_name' => $holder,
                'account_holder_type' => 'individual',
                'account_number' => $data['iban'],
            ],
            'metadata' => array_filter([
                'asso_user_id' => (string) ($data['user_id'] ?? ''),
            ]),
        ];

        // requirement_collection=application → la plateforme atteste l'acceptation
        // du contrat de service Stripe par le vendeur (date + IP de la requête).
        if (!empty($data['tos_ip'])) {
            $params['tos_acceptance'] = array_filter([
                'date' => $data['tos_date'] ?? time(),
                'ip' => $data['tos_ip'],
            ]);
        }

        $account = $this->client()->accounts->create($params);

        $external = $account->external_accounts->data[0] ?? null;

        return [
            'id' => $account->id,
            'external_last4' => $external->last4 ?? substr(preg_replace('/\s+/', '', $data['iban']), -4),
            'bank_country' => $external->country ?? $country,
            'account_holder_name' => $holder,
        ];
    }

    /**
     * Remplace le compte externe (IBAN) d'un compte Connect existant.
     *
     * @return array { external_last4, bank_country }
     */
    public function replaceExternalAccount(string $accountId, array $data): array
    {
        $country = strtoupper($data['country']);
        $currency = strtolower($data['currency'] ?? $this->defaultCurrencyForCountry($country));

        $bank = $this->client()->accounts->createExternalAccount($accountId, [
            'external_account' => [
                'object' => 'bank_account',
                'country' => $country,
                'currency' => $currency,
                'account_holder_name' => $data['account_holder_name'],
                'account_holder_type' => 'individual',
                'account_number' => $data['iban'],
            ],
            'default_for_currency' => true,
        ]);

        return [
            'external_last4' => $bank->last4 ?? substr(preg_replace('/\s+/', '', $data['iban']), -4),
            'bank_country' => $bank->country ?? $country,
        ];
    }

    public function retrieveAccount(string $accountId): \Stripe\Account
    {
        return $this->client()->accounts->retrieve($accountId);
    }

    /** Devise de payout par défaut selon le pays du compte bancaire. */
    private function defaultCurrencyForCountry(string $country): string
    {
        $eur = ['FR', 'DE', 'ES', 'IT', 'BE', 'NL', 'PT', 'IE', 'FI', 'AT', 'LU', 'GR'];
        return match (true) {
            in_array($country, $eur, true) => 'eur',
            $country === 'GB' => 'gbp',
            $country === 'US' => 'usd',
            default => 'eur',
        };
    }
}
