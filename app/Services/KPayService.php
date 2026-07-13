<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * KPay Mobile Money integration (deposits + payouts).
 *
 * Auth: two headers X-API-Key / X-Secret-Key (server-side only).
 * The provider code (e.g. MTN_MOMO_CMR) determines country & currency.
 * Status polling uses the KPay transaction id (pay_xxx / wdr_xxx).
 *
 * Method names mirror the former FreemopayService so the callers
 * (WalletController, jobs) keep a stable interface.
 */
class KPayService
{
    private string $baseUrl;
    private ?string $apiKey;
    private ?string $secretKey;

    public function __construct()
    {
        $config = \App\Models\ServiceConfiguration::getConfig('kpay') ?? [];

        $this->apiKey = $config['api_key'] ?? env('KPAY_API_KEY');
        $this->secretKey = $config['secret_key'] ?? env('KPAY_SECRET_KEY');
        $this->baseUrl = rtrim($config['base_url'] ?? env('KPAY_BASE_URL', 'https://admin.kpay.site'), '/');

        Log::debug('[KPayService] Initialized', [
            'base_url' => $this->baseUrl,
            'api_key' => $this->apiKey ? 'SET' : 'NULL',
            'secret_key' => $this->secretKey ? 'SET' : 'NULL',
        ]);
    }

