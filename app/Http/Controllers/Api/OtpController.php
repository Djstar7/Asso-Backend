<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PhoneOtp;
use App\Models\User;
use App\Services\OtpService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class OtpController extends Controller
{
    /**
     * Envoie un OTP par SMS pour le register
     *
     * Body attendu :
     *   { "phone": "+237690000000", "otp_method": "whatsapp|sms|auto" }
     */
    public function sendOtp(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|max:20',
            'otp_method' => 'nullable|string|in:whatsapp,sms,auto',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        $otpMethod = $request->otp_method ?? 'auto';
        return $this->sendPhoneOtp($request->phone, $otpMethod);
    }

    /**
     * Vérifie un OTP (SMS).
     *
     * Body attendu :
     *   { "phone": "+237690000000", "code": "123456" }
     */
    public function verifyOtp(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|max:20',
            'code'  => 'required|string|size:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        return $this->verifyPhoneOtp($request->phone, $request->code);
    }

    // ──────────────────────────────────────────────────────────────
    // PRIVÉ — SMS
    // ──────────────────────────────────────────────────────────────

    private function sendPhoneOtp(string $phone, string $otpMethod = 'auto'): JsonResponse
    {
        // Nettoyer le numéro
        $phone = preg_replace('/\s+/', '', $phone);

        // Vérifier si le numéro est déjà utilisé
        if (User::where('phone', $phone)->exists()) {
            return response()->json([
                'message' => 'Ce numéro de téléphone est déjà associé à un compte.',
            ], 422);
        }

        // Check if phone is in OTP bypass list
        $isBypassAllowed = \App\Models\OtpBypassPhone::isAllowedToBypass($phone);

        if ($isBypassAllowed) {
            Log::info('[OTP] OTP bypass enabled for this phone - skipping OTP generation and sending', [
                'phone' => $phone,
                'context' => 'registration'
            ]);

            // Return special response indicating bypass is active
            return response()->json([
                'message' => 'Connexion directe autorisée via WhatsApp',
                'bypass_enabled' => true,
                'channel' => 'whatsapp',
            ], 200);
        }

        // Générer un code à 6 chiffres
        $code = (string) random_int(100000, 999999);

        // Sauvegarder en base (5 minutes)
        PhoneOtp::updateOrCreate(
            ['phone' => $phone],
            [
                'code'       => $code,
                'expires_at' => Carbon::now()->addMinutes(5),
                'verified'   => false,
            ]
        );

        // Envoyer via le service actif (WhatsApp ou SMS)
        try {
            $otpService = new OtpService();

            // Check if any service is available
            if (!$otpService->isServiceAvailable()) {
                Log::error("[OTP] Aucun service d'envoi d'OTP n'est configuré");
                return response()->json([
                    'message' => 'Service d\'envoi d\'OTP non disponible. Contactez l\'administrateur.',
                ], 500);
            }

            // Normalize otp_method: 'auto' becomes null for OtpService
            $preferredChannel = ($otpMethod === 'auto') ? null : $otpMethod;

            $result = $otpService->sendOtp($phone, $code, $preferredChannel);

            Log::info("[OTP] Envoi via {$result['channel']} → {$phone}", [
                'success' => $result['success'],
                'channel' => $result['channel'],
                'preferred_method' => $otpMethod,
            ]);

            if (!$result['success']) {
                return response()->json([
                    'message' => $result['message'],
                ], 500);
            }

            $response = [
                'message' => $result['message'],
                'channel' => $result['channel'],
            ];

            // En développement, retourner le code
            if (app()->environment('local')) {
                $response['otp_code'] = $code;
            }

            return response()->json($response, 200);

        } catch (\Exception $e) {
            Log::error("[OTP] Erreur envoi OTP : " . $e->getMessage());
            return response()->json([
                'message' => 'Erreur lors de l\'envoi de l\'OTP. Veuillez réessayer.',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    private function verifyPhoneOtp(string $phone, string $code): JsonResponse
    {
        $phone = preg_replace('/\s+/', '', $phone);

        // Check if phone is in OTP bypass list
        $isBypassAllowed = \App\Models\OtpBypassPhone::isAllowedToBypass($phone);

        if ($isBypassAllowed) {
            Log::info('[OTP] OTP bypass verification - auto-accepting code', [
                'phone' => $phone,
                'context' => 'registration'
            ]);

            // Mark any pending OTPs as verified
            PhoneOtp::where('phone', $phone)
                ->where('verified', false)
                ->update(['verified' => true]);

            return response()->json([
                'message' => 'Numéro vérifié avec succès (bypass).',
                'bypass_mode' => true,
            ], 200);
        }

        $record = PhoneOtp::where('phone', $phone)
            ->where('code', $code)
            ->where('expires_at', '>', now())
            ->where('verified', false)
            ->first();

        if (!$record) {
            return response()->json([
                'message' => 'Code invalide ou expiré.',
            ], 422);
        }

        $record->update(['verified' => true]);

        return response()->json([
            'message' => 'Numéro vérifié avec succès.',
        ], 200);
    }
}
