<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Setting;

class PayPalService
{
    private ?string $clientId;
    private ?string $clientSecret;
    private string $mode;
    private string $baseUrl;
    private ?string $accessToken = null;
    private ?int $tokenExpiresAt = null;

    public function __construct()
    {
        // Charger les credentials depuis les settings
        $this->clientId = Setting::get('paypal_client_id');
        $this->clientSecret = Setting::get('paypal_client_secret');
        $this->mode = Setting::get('paypal_mode', 'sandbox');

        // Déterminer l'URL de base selon le mode
        $this->baseUrl = $this->mode === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';

        Log::debug('[PayPalService] Service initialized', [
            'mode' => $this->mode,
            'baseUrl' => $this->baseUrl,
            'hasClientId' => !empty($this->clientId),
            'hasClientSecret' => !empty($this->clientSecret),
        ]);
    }

    /** Credentials PayPal présents (client id + secret). */
    public function isConfigured(): bool
    {
        return !empty($this->clientId) && !empty($this->clientSecret);
    }

    /**
     * Generate OAuth 2.0 access token
     */
    public function generateAccessToken(): ?string
    {
        // Si le token est encore valide, le retourner
        if ($this->accessToken && $this->tokenExpiresAt && time() < $this->tokenExpiresAt) {
            Log::debug('[PayPalService] Using cached access token');
            return $this->accessToken;
        }

        try {
            Log::debug('[PayPalService] Generating new access token...');

            $response = Http::asForm()
                ->withBasicAuth($this->clientId, $this->clientSecret)
                ->post("{$this->baseUrl}/v1/oauth2/token", [
                    'grant_type' => 'client_credentials',
                ]);

            if (!$response->successful()) {
                Log::error('[PayPalService] Failed to generate access token', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();
            $this->accessToken = $data['access_token'] ?? null;
            $expiresIn = $data['expires_in'] ?? 3600;
            $this->tokenExpiresAt = time() + $expiresIn - 60; // Expire 1 minute avant

            Log::info('[PayPalService] ✅ Access token generated successfully', [
                'expires_in' => $expiresIn,
            ]);

            return $this->accessToken;
        } catch (\Exception $e) {
            Log::error('[PayPalService] Exception generating access token', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Create a PayPal Order for native payment
     *
     * @param array $params
     *   - amount: float (in XAF)
     *   - user_id: int
     *   - return_url: string (optional)
     *   - cancel_url: string (optional)
     *
     * @return array
     */
    public function createOrder(array $params): array
    {
        $token = $this->generateAccessToken();

        if (!$token) {
            return [
                'success' => false,
                'message' => 'Impossible de générer le token d\'authentification PayPal',
            ];
        }

        try {
            // Montant source (dans sa devise d'origine) → USD (devise d'encaissement
            // PayPal). Conversion via les taux de change stockés/live ; le fallback
            // heuristique (655 XAF/USD) ne sert que si aucun taux n'est disponible.
            $amountSource = (float) $params['amount'];
            $sourceCurrency = strtoupper($params['currency'] ?? 'XAF');

            if (isset($params['amount_usd'])) {
                $amountUsd = round((float) $params['amount_usd'], 2);
            } elseif ($sourceCurrency === 'USD') {
                $amountUsd = round($amountSource, 2);
            } else {
                $converted = \App\Services\ExchangeRateService::convertAmount($sourceCurrency, 'USD', $amountSource);
                $amountUsd = $converted !== null
                    ? round($converted, 2)
                    : round($amountSource / 655, 2);
            }

            $description = $params['description'] ?? "Recharge wallet - {$amountSource} {$sourceCurrency}";

            Log::info('[PayPalService] Creating PayPal order...', [
                'amount_source' => $amountSource,
                'source_currency' => $sourceCurrency,
                'amount_usd' => $amountUsd,
                'user_id' => $params['user_id'] ?? null,
            ]);

            $response = Http::withToken($token)
                ->asJson()
                ->post("{$this->baseUrl}/v2/checkout/orders", [
                    'intent' => 'CAPTURE',
                    'purchase_units' => [
                        [
                            'amount' => [
                                'currency_code' => 'USD',
                                'value' => (string) $amountUsd,
                            ],
                            'description' => $description,
                        ],
                    ],
                    'application_context' => [
                        'brand_name' => 'Asso Platform',
                        'landing_page' => 'LOGIN',
                        'user_action' => 'PAY_NOW',
                        'return_url' => $params['return_url'] ?? url('/'),
                        'cancel_url' => $params['cancel_url'] ?? url('/'),
                    ],
                ]);

            if (!$response->successful()) {
                Log::error('[PayPalService] Failed to create order', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [
                    'success' => false,
                    'message' => 'Échec de la création de la commande PayPal',
                    'error' => $response->json()['message'] ?? 'Unknown error',
                ];
            }

            $data = $response->json();
            $orderId = $data['id'] ?? null;
            $approvalUrl = null;

            // Trouver le lien d'approbation
            foreach ($data['links'] ?? [] as $link) {
                if ($link['rel'] === 'approve') {
                    $approvalUrl = $link['href'];
                    break;
                }
            }

            Log::info('[PayPalService] ✅ Order created successfully', [
                'order_id' => $orderId,
                'approval_url' => $approvalUrl,
                'amount_usd' => $amountUsd,
                'amount_source' => $amountSource,
                'source_currency' => $sourceCurrency,
            ]);

            return [
                'success' => true,
                'order_id' => $orderId,
                'approval_url' => $approvalUrl,
                'amount' => $amountSource,
                'currency' => $sourceCurrency,
                'amount_usd' => $amountUsd,
                'client_id' => $this->clientId,
                'data' => $data,
            ];
        } catch (\Exception $e) {
            Log::error('[PayPalService] Exception creating order', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => 'Erreur lors de la création de la commande PayPal',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Capture a PayPal Order after approval
     *
     * @param string $orderId
     * @return array
     */
    public function captureOrder(string $orderId): array
    {
        $token = $this->generateAccessToken();

        if (!$token) {
            return [
                'success' => false,
                'message' => 'Impossible de générer le token d\'authentification PayPal',
            ];
        }

        try {
            Log::info('[PayPalService] Capturing PayPal order...', [
                'order_id' => $orderId,
            ]);

            $response = Http::withToken($token)
                ->withBody('{}', 'application/json')
                ->post("{$this->baseUrl}/v2/checkout/orders/{$orderId}/capture");

            if (!$response->successful()) {
                Log::error('[PayPalService] Failed to capture order', [
                    'order_id' => $orderId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [
                    'success' => false,
                    'message' => 'Échec de la capture du paiement PayPal',
                    'error' => $response->json()['message'] ?? 'Unknown error',
                ];
            }

            $data = $response->json();
            $status = $data['status'] ?? null;

            Log::info('[PayPalService] ✅ Order captured successfully', [
                'order_id' => $orderId,
                'status' => $status,
                'capture_id' => $data['purchase_units'][0]['payments']['captures'][0]['id'] ?? null,
            ]);

            return [
                'success' => true,
                'status' => $status,
                'order_id' => $orderId,
                'data' => $data,
            ];
        } catch (\Exception $e) {
            Log::error('[PayPalService] Exception capturing order', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => 'Erreur lors de la capture du paiement PayPal',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get order details
     *
     * @param string $orderId
     * @return array
     */
    public function getOrderDetails(string $orderId): array
    {
        $token = $this->generateAccessToken();

        if (!$token) {
            return [
                'success' => false,
                'message' => 'Impossible de générer le token d\'authentification PayPal',
            ];
        }

        try {
            $response = Http::withToken($token)
                ->get("{$this->baseUrl}/v2/checkout/orders/{$orderId}");

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'message' => 'Commande introuvable',
                ];
            }

            return [
                'success' => true,
                'data' => $response->json(),
            ];
        } catch (\Exception $e) {
            Log::error('[PayPalService] Exception getting order details', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Erreur lors de la récupération des détails de la commande',
            ];
        }
    }

    /**
     * Verse un paiement (payout) vers une adresse e-mail PayPal via la Payouts API.
     *
     * Utilisé pour les retraits vendeur : PayPal débite le compte marchand ASSO et
     * crédite l'e-mail du bénéficiaire. Retour synchrone du batch (PENDING/PROCESSING/
     * SUCCESS) ; le statut final peut évoluer ensuite (webhook / polling).
     *
     * @param string $email       E-mail PayPal du bénéficiaire
     * @param float  $amount       Montant à verser (dans $currency)
     * @param string $currency     Devise ISO (ex. USD)
     * @param string $senderItemId Référence interne unique (idempotence / suivi)
     * @param string|null $note    Note affichée au bénéficiaire
     * @return array {
     *   success: bool, payout_batch_id?: string, batch_status?: string,
     *   data?: array, message?: string
     * }
     */
    public function payout(
        string $email,
        float $amount,
        string $currency,
        string $senderItemId,
        ?string $note = null
    ): array {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => "PayPal n'est pas configuré."];
        }

        $token = $this->generateAccessToken();
        if (!$token) {
            return ['success' => false, 'message' => "Authentification PayPal impossible."];
        }

        try {
            $response = Http::withToken($token)
                ->post("{$this->baseUrl}/v1/payments/payouts", [
                    'sender_batch_header' => [
                        'sender_batch_id' => $senderItemId,
                        'email_subject' => 'Vous avez reçu un paiement ASSO',
                        'email_message' => $note ?? 'Votre retrait ASSO a été envoyé sur votre compte PayPal.',
                    ],
                    'items' => [[
                        'recipient_type' => 'EMAIL',
                        'amount' => [
                            'value' => number_format($amount, 2, '.', ''),
                            'currency' => strtoupper($currency),
                        ],
                        'receiver' => $email,
                        'note' => $note ?? 'Retrait ASSO',
                        'sender_item_id' => $senderItemId,
                    ]],
                ]);

            $data = $response->json() ?? [];

            if (!$response->successful()) {
                Log::error('[PayPalService] Payout échoué', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                $message = $data['message']
                    ?? ($data['details'][0]['description'] ?? null)
                    ?? 'Versement PayPal refusé.';

                return ['success' => false, 'message' => $message, 'data' => $data];
            }

            $batch = $data['batch_header'] ?? [];

            Log::info('[PayPalService] ✅ Payout créé', [
                'payout_batch_id' => $batch['payout_batch_id'] ?? null,
                'batch_status' => $batch['batch_status'] ?? null,
                'sender_item_id' => $senderItemId,
            ]);

            return [
                'success' => true,
                'payout_batch_id' => $batch['payout_batch_id'] ?? null,
                'batch_status' => $batch['batch_status'] ?? null,
                'data' => $data,
            ];
        } catch (\Exception $e) {
            Log::error('[PayPalService] Exception payout', [
                'error' => $e->getMessage(),
                'sender_item_id' => $senderItemId,
            ]);

            return ['success' => false, 'message' => "Erreur lors du versement PayPal."];
        }
    }

    /**
     * Statut d'un batch de payout (réconciliation du règlement final).
     * GET /v1/payments/payouts/{payout_batch_id}
     *
     * @return array {
     *   success: bool, batch_status?: string, item_status?: string,
     *   data?: array, message?: string
     * }
     *   batch_status : PENDING | PROCESSING | SUCCESS | DENIED | CANCELED
     *   item_status  : SUCCESS | FAILED | UNCLAIMED | RETURNED | BLOCKED | REFUNDED | ...
     */
    public function getPayoutStatus(string $payoutBatchId): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => "PayPal n'est pas configuré."];
        }

        $token = $this->generateAccessToken();
        if (!$token) {
            return ['success' => false, 'message' => "Authentification PayPal impossible."];
        }

        try {
            $response = Http::withToken($token)
                ->get("{$this->baseUrl}/v1/payments/payouts/{$payoutBatchId}");

            $data = $response->json() ?? [];

            if (!$response->successful()) {
                Log::warning('[PayPalService] getPayoutStatus échoué', [
                    'batch_id' => $payoutBatchId,
                    'status' => $response->status(),
                ]);
                return ['success' => false, 'message' => 'Statut PayPal indisponible.', 'data' => $data];
            }

            return [
                'success' => true,
                'batch_status' => $data['batch_header']['batch_status'] ?? null,
                'item_status' => $data['items'][0]['transaction_status'] ?? null,
                'data' => $data,
            ];
        } catch (\Exception $e) {
            Log::error('[PayPalService] Exception getPayoutStatus', [
                'batch_id' => $payoutBatchId,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => "Erreur lors de la vérification du versement PayPal."];
        }
    }
}
