<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PayoutAccount;
use App\Services\KPayCatalog;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Coordonnées de versement Mobile Money enregistrées pour les retraits Wallet.
 *
 * Le virement bancaire (IBAN) reste géré par Stripe Connect (/v1/stripe/connect).
 *
 * GET    /v1/wallet/payout-account  → compte enregistré (ou null)
 * PUT    /v1/wallet/payout-account  → crée / remplace
 * DELETE /v1/wallet/payout-account  → supprime
 */
class PayoutAccountController extends Controller
{
    public function show(Request $request)
    {
        $account = PayoutAccount::where('user_id', $request->user()->id)
            ->where('type', PayoutAccount::TYPE_MOBILE_MONEY)
            ->first();

        return response()->json([
            'success' => true,
            'data' => $account?->toApiArray(),
        ]);
    }

    public function upsert(Request $request)
    {
        $validated = $request->validate([
            'provider' => ['required', 'string', Rule::in(array_keys(KPayCatalog::PROVIDERS))],
            // Numéro international sans « + » : indicatif + numéro local.
            'phone_number' => ['required', 'string', 'regex:/^\d{8,15}$/'],
            'account_holder' => ['nullable', 'string', 'max:120'],
        ], [
            'provider.in' => __('wallet.payout_account.provider_unsupported'),
            'phone_number.regex' => __('wallet.payout_account.phone_invalid'),
        ]);

        $account = PayoutAccount::updateOrCreate(
            ['user_id' => $request->user()->id, 'type' => PayoutAccount::TYPE_MOBILE_MONEY],
            [
                'provider' => $validated['provider'],
                'phone_number' => $validated['phone_number'],
                'account_holder' => $validated['account_holder'] ?? null,
                'currency' => KPayCatalog::currencyForProvider($validated['provider']),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => __('wallet.payout_account.saved'),
            'data' => $account->toApiArray(),
        ]);
    }

    public function destroy(Request $request)
    {
        PayoutAccount::where('user_id', $request->user()->id)
            ->where('type', PayoutAccount::TYPE_MOBILE_MONEY)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => __('wallet.payout_account.deleted'),
            'data' => null,
        ]);
    }
}