    /** Whether the service has credentials configured. */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey) && !empty($this->secretKey);
    }

    /**
     * Test the API credentials (used by the admin config panel).
     * Calls GET /api/v1/payments/me which echoes the application/environment.
     */
    public function testConnection(): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'Clés API KPay manquantes.'];
        }
        try {
            $response = Http::withHeaders($this->headers())->timeout(20)
                ->get("{$this->baseUrl}/api/v1/payments/me");

            if ($response->successful()) {
                $data = $response->json() ?? [];
                return [
                    'success' => true,
                    'message' => 'Connexion KPay réussie (' . ($data['environment'] ?? '—') . ').',
                    'data' => $data,
                ];
            }
            return ['success' => false, 'message' => 'Échec de connexion KPay (HTTP ' . $response->status() . ').'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Erreur: ' . $e->getMessage()];
        }
    }

    /** Authenticated request headers. */
    private function headers(): array
    {
        return [
            'X-API-Key' => $this->apiKey,
            'X-Secret-Key' => $this->secretKey,
            'Accept' => 'application/json',
        ];
    }

    /**
     * Initialize a deposit (USSD push). The customer validates on their phone.
     *
     * @param array $params amount, phone_number, provider, external_reference, description, metadata
     * @return array success, id, reference, status, message, data
     */
    public function initializePayment(array $params): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'KPay non configuré (clés API manquantes).'];
        }

        try {
            $payload = [
                'amount' => (float) $params['amount'],
                'provider' => $params['provider'],
                'phoneNumber' => $params['phone_number'],
                'externalId' => $params['external_reference'],
                'description' => $params['description'] ?? 'Recharge wallet ASSO',
            ];
            if (!empty($params['metadata'])) {
                $payload['metadata'] = $params['metadata'];
            }

            $response = Http::withHeaders($this->headers())
                ->asJson()
                ->timeout(30)
                ->post("{$this->baseUrl}/api/v1/payments/init", $payload);

            $data = $response->json() ?? [];

            Log::debug('[KPayService] Payment init', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'id' => $data['id'] ?? null,                 // pay_xxx — used for status polling
                    'reference' => $data['reference'] ?? null,   // KPAY-... (human ref)
                    'status' => $data['status'] ?? 'PENDING',
                    'message' => $data['message'] ?? 'Paiement initié',
                    'data' => $data,
                ];
            }

            return [
                'success' => false,
                'message' => $data['message'] ?? 'Erreur lors de l\'initialisation du paiement',
                'data' => $data,
            ];
        } catch (\Exception $e) {
            Log::error('[KPayService] Payment init exception', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Erreur de connexion au service de paiement'];
        }
    }

    /**
     * Check a deposit status. $id is the KPay payment id (pay_xxx).
     *
     * @return array status, reference, data, message, reason
     */
    public function checkPaymentStatus(string $id): array
    {
        try {
            $response = Http::withHeaders($this->headers())
                ->timeout(30)
                ->get("{$this->baseUrl}/api/v1/payments/{$id}");

            $data = $response->json() ?? [];

            Log::debug('[KPayService] Payment status', [
                'id' => $id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            if (!$response->successful()) {
                return ['status' => 'ERROR', 'reference' => $id, 'message' => $data['message'] ?? 'Statut indisponible', 'data' => $data];
            }

            return [
                'status' => $data['status'] ?? 'UNKNOWN',
                'reference' => $id,
                'data' => $data,
                'message' => $data['message'] ?? null,
                'reason' => $data['failureReason'] ?? null,
            ];
        } catch (\Exception $e) {
            Log::error('[KPayService] Payment status exception', ['id' => $id, 'error' => $e->getMessage()]);
            return ['status' => 'ERROR', 'reference' => $id, 'message' => $e->getMessage()];
        }
    }

    /**
     * Initiate a payout (USSD) to a mobile money account.
     *
     * @param array $params amount, phone_number, provider, external_reference, description, source_country
     * @return array success, id, reference, status, net_amount, fee_amount, message, data
     */
    public function initiateDisbursement(array $params): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'KPay non configuré (clés API manquantes).'];
        }

        try {
            $payload = [
                'amount' => (float) $params['amount'],
                'provider' => $params['provider'],
                'phoneNumber' => $params['phone_number'],
                'externalId' => $params['external_reference'],
                'description' => $params['description'] ?? 'Retrait wallet ASSO',
            ];
            if (!empty($params['source_country'])) {
                $payload['sourceCountry'] = $params['source_country'];
            }

            $response = Http::withHeaders($this->headers())
                ->asJson()
                ->timeout(30)
                ->post("{$this->baseUrl}/api/v1/payments/withdraw", $payload);

            $data = $response->json() ?? [];

            Log::debug('[KPayService] Disbursement init', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'id' => $data['id'] ?? null,                 // wdr_xxx
                    'reference' => $data['reference'] ?? null,
                    'status' => $data['status'] ?? 'PENDING',
                    'net_amount' => $data['netAmount'] ?? null,
                    'fee_amount' => $data['feeAmount'] ?? null,
                    'message' => $data['message'] ?? 'Retrait initié',
                    'data' => $data,
                ];
            }

            return [
                'success' => false,
                'message' => $data['message'] ?? 'Erreur lors de l\'initialisation du retrait',
                'data' => $data,
            ];
        } catch (\Exception $e) {
            Log::error('[KPayService] Disbursement exception', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Erreur de connexion au service de paiement'];
        }
    }

    /**
     * Check a payout status. $id is the KPay withdrawal id (wdr_xxx).
     *
     * @return array status, reference, data, message, reason
     */
    public function checkDisbursementStatus(string $id): array
    {
        try {
            $response = Http::withHeaders($this->headers())
                ->timeout(30)
                ->get("{$this->baseUrl}/api/v1/payments/withdraw/{$id}");

            $data = $response->json() ?? [];

            Log::debug('[KPayService] Disbursement status', [
                'id' => $id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            if (!$response->successful()) {
                return ['status' => 'ERROR', 'reference' => $id, 'message' => $data['message'] ?? 'Statut indisponible', 'data' => $data];
            }

            return [
                'status' => $data['status'] ?? 'UNKNOWN',
                'reference' => $id,
                'data' => $data,
                'message' => $data['message'] ?? null,
                'reason' => $data['failureReason'] ?? null,
            ];
        } catch (\Exception $e) {
            Log::error('[KPayService] Disbursement status exception', ['id' => $id, 'error' => $e->getMessage()]);
            return ['status' => 'ERROR', 'reference' => $id, 'message' => $e->getMessage()];
        }
    }

    /**
     * Suggest the provider/country for a phone number.
     * @return array|null ['country' => 'CMR', 'provider' => 'MTN_MOMO_CMR', 'phoneNumber' => '...']
     */
    public function predictProvider(string $phoneNumber): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }
        try {
            $response = Http::withHeaders($this->headers())
                ->asJson()
                ->timeout(15)
                ->post("{$this->baseUrl}/api/v1/payments/predict-provider", ['phoneNumber' => $phoneNumber]);

            return $response->successful() ? $response->json() : null;
        } catch (\Exception $e) {
            Log::warning('[KPayService] predictProvider exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /** Wallet balances per currency. */
    public function getBalance(): array
    {
        if (!$this->isConfigured()) {
            return [];
        }
        try {
            $response = Http::withHeaders($this->headers())->timeout(20)
                ->get("{$this->baseUrl}/api/v1/payments/balance");
            return $response->successful() ? ($response->json() ?? []) : [];
        } catch (\Exception $e) {
            Log::warning('[KPayService] getBalance exception', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Verify an incoming webhook signature (HMAC-SHA256 hex over the RAW body).
     */
    public static function verifyWebhookSignature(string $rawBody, ?string $signature): bool
    {
        if (empty($signature)) {
            return false;
        }
        $config = \App\Models\ServiceConfiguration::getConfig('kpay') ?? [];
        $secret = $config['webhook_secret'] ?? env('KPAY_WEBHOOK_SECRET');
        if (empty($secret)) {
            return false;
        }
        $expected = hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($expected, $signature);
    }
}
