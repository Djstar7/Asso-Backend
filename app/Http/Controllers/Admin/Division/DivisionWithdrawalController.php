<?php

namespace App\Http\Controllers\Admin\Division;

use App\Http\Controllers\Admin\Concerns\WithdrawsFromAccount;
use App\Http\Controllers\Controller;
use App\Models\PlatformWithdrawal;
use App\Models\User;
use App\Support\DivisionScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Retrait des gains du chef de rubrique, depuis son espace.
 *
 * Même retrait Wallet que l'application (WalletController : soldes Mobile /
 * bancaire, verrous, débit atomique, suivi), à trois différences près avec le
 * retrait ASSO :
 * - le chef retire SON Wallet, en son nom : frais et contrôles de WithdrawalPolicy
 *   s'appliquent comme pour tout bénéficiaire (pas de passe-droit admin) ;
 * - son mot de passe est redemandé à chaque retrait ;
 * - il ne valide pas lui-même son IBAN : l'admin le fait dans « Comptes de virement ».
 */
class DivisionWithdrawalController extends Controller
{
    use WithdrawsFromAccount;

    public function index(Request $request)
    {
        $account = $this->chief($request);
        $division = DivisionScope::current($request);

        return view('admin.division_space.withdrawal', $this->withdrawalViewData($request, $account) + [
            'division' => $division,
            'divisions' => DivisionScope::divisionsFor($account),
            'query' => $division ? ['division' => $division->id] : [],
            'requirePassword' => true,
            'routes' => [
                'mobile' => route('admin.division.withdrawal.mobile'),
                'bank' => route('admin.division.withdrawal.bank'),
                'iban' => route('admin.division.withdrawal.iban'),
                'refresh' => fn ($w) => route('admin.division.withdrawal.refresh', $w),
            ],
        ]);
    }

    public function mobile(Request $request)
    {
        $account = $this->chief($request);
        $this->confirmPassword($request);

        return $this->withdrawMobile($request, $account, 'Retrait chef de rubrique');
    }

    public function bank(Request $request)
    {
        $account = $this->chief($request);
        $this->confirmPassword($request);

        return $this->withdrawBank($request, $account, 'Retrait chef de rubrique');
    }

    public function iban(Request $request)
    {
        return $this->submitAccountIban($request, $this->chief($request));
    }

    public function refresh(Request $request, PlatformWithdrawal $withdrawal)
    {
        return $this->refreshAccountWithdrawal($request, $this->chief($request), $withdrawal);
    }

    /**
     * Le chef agit en son propre nom : pas de drapeau « admin qui retire pour un
     * compte interne » (qui supprimerait frais et contrôle manuel).
     */
    protected function asAccount(Request $request, User $account, callable $action): array
    {
        abort_unless($request->user()?->is($account), 403);
        $response = $action($request);

        return $response instanceof JsonResponse ? (array) $response->getData(true) : [];
    }

    /** Comme le retrait du trait, sans inscrire le chef comme « admin » du retrait. */
    private function withdrawalBack(array $result, Request $request)
    {
        if (! ($result['success'] ?? false)) {
            $errors = collect($result['errors'] ?? [])->flatten()->implode(' ');

            return back()->withInput($request->except('password'))->with('error', e(trim(($result['message'] ?? 'Retrait impossible.').' '.$errors)));
        }

        return back()->with('success', e($result['message'] ?? 'Retrait en cours.'));
    }

    private function chief(Request $request): User
    {
        $user = $request->user();
        // L'admin a sa propre page de retrait ASSO ; ici, seulement le chef.
        abort_unless($user?->isDivisionHead(), 403);

        return $user;
    }

    private function confirmPassword(Request $request): void
    {
        $request->validate(['password' => ['required', 'current_password']], [
            'password.required' => 'Saisissez votre mot de passe pour confirmer le retrait.',
            'password.current_password' => 'Mot de passe incorrect : retrait non effectué.',
        ]);
    }
}
