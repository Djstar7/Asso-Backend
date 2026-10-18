<?php

namespace App\Http\Controllers\Admin\Concerns;

use App\Http\Controllers\Admin\PlatformWithdrawalController;
use App\Http\Controllers\Admin\StripeConnectController as AdminStripeConnect;
use App\Http\Controllers\Api\StripeConnectController;
use App\Http\Controllers\Api\WalletController;
use App\Models\PlatformWithdrawal;
use App\Models\User;
use App\Services\KPayCatalog;
use App\Services\MobileMoneyGateway;
use App\Services\StripeWithdrawalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Retrait, depuis le back-office, du Wallet d'un compte géré par ASSO : compte
 * plateforme ou compte interne d'un partenaire. Rien n'est réimplémenté : on
 * appelle le retrait Wallet de l'app (WalletController) au nom de ce compte —
 * mêmes soldes « Mobile » / « bancaire », verrous, débit atomique et suivi.
 */
trait WithdrawsFromAccount
{
    /** Données de la vue admin.withdrawals.panel. */
    protected function withdrawalViewData(Request $request, User $account): array
    {
        $balances = $this->asAccount($request, $account, fn ($r) => app(WalletController::class)->getWithdrawalBalances($r));

        return [
            'account' => $account,
            'balances' => $balances['data'] ?? null,
            'balancesError' => ($balances['success'] ?? false) ? null : ($balances['message'] ?? 'Soldes indisponibles.'),
            'countries' => $this->withdrawalCountries($account),
            'withdrawals' => PlatformWithdrawal::where('user_id', $account->id)->latest()->limit(20)->get(),
        ];
    }

    /**
     * Soldes par pays : le Wallet est tenu par devise, un pays affiche donc le
     * solde de sa devise (partagé entre pays de même devise, ex. XAF Cameroun/Gabon).
     * Un opérateur n'est retirable que si le prestataire actif le prend en charge.
     */
    protected function withdrawalCountries(User $account): array
    {
        $allowed = app(MobileMoneyGateway::class)->allowedProviders();

        return array_map(function (array $country) use ($account, $allowed) {
            $payable = array_filter(
                $country['providers'],
                fn ($code) => in_array($code, $allowed, true)
                    && KPayCatalog::currencyForProvider($code) === $country['currency'],
                ARRAY_FILTER_USE_KEY
            );

            return $country + [
                'key' => $country['code'] . '-' . $country['currency'],
                'available' => max(0, $account->kpayAvailableFor($country['currency'])),
                'payable' => $payable,
                'shared_with' => collect(PlatformWithdrawalController::COUNTRIES)
                    ->filter(fn ($c) => $c['currency'] === $country['currency'] && $c['code'] !== $country['code'])
                    ->pluck('name')->unique()->values()->all(),
            ];
        }, PlatformWithdrawalController::COUNTRIES);
    }

    /** Retrait Mobile Money (prestataire actif : KPay ou ElgioPay). */
    protected function withdrawMobile(Request $request, User $account, string $notePrefix)
    {
        // Opérateur obligatoirement rattaché à un des pays affichés.
        $known = collect(PlatformWithdrawalController::COUNTRIES)->flatMap(fn ($c) => array_keys($c['providers']))->unique()->all();
        if (!in_array($request->input('provider'), $known, true)) {
            return back()->withInput()->with('error', 'Opérateur inconnu pour les pays ASSO.');
        }

        $request->merge(['notes' => $this->withdrawalNote($request, $notePrefix)]);

        return $this->withdrawalBack($this->asAccount($request, $account, fn ($r) => app(WalletController::class)->initiateKpayWithdrawal($r)), $request);
    }

    /** Virement bancaire vers l'IBAN validé du compte (Stripe Connect). */
    protected function withdrawBank(Request $request, User $account, string $notePrefix)
    {
        $request->merge(['notes' => $this->withdrawalNote($request, $notePrefix)]);

        $result = $this->asAccount(
            $request,
            $account,
            fn ($r) => app(WalletController::class)->initiateStripeWithdrawal($r, app(StripeWithdrawalService::class))
        );

        return $this->withdrawalBack($result, $request);
    }

    /**
     * Enregistre l'IBAN du compte (même soumission Stripe Connect que dans
     * l'app). Le compte passe « en attente » puis est validé via approveAccountIban().
     */
    protected function submitAccountIban(Request $request, User $account)
    {
        try {
            $result = $this->asAccount($request, $account, fn ($r) => app(StripeConnectController::class)->submit($r));
        } catch (\Illuminate\Validation\ValidationException $e) {
            // L'IBAN complet n'est jamais renvoyé en session.
            return back()->withInput($request->except('iban'))->withErrors($e->errors());
        }

        return ($result['success'] ?? false)
            ? back()->with('success', 'IBAN enregistré. Validez-le pour activer le virement bancaire.')
            : back()->withInput($request->except('iban'))->with('error', e($result['message'] ?? 'IBAN non enregistré.'));
    }

    /** Valide l'IBAN en attente (mêmes contrôles Stripe que « Comptes de virement »). */
    protected function approveAccountIban(Request $request, User $account)
    {
        app(AdminStripeConnect::class)->approveWeb($request, $account->id);

        return back();
    }

    /** Relit le statut d'un retrait en cours auprès du prestataire. */
    protected function refreshAccountWithdrawal(Request $request, User $account, PlatformWithdrawal $withdrawal)
    {
        abort_unless($withdrawal->user_id === $account->id, 404);

        $result = $this->asAccount($request, $account, fn ($r) => app(WalletController::class)->checkWithdrawalStatus($r, $withdrawal->id));

        if (!($result['success'] ?? false)) {
            return back()->with('error', e($result['message'] ?? 'Statut indisponible.'));
        }

        return back()->with('success', 'Retrait #' . $withdrawal->id . ' : ' . PlatformWithdrawalController::statusLabel($result['data']['status'] ?? $withdrawal->status) . '.');
    }

    /**
     * Exécute une action du Wallet au nom du compte et renvoie sa réponse JSON
     * décodée. L'administrateur connecté est rétabli ensuite.
     */
    protected function asAccount(Request $request, User $account, callable $action): array
    {
        $resolver = $request->getUserResolver();
        $request->setUserResolver(fn () => $account);

        try {
            $response = $action($request);
        } finally {
            $request->setUserResolver($resolver);
        }

        return $response instanceof JsonResponse ? (array) $response->getData(true) : [];
    }

    private function withdrawalNote(Request $request, string $prefix): string
    {
        $note = trim((string) $request->input('notes'));

        return mb_substr($prefix . ' par ' . $request->user()->name . ($note !== '' ? ' — ' . $note : ''), 0, 500);
    }

    private function withdrawalBack(array $result, Request $request)
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
