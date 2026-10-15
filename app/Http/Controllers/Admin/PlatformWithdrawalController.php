<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\StripeConnectController as AdminStripeConnect;
use App\Http\Controllers\Api\StripeConnectController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Controller;
use App\Models\PlatformWithdrawal;
use App\Models\User;
use App\Services\CommissionService;
use App\Services\KPayCatalog;
use App\Services\MobileMoneyGateway;
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
    /**
     * Pays où ASSO encaisse (comptes KPay par pays), chacun avec sa devise et ses
     * opérateurs de retrait. Pas de remittance entre pays : les fonds d'un pays se
     * retirent uniquement vers un opérateur de ce pays.
     */
    public const COUNTRIES = [
        ['code' => 'BEN', 'name' => 'Bénin', 'flag' => '🇧🇯', 'currency' => 'XOF', 'providers' => ['MTN_MOMO_BEN' => 'MTN', 'MOOV_BEN' => 'Moov']],
        ['code' => 'CMR', 'name' => 'Cameroun', 'flag' => '🇨🇲', 'currency' => 'XAF', 'providers' => ['MTN_MOMO_CMR' => 'MTN', 'ORANGE_CMR' => 'Orange']],
        ['code' => 'CIV', 'name' => "Côte d'Ivoire", 'flag' => '🇨🇮', 'currency' => 'XOF', 'providers' => ['MTN_MOMO_CIV' => 'MTN', 'ORANGE_CIV' => 'Orange']],
        ['code' => 'COD', 'name' => 'RD Congo', 'flag' => '🇨🇩', 'currency' => 'CDF', 'providers' => ['VODACOM_MPESA_COD' => 'Vodacom M-Pesa', 'AIRTEL_COD' => 'Airtel', 'ORANGE_COD' => 'Orange']],
        // Solde USD de la RDC : affiché, mais les opérateurs y versent en CDF.
        ['code' => 'COD', 'name' => 'RD Congo', 'flag' => '🇨🇩', 'currency' => 'USD', 'providers' => ['VODACOM_MPESA_COD' => 'Vodacom M-Pesa', 'AIRTEL_COD' => 'Airtel', 'ORANGE_COD' => 'Orange']],
        ['code' => 'GAB', 'name' => 'Gabon', 'flag' => '🇬🇦', 'currency' => 'XAF', 'providers' => ['AIRTEL_GAB' => 'Airtel']],
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
            'countries' => $this->countries($platform),
            'withdrawals' => PlatformWithdrawal::where('user_id', $platform->id)->latest()->limit(20)->get(),
        ]);
    }

    /**
     * Soldes par pays : le Wallet est tenu par devise, un pays affiche donc le
     * solde de sa devise (partagé entre pays de même devise, ex. XAF Cameroun/Gabon).
     * Un opérateur n'est retirable que si le prestataire actif le prend en charge.
     */
    private function countries(User $platform): array
    {
        $allowed = app(MobileMoneyGateway::class)->allowedProviders();

        return array_map(function (array $country) use ($platform, $allowed) {
            $payable = array_filter(
                $country['providers'],
                fn ($code) => in_array($code, $allowed, true)
                    && KPayCatalog::currencyForProvider($code) === $country['currency'],
                ARRAY_FILTER_USE_KEY
            );

            return $country + [
                'key' => $country['code'] . '-' . $country['currency'],
                'available' => max(0, $platform->kpayAvailableFor($country['currency'])),
                'payable' => $payable,
                'shared_with' => collect(self::COUNTRIES)
                    ->filter(fn ($c) => $c['currency'] === $country['currency'] && $c['code'] !== $country['code'])
                    ->pluck('name')->unique()->values()->all(),
            ];
        }, self::COUNTRIES);
    }

    /** Retrait Mobile Money (prestataire actif : KPay ou ElgioPay). */
    public function mobile(Request $request)
    {
        $platform = $this->platform($request);

        // Opérateur obligatoirement rattaché à un des pays affichés.
        $known = collect(self::COUNTRIES)->flatMap(fn ($c) => array_keys($c['providers']))->unique()->all();
        if (!in_array($request->input('provider'), $known, true)) {
            return back()->withInput()->with('error', 'Opérateur inconnu pour les pays ASSO.');
        }

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

    /**
     * Enregistre l'IBAN du compte plateforme (même soumission Stripe Connect que
     * dans l'app). Le compte passe « en attente » puis est validé via approveIban().
     */
    public function iban(Request $request)
    {
        $platform = $this->platform($request);

        try {
            $result = $this->asPlatform($request, $platform, fn ($r) => app(StripeConnectController::class)->submit($r));
        } catch (\Illuminate\Validation\ValidationException $e) {
            // L'IBAN complet n'est jamais renvoyé en session.
            return back()->withInput($request->except('iban'))->withErrors($e->errors());
        }

        return ($result['success'] ?? false)
            ? back()->with('success', 'IBAN enregistré. Validez-le pour activer le virement bancaire.')
            : back()->withInput($request->except('iban'))->with('error', e($result['message'] ?? 'IBAN non enregistré.'));
    }

    /** Valide l'IBAN en attente du compte plateforme (mêmes contrôles Stripe que « Comptes de virement »). */
    public function approveIban(Request $request)
    {
        $platform = $this->platform($request);

        app(AdminStripeConnect::class)->approveWeb($request, $platform->id);

        return back();
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
