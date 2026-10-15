<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Controller;
use App\Models\PlatformWithdrawal;
use App\Models\User;
use App\Services\CommissionService;
use App\Services\StripeWithdrawalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Retrait des fonds ASSO depuis le back-office.
 *
 * Les commissions ASSO sont créditées sur le Wallet du compte plateforme
 * (CommissionService::platformAccount) : ce contrôleur expose, pour ce compte, le
 * retrait Wallet déjà utilisé par l'app — mêmes soldes « Mobile » / « bancaire »,
 * mêmes verrous, débit atomique et suivi de statut (WalletController). Rien n'est
 * réimplémenté ici : on agit simplement au nom du compte plateforme.
 *
 * Réservé aux administrateurs (pas aux gestionnaires, même avec l'accès Revenus).
 */
class PlatformWithdrawalController extends Controller
{
    /** Libellés des opérateurs Mobile Money proposés au retrait. */
    private const PROVIDER_LABELS = [
        'MTN_MOMO_CMR' => 'MTN Mobile Money (Cameroun)',
        'ORANGE_CMR' => 'Orange Money (Cameroun)',
    ];

    public function __construct(private WalletController $wallet) {}

    public function index(Request $request)
    {
        $platform = $this->platform($request);
        $balances = $this->asPlatform($request, $platform, fn ($r) => $this->wallet->getWithdrawalBalances($r));

        return view('admin.revenue.withdrawal', [
            'platform' => $platform,
            'balances' => $balances['data'] ?? null,
            'balancesError' => ($balances['success'] ?? false) ? null : ($balances['message'] ?? 'Soldes indisponibles.'),
            'providers' => collect($balances['data']['methods']['kpay']['providers'] ?? [])
                ->mapWithKeys(fn ($code) => [$code => self::PROVIDER_LABELS[$code] ?? $code]),
            'withdrawals' => PlatformWithdrawal::where('user_id', $platform->id)->latest()->limit(20)->get(),
        ]);
    }

    /** Retrait Mobile Money (prestataire actif : KPay ou ElgioPay). */
    public function mobile(Request $request)
    {
        $platform = $this->platform($request);
        $request->merge(['notes' => $this->note($request)]);

        $result = $this->asPlatform($request, $platform, fn ($r) => $this->wallet->initiateKpayWithdrawal($r));

        return $this->back($result, $request);
    }

    /** Virement bancaire vers l'IBAN validé du compte plateforme (Stripe Connect). */
    public function bank(Request $request)
    {
        $platform = $this->platform($request);
        $request->merge(['notes' => $this->note($request)]);

        $result = $this->asPlatform(
            $request,
            $platform,
            fn ($r) => $this->wallet->initiateStripeWithdrawal($r, app(StripeWithdrawalService::class))
        );

        return $this->back($result, $request);
    }

    /** Relit le statut d'un retrait en cours auprès du prestataire. */
    public function refresh(Request $request, PlatformWithdrawal $withdrawal)
    {
        $platform = $this->platform($request);
        abort_unless($withdrawal->user_id === $platform->id, 404);

        $result = $this->asPlatform($request, $platform, fn ($r) => $this->wallet->checkWithdrawalStatus($r, $withdrawal->id));

        if (!($result['success'] ?? false)) {
            return back()->with('error', e($result['message'] ?? 'Statut indisponible.'));
        }

        return back()->with('success', 'Retrait #' . $withdrawal->id . ' : ' . self::statusLabel($result['data']['status'] ?? $withdrawal->status) . '.');
    }

    public static function statusLabel(?string $status): string
    {
        return match ($status) {
            'pending' => 'en attente',
            'processing' => 'en cours',
            'completed' => 'effectué',
            'failed' => 'échoué (montant recrédité)',
            'cancelled' => 'annulé',
            default => (string) $status,
        };
    }

    private function platform(Request $request): User
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $platform = CommissionService::platformAccount();
        abort_unless($platform, 404, 'Compte plateforme ASSO introuvable.');

        return $platform;
    }

    /**
     * Exécute une action du Wallet au nom du compte plateforme et renvoie sa
     * réponse JSON décodée. L'administrateur connecté est rétabli ensuite.
     */
    private function asPlatform(Request $request, User $platform, callable $action): array
    {
        $resolver = $request->getUserResolver();
        $request->setUserResolver(fn () => $platform);

        try {
            $response = $action($request);
        } finally {
            $request->setUserResolver($resolver);
        }

        return $response instanceof JsonResponse ? (array) $response->getData(true) : [];
    }

    private function note(Request $request): string
    {
        $note = trim((string) $request->input('notes'));

        return mb_substr('Retrait ASSO par ' . $request->user()->name . ($note !== '' ? ' — ' . $note : ''), 0, 500);
    }

    private function back(array $result, Request $request)
    {
        if (!($result['success'] ?? false)) {
            $errors = collect($result['errors'] ?? [])->flatten()->implode(' ');

            return back()->withInput()->with('error', e(trim(($result['message'] ?? 'Retrait impossible.') . ' ' . $errors)));
        }

        if ($id = $result['data']['withdrawal_id'] ?? null) {
            PlatformWithdrawal::whereKey($id)->update(['admin_id' => $request->user()->id]);
        }

        return back()->with('success', e($result['message'] ?? 'Retrait en cours.'));
    }
}
