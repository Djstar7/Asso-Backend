<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NexaahService
{
    private ?string $baseUrl;
    private ?string $sendEndpoint;
    private ?string $user;
    private ?string $password;
    private ?string $senderId;
    private bool $isActive = false;

    public function __construct()
    {
        // Lire depuis la table settings (group='sms')
        $this->isActive = Setting::get('nexaah_enabled', false);

        if ($this->isActive) {
            $this->baseUrl = Setting::get('nexaah_base_url', 'https://smsvas.com/bulk/public/index.php/api/v1');
            $this->sendEndpoint = Setting::get('nexaah_send_endpoint', '/sendsms');
            $this->user = Setting::get('nexaah_user', null);
            $this->password = Setting::get('nexaah_password', null);
            $this->senderId = Setting::get('nexaah_sender_id', 'ASSO');
        }
    }

    /**
     * Check if service is active and configured
     */
    public function isConfigured(): bool
    {
        return $this->isActive && $this->user && $this->password && $this->baseUrl;
    }

    /**
     * Send SMS message
     */
    public function sendSms(string $recipient, string $message, ?string $senderId = null): array
    {
        if (!$this->isConfigured()) {
            Log::warning('NexaahService: Service not configured');
            return ['success' => false, 'message' => __('otp.sms_not_configured')];
        }

        try {
            $url = rtrim($this->baseUrl, '/') . $this->sendEndpoint;

            Log::info('NexaahService: Sending SMS', [
                'recipient' => $recipient,
                'sender_id' => $senderId ?? $this->senderId,
                'url' => $url,
            ]);

            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->timeout(30)
            ->post($url, [
                'user' => $this->user,
                'password' => $this->password,
                'senderid' => $senderId ?? $this->senderId,
                'sms' => $message,
                'mobiles' => $recipient,
            ]);

            $body = $response->json() ?? [];

            Log::info('NexaahService: Response', [
                'status' => $response->status(),
                'body' => $body,
            ]);

            // Check various success indicators
            $isSuccess = $this->isSuccessResponse($response, $body);

            return [
                'success' => $isSuccess,
                'message' => $isSuccess ? 'SMS envoyé avec succès' : 'Échec de l\'envoi du SMS',
                'data' => $body,
            ];
        } catch (\Exception $e) {
            Log::error('NexaahService: Exception', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Erreur: ' . $e->getMessage()];
        }
    }

    /**
     * Send OTP code via SMS
     */
    public function sendOtp(string $phone, string $code): array
    {
        $message = __('otp.sms_text', ['code' => $code]);

        // Send twice with different sender IDs for redundancy (like E-Emploie)
        $result1 = $this->sendSms($phone, $message, 'infos');
        $result2 = $this->sendSms($phone, $message);

        // Return success if at least one send succeeded
        if ($result1['success'] || $result2['success']) {
            return ['success' => true, 'message' => __('otp.sent_sms')];
        }

        return ['success' => false, 'message' => __('otp.sms_failed')];
    }

    /**
     * Test connection to Nexaah API
     */
    public function testConnection(): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'Service non configuré'];
        }

        try {
            $creditsEndpoint = $this->baseUrl ? (rtrim($this->baseUrl, '/') . '/smscredit') : null;

            if (!$creditsEndpoint) {
                return ['success' => false, 'message' => 'URL de base non configurée'];
            }

            Log::info('NexaahService: Testing connection', [
                'url' => $creditsEndpoint,
                'user' => $this->user,
            ]);

            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->timeout(30)
            ->post($creditsEndpoint, [
                'user' => $this->user,
                'password' => $this->password,
            ]);

            $body = $response->json() ?? [];

            Log::info('NexaahService: Test connection response', [
                'status' => $response->status(),
                'body' => $body,
            ]);

            return [
                'success' => $response->successful(),
                'message' => $response->successful() ? 'Connexion réussie' : 'Échec de connexion',
                'credits' => $body['balance'] ?? $body['credits'] ?? $body['credit'] ?? null,
                'data' => $body,
            ];
        } catch (\Exception $e) {
            Log::error('NexaahService: Test connection exception', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Erreur: ' . $e->getMessage()];
        }
    }

    /**
     * Check if response indicates success
     */
    private function isSuccessResponse($response, array $body): bool
    {
        if (!$response->successful()) return false;

        // Check various success indicators
        $status = $body['status'] ?? $body['sent'] ?? $body['error'] ?? $body['response'] ?? null;

        if ($status === null) return $response->successful();

        if (is_bool($status)) return $status;
        if (is_string($status)) {
            return in_array(strtolower($status), ['success', 'ok', 'true', 'sent']);
        }
        if (is_int($status)) return $status === 0 || $status === 200;

        return $response->successful();
    }
}
