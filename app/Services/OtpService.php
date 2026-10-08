<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Log;

class OtpService
{
    /**
     * Send OTP code via the active channel (WhatsApp or SMS)
     *
     * @param string $phone Phone number in international format
     * @param string $code OTP code to send
     * @param string|null $preferredChannel Preferred channel: 'whatsapp', 'sms', or null for auto (uses admin setting)
     * @return array ['success' => bool, 'message' => string, 'channel' => string]
     */
    public function sendOtp(string $phone, string $code, ?string $preferredChannel = null): array
    {
        // Check which service is enabled
        $whatsappEnabled = Setting::get('whatsapp_enabled', false);
        $nexaahEnabled = Setting::get('nexaah_enabled', false);

        // Get default OTP service from admin settings
        $defaultOtpService = Setting::get('otp_default_service', 'auto');

        Log::info('OtpService: Checking active services', [
            'phone' => $phone,
            'preferred_channel' => $preferredChannel,
            'default_otp_service' => $defaultOtpService,
            'whatsapp_enabled' => $whatsappEnabled,
            'nexaah_enabled' => $nexaahEnabled,
        ]);

        // If user specified a preferred channel, try it first
        if ($preferredChannel === 'whatsapp' && $whatsappEnabled) {
            return $this->sendViaWhatsApp($phone, $code);
        }

        if ($preferredChannel === 'sms' && $nexaahEnabled) {
            return $this->sendViaSms($phone, $code);
        }

        // If preferred channel was specified but not available, log warning
        if ($preferredChannel && $preferredChannel !== 'auto') {
            Log::warning('OtpService: Preferred channel not available', [
                'preferred_channel' => $preferredChannel,
                'whatsapp_enabled' => $whatsappEnabled,
                'nexaah_enabled' => $nexaahEnabled,
            ]);
        }

        // Use admin-configured default service
        if ($defaultOtpService === 'whatsapp' && $whatsappEnabled) {
            return $this->sendViaWhatsApp($phone, $code);
        }

        if ($defaultOtpService === 'sms' && $nexaahEnabled) {
            return $this->sendViaSms($phone, $code);
        }

        // Fallback to automatic selection (WhatsApp priority)
        // Priority 1: WhatsApp (if enabled)
        if ($whatsappEnabled) {
            return $this->sendViaWhatsApp($phone, $code);
        }

        // Priority 2: SMS via Nexaah (if enabled)
        if ($nexaahEnabled) {
            return $this->sendViaSms($phone, $code);
        }

        // No service enabled
        Log::error('OtpService: No OTP service is enabled');
        return [
            'success' => false,
            'message' => __('otp.none_configured'),
            'channel' => 'none',
        ];
    }

    /**
     * Send OTP via WhatsApp
     */
    private function sendViaWhatsApp(string $phone, string $code): array
    {
        try {
            $whatsappService = new WhatsAppService();

            if (!$whatsappService) {
                Log::warning('OtpService: WhatsApp service not available');
                // Fall back to SMS if WhatsApp fails to initialize
                return $this->sendViaSms($phone, $code);
            }

            Log::info('OtpService: Sending OTP via WhatsApp', ['phone' => $phone]);

            $result = $whatsappService->sendOtp($phone, $code);

            if ($result['success']) {
                return [
                    'success' => true,
                    'message' => __('otp.sent_whatsapp'),
                    'channel' => 'whatsapp',
                    'data' => $result['data'] ?? null,
                ];
            }

            // WhatsApp failed, try SMS fallback
            Log::warning('OtpService: WhatsApp failed, falling back to SMS', [
                'phone' => $phone,
                'error' => $result['message'] ?? 'Unknown error',
            ]);

            return $this->sendViaSms($phone, $code);

        } catch (\Exception $e) {
            Log::error('OtpService: WhatsApp exception, falling back to SMS', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);

            // Fall back to SMS on exception
            return $this->sendViaSms($phone, $code);
        }
    }

    /**
     * Send OTP via SMS (Nexaah)
     */
    private function sendViaSms(string $phone, string $code): array
    {
        try {
            $nexaahService = new NexaahService();

            if (!$nexaahService->isConfigured()) {
                Log::error('OtpService: SMS service not configured');
                return [
                    'success' => false,
                    'message' => __('otp.sms_not_configured'),
                    'channel' => 'sms',
                ];
            }

            Log::info('OtpService: Sending OTP via SMS', ['phone' => $phone]);

            // Use the sendOtp method which sends twice with different sender IDs
            $result = $nexaahService->sendOtp($phone, $code);

            return [
                'success' => $result['success'],
                'message' => $result['message'],
                'channel' => 'sms',
                'data' => $result['data'] ?? null,
            ];

        } catch (\Exception $e) {
            Log::error('OtpService: SMS exception', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => __('otp.sms_error', ['error' => $e->getMessage()]),
                'channel' => 'sms',
            ];
        }
    }

    /**
     * Get the currently active OTP channel
     *
     * @return string 'whatsapp', 'sms', or 'none'
     */
    public function getActiveChannel(): string
    {
        $whatsappEnabled = Setting::get('whatsapp_enabled', false);
        $nexaahEnabled = Setting::get('nexaah_enabled', false);

        if ($whatsappEnabled) {
            return 'whatsapp';
        }

        if ($nexaahEnabled) {
            return 'sms';
        }

        return 'none';
    }

    /**
     * Check if any OTP service is configured and active
     *
     * @return bool
     */
    public function isServiceAvailable(): bool
    {
        return $this->getActiveChannel() !== 'none';
    }
}
