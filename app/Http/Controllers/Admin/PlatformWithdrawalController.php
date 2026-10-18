<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\WithdrawsFromAccount;
use App\Http\Controllers\Controller;
use App\Models\PlatformWithdrawal;
use App\Models\User;
use App\Services\CommissionService;
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

    use WithdrawsFromAccount;

    public function index(Request $request)
    {
        $platform = $this->platform($request);

        return view('admin.revenue.withdrawal', $this->withdrawalViewData($request, $platform) + [
            'routes' => [
                'mobile' => route('admin.revenue.withdrawal.mobile'),
                'bank' => route('admin.revenue.withdrawal.bank'),
                'iban' => route('admin.revenue.withdrawal.iban'),
                'approve' => route('admin.revenue.withdrawal.iban.approve'),
                'refresh' => fn ($w) => route('admin.revenue.withdrawal.refresh', $w),
            ],
        ]);
    }

    public function mobile(Request $request)
    {
        return $this->withdrawMobile($request, $this->platform($request), 'Retrait ASSO');
    }

    public function bank(Request $request)
    {
        return $this->withdrawBank($request, $this->platform($request), 'Retrait ASSO');
    }

    public function iban(Request $request)
    {
        return $this->submitAccountIban($request, $this->platform($request));
    }

    public function approveIban(Request $request)
    {
        return $this->approveAccountIban($request, $this->platform($request));
    }

    public function refresh(Request $request, PlatformWithdrawal $withdrawal)
    {
        return $this->refreshAccountWithdrawal($request, $this->platform($request), $withdrawal);
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
}
