<?php

namespace App\Services;

use App\Models\ServiceConfiguration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ElgioPay Mobile Money (Cameroun uniquement : MTN MoMo + Orange Money, XAF).
 *
 * Endpoints réels (SDK officiel elgiosoft/elgiopay-php-sdk — la doc publique
 * est fausse : /v1/account n'existe pas) :
 *   POST /api/v1/payments            GET /api/v1/payments/{id}
 *   POST /api/v1/payments/{id}/verify
 *   POST /api/v1/payouts             GET /api/v1/payouts/{id}
 *   GET  /api/v1/balance
 *
 * Auth : `Authorization: Bearer <public_key>` (pk_live_… / pk_test_…). La clé
 * secrète (sk_…) est REFUSÉE en Bearer (401) : elle sert à signer les webhooks.
 *
 * Les méthodes et formats de retour reprennent ceux de KPayService (statuts en
 * MAJUSCULES : COMPLETED / FAILED / CANCELLED / PENDING) pour que les jobs et
 * contrôleurs existants les traitent sans distinction de prestataire.
 */
class ElgioPayService
{
    /** Codes opérateurs (catalogue KPay) servis par ElgioPay → payment_method ElgioPay. */
    public const PROVIDER_METHODS = [
        'MTN_MOMO_CMR' => 'mtn_mobile_money',
        'ORANGE_CMR' => 'orange_money',
    ];

    public const CURRENCY = 'XAF';

    private string $baseUrl;
    private ?string $publicKey;
    private ?string $secretKey;
    private ?string $webhookSecret;
    private bool $active;

    public function __construct()
    {
        $service = ServiceConfiguration::where('service_name', ServiceConfiguration::SERVICE_ELGIOPAY)->first();
        $config = $service->configuration ?? [];

        $this->active = (bool) ($service->is_active ?? false);
        $this->publicKey = ($config['public_key'] ?? null) ?: env('ELGIOPAY_PUBLIC_KEY');
        $this->secretKey = ($config['secret_key'] ?? null) ?: env('ELGIOPAY_SECRET_KEY');
        $this->webhookSecret = ($config['webhook_secret'] ?? null) ?: env('ELGIOPAY_WEBHOOK_SECRET');

        $mode = $config['mode'] ?? 'live';
        $default = $mode === 'sandbox' ? 'https://sandbox-api.elgiopay.com' : 'https://api.elgiopay.com';
        $this->baseUrl = rtrim(($config['base_url'] ?? null) ?: $default, '/');
    }

    /** Clés présentes (indépendamment de l'activation). */
    public function hasCredentials(): bool
    {
        return !empty($this->publicKey);
    }

    /** Activé par l'admin ET clés présentes : seul critère de routage. */
    public function isConfigured(): bool
    {
        return $this->active && $this->hasCredentials();
    }

    /** L'opérateur (code catalogue) est-il servi par ElgioPay ? */
    public static function supportsProvider(?string $provider): bool
    {
        return $provider !== null && isset(self::PROVIDER_METHODS[$provider]);
    }

    /**
     * Test des identifiants (panneau admin) via GET /balance.
     */
    public function testConnection(): array
    {
        if (!$this->hasCredentials()) {
            return ['success' => false, 'message' => 'Clé publique ElgioPay manquante.'];
        }

        try {
            $response = $this->http()->timeout(20)->get('/api/v1/balance');
            $data = $response->json() ?? [];

            if ($response->successful()) {
                return [
                    'success' => true,
                    'message' => sprintf(
                        'Connexion ElgioPay réussie — disponible : %s %s (réservé : %s).',
                        number_format((float) ($data['available_balance'] ?? 0), 0, ',', ' '),
                        $data['currency'] ?? self::CURRENCY,
                        number_format((float) ($data['reserved_balance'] ?? 0), 0, ',', ' ')
                    ),
                    'data' => $data,
                ];
            }

            return [
                'success' => false,
                'message' => 'Échec de connexion ElgioPay (HTTP ' . $response->status() . ') : ' . $this->errorMessage($data),
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Erreur: ' . $e->getMessage()];
        }
    }

    /** Solde du compte marchand (balance, available_balance, reserved_balance, currency). */
    public function getBalance(): array
    {
        if (!$this->hasCredentials()) {
            return [];
        }
        try {
            $response = $this->http()->timeout(20)->get('/api/v1/balance');
            return $response->successful() ? ($response->json() ?? []) : [];
        } catch (\Throwable $e) {
            Log::warning('[ElgioPay] getBalance exception', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Encaissement (invite de confirmation sur le téléphone du client).
     *
     * @param array $params amount, provider, phone_number, external_reference, description, metadata
     * @return array success, id, reference, status, message, data
     */
    public function initializePayment(array $params): array
    {
        if (!$this->hasCredentials()) {
            return ['success' => false, 'message' => 'ElgioPay non configuré (clé publique manquante).'];
        }

        $method = self::PROVIDER_METHODS[$params['provider'] ?? ''] ?? null;
        if (!$method) {
            return ['success' => false, 'message' => 'Opérateur non pris en charge par ElgioPay (Cameroun uniquement).'];
        }

        $payload = [
            'amount' => (int) round((float) $params['amount']), // XAF : pas de décimales
            'currency' => self::CURRENCY,
            'payment_method' => $method,
            'customer_phone' => self::normalizePhone($params['phone_number']),
            'reference' => $params['external_reference'],
            'description' => $params['description'] ?? 'Paiement ASSO',
        ];
        if (!empty($params['customer_name'])) {
            $payload['customer_name'] = $params['customer_name'];
        }
        if (!empty($params['metadata'])) {
            $payload['metadata'] = $params['metadata'];
        }

        try {
            $response = $this->http()->timeout(30)->post('/api/v1/payments', $payload);
            $data = $response->json() ?? [];

            Log::info('[ElgioPay] Payment init', [
                'reference' => $payload['reference'],
                'http' => $response->status(),
                'body' => $data,
            ]);

            $id = $data['transaction_id'] ?? $data['data']['transaction_id'] ?? $data['id'] ?? null;

            if ($response->successful() && ($data['success'] ?? true) !== false && $id) {
                return [
                    'success' => true,
                    'id' => $id,
                    'reference' => $data['reference'] ?? $payload['reference'],
                    'status' => self::normalizeStatus($data['status'] ?? 'pending'),
                    'message' => $data['message'] ?? 'Paiement initié',
                    'data' => $data,
                ];
            }

            return [
                'success' => false,
                'message' => $this->errorMessage($data, "Erreur lors de l'initialisation du paiement"),
                'data' => $data,
            ];
        } catch (\Throwable $e) {
            Log::error('[ElgioPay] Payment init exception', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Erreur de connexion au service de paiement'];
        }
    }

    /**
     * Statut d'un encaissement. Si le statut lu est encore « pending », on force
     * une re-vérification auprès de l'opérateur (POST /verify) pour ne pas rester
     * bloqué sur un état obsolète — au plus une fois toutes les 2 min par
     * transaction, le polling (scheduler + mobile) tournant bien plus souvent.
     *
     * @return array status, reference, data, message, reason, external_reference
     */
    public function checkPaymentStatus(string $id): array
    {
        $result = $this->fetchStatus("/api/v1/payments/{$id}", $id);

        if ($result['status'] === 'PENDING' && Cache::add("elgiopay_verify_{$id}", 1, 120)) {
            $verified = $this->fetchStatus("/api/v1/payments/{$id}/verify", $id, 'post');
            if (!in_array($verified['status'], ['ERROR', 'UNKNOWN'], true)) {
                return $verified + ['external_reference' => $result['external_reference'] ?? null];
            }
        }

        return $result;
    }

    /**
     * Retrait vers un compte Mobile Money camerounais.
     * Le compte marchand est débité de amount + frais ElgioPay.
     *
     * @param array $params amount, provider, phone_number, external_reference, description, recipient_name
     * @return array success, id, reference, status, message, data
     */
    public function initiateDisbursement(array $params): array
    {
        if (!$this->hasCredentials()) {
            return ['success' => false, 'message' => 'ElgioPay non configuré (clé publique manquante).'];
        }

        $method = self::PROVIDER_METHODS[$params['provider'] ?? ''] ?? null;
        if (!$method) {
            return ['success' => false, 'message' => 'Opérateur non pris en charge par ElgioPay (Cameroun uniquement).'];
        }

        $payload = [
            'amount' => (int) round((float) $params['amount']),
            'currency' => self::CURRENCY,
            'payout_method' => $method,
            'recipient_phone' => self::normalizePhone($params['phone_number']),
            'recipient_name' => trim((string) ($params['recipient_name'] ?? '')) ?: 'Client ASSO',
            'reference' => $params['external_reference'],
            'description' => $params['description'] ?? 'Retrait wallet ASSO',
        ];

        try {
            $response = $this->http()->timeout(30)->post('/api/v1/payouts', $payload);
            $data = $response->json() ?? [];

            Log::info('[ElgioPay] Payout init', [
                'reference' => $payload['reference'],
                'http' => $response->status(),
                'body' => $data,
            ]);

            $body = $data['data'] ?? $data;
            $id = $body['payout_id'] ?? $body['id'] ?? null;

            if ($response->successful() && ($data['success'] ?? true) !== false && $id) {
                return [
                    'success' => true,
                    'id' => $id,
                    'reference' => $body['reference'] ?? $payload['reference'],
                    'status' => self::normalizeStatus($body['status'] ?? 'pending'),
                    'net_amount' => $body['amount']['total'] ?? $payload['amount'],
                    'fee_amount' => $body['fees'] ?? $body['amount']['fees'] ?? null,
                    'message' => $data['message'] ?? 'Retrait initié',
                    'data' => $data,
                ];
            }

            return [
                'success' => false,
                'message' => $this->errorMessage($data, "Erreur lors de l'initialisation du retrait"),
                'data' => $data,
            ];
        } catch (\Throwable $e) {
            Log::error('[ElgioPay] Payout init exception', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Erreur de connexion au service de paiement'];
        }
    }

    /** Statut d'un retrait (payout_id ElgioPay). */
    public function checkDisbursementStatus(string $id): array
    {
        return $this->fetchStatus("/api/v1/payouts/{$id}", $id);
    }

    /**
     * Vérifie la signature d'un webhook (en-tête X-Elgiopay-Signature).
     *
     * Deux formats acceptés :
     *  - `t=<unix>,v1=<hex>` : HMAC-SHA256 de "<t>.<corps brut>" (format du SDK), avec
     *    tolérance de 5 min contre le rejeu ;
     *  - `<hex>` : HMAC-SHA256 du corps brut (format de la doc publique).
     * Secrets essayés : webhook_secret puis secret_key.
     */
    public function verifyWebhookSignature(string $rawBody, ?string $header, int $tolerance = 300): bool
    {
        $header = trim((string) $header);
        if ($header === '') {
            return false;
        }

        $secrets = array_values(array_unique(array_filter([$this->webhookSecret, $this->secretKey])));
        if (empty($secrets)) {
            return false;
        }

        $timestamp = null;
        $signature = $header;
        if (str_contains($header, '=')) {
            $signature = null;
            foreach (explode(',', $header) as $part) {
                $part = trim($part);
                if (str_starts_with($part, 't=')) {
                    $timestamp = (int) substr($part, 2);
                } elseif (str_starts_with($part, 'v1=')) {
                    $signature = substr($part, 3);
                }
            }
            if (!$timestamp || !$signature || abs(time() - $timestamp) > $tolerance) {
                return false;
            }
        }

        $signed = $timestamp ? $timestamp . '.' . $rawBody : $rawBody;
        foreach ($secrets as $secret) {
            if (hash_equals(hash_hmac('sha256', $signed, $secret), $signature)) {
                return true;
            }
        }

        return false;
    }

    /** Un secret webhook DÉDIÉ est-il renseigné ? (sinon la signature n'est pas bloquante) */
    public function hasDedicatedWebhookSecret(): bool
    {
        return !empty($this->webhookSecret);
    }

    /**
     * Normalise un numéro camerounais en +2376XXXXXXXX (formats acceptés :
     * 6XXXXXXXX, 2376XXXXXXXX, +237 6XX XX XX XX, 00237…).
     */
    public static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (preg_match('/^(?:237)?([2368]\d{8})$/', $digits, $m)) {
            return '+237' . $m[1];
        }
        return str_starts_with($phone, '+') ? $phone : '+' . $digits;
    }

    /**
     * Statut ElgioPay (minuscules) → vocabulaire des jobs existants.
     * `expired` est assimilé à un échec (le client n'a pas validé à temps).
     */
    public static function normalizeStatus(?string $status): string
    {
        return match (strtolower((string) $status)) {
            'completed', 'success', 'successful', 'paid' => 'COMPLETED',
            'failed', 'expired', 'rejected', 'error' => 'FAILED',
            'cancelled', 'canceled' => 'CANCELLED',
            'pending', 'processing', 'initiated', 'created' => 'PENDING',
            default => 'UNKNOWN',
        };
    }

    private function fetchStatus(string $path, string $id, string $verb = 'get'): array
    {
        try {
            $response = $this->http()->timeout(30)->{$verb}($path);
            $data = $response->json() ?? [];

            Log::debug('[ElgioPay] Status', ['path' => $path, 'http' => $response->status(), 'body' => $data]);

            if (!$response->successful()) {
                return [
                    'status' => 'ERROR',
                    'reference' => $id,
                    'message' => $this->errorMessage($data, 'Statut indisponible'),
                    'data' => $data,
                ];
            }

            $body = isset($data['data']) && is_array($data['data']) && isset($data['data']['status'])
                ? $data['data']
                : $data;
            $rawStatus = $body['status'] ?? null;
            $reason = $body['failure_reason'] ?? $body['message'] ?? null;
            if (strtolower((string) $rawStatus) === 'expired' && !$reason) {
                $reason = 'Paiement expiré (non validé à temps)';
            }

            return [
                'status' => self::normalizeStatus($rawStatus),
                'raw_status' => $rawStatus,
                'reference' => $id,
                'external_reference' => $body['reference'] ?? null,
                'data' => $data,
                'message' => $data['message'] ?? null,
                'reason' => $reason,
            ];
        } catch (\Throwable $e) {
            Log::error('[ElgioPay] Status exception', ['path' => $path, 'error' => $e->getMessage()]);
            return ['status' => 'ERROR', 'reference' => $id, 'message' => $e->getMessage()];
        }
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken((string) $this->publicKey)
            ->acceptJson()
            ->asJson();
    }

    /** Message d'erreur lisible depuis {error, message, errors{champ:[…]}}. */
    private function errorMessage(array $data, string $fallback = 'Erreur ElgioPay'): string
    {
        if (!empty($data['errors']) && is_array($data['errors'])) {
            $first = collect($data['errors'])->flatten()->first();
            if ($first) {
                return (string) $first;
            }
        }
        return (string) ($data['message'] ?? $data['error'] ?? $fallback);
    }
}
