<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\WithdrawsFromAccount;
use App\Http\Controllers\Controller;
use App\Models\DelivererCompany;
use App\Models\PlatformWithdrawal;
use App\Models\User;
use App\Services\PartnerAccountService;
use Illuminate\Http\Request;

/**
 * Paiement d'un partenaire (livraison ou maritime) : même page et même principe
 * que le retrait ASSO, sur le Wallet de son compte interne, qui reçoit ses
 * courses et son fret. Réservé aux administrateurs.
 */
class PartnerWithdrawalController extends Controller
{
    use WithdrawsFromAccount;

    public function show(Request $request, DelivererCompany $partner)
    {
        $account = $this->account($request, $partner);
        $isMaritime = $partner->service_type === DelivererCompany::SERVICE_MARITIME;

        return view('admin.partner_payouts.show', $this->withdrawalViewData($request, $account) + [
            'partner' => $partner,
            'backUrl' => $isMaritime
                ? route('admin.maritime.partners.show', $partner)
                : route('admin.delivery-partners.edit', $partner),
            'tabKey' => 'asso.partner-withdrawal.tab',
            'routes' => [
                'mobile' => route('admin.partner-payouts.mobile', $partner),
                'bank' => route('admin.partner-payouts.bank', $partner),
                'iban' => route('admin.partner-payouts.iban', $partner),
                'approve' => route('admin.partner-payouts.iban.approve', $partner),
                'refresh' => fn ($w) => route('admin.partner-payouts.refresh', [$partner, $w]),
            ],
        ]);
    }

    public function mobile(Request $request, DelivererCompany $partner)
    {
        return $this->withdrawMobile($request, $this->account($request, $partner), "Paiement {$partner->name}");
    }

    public function bank(Request $request, DelivererCompany $partner)
    {
        return $this->withdrawBank($request, $this->account($request, $partner), "Paiement {$partner->name}");
    }

    public function iban(Request $request, DelivererCompany $partner)
    {
        return $this->submitAccountIban($request, $this->account($request, $partner));
    }

    public function approveIban(Request $request, DelivererCompany $partner)
    {
        return $this->approveAccountIban($request, $this->account($request, $partner));
    }

    public function refresh(Request $request, DelivererCompany $partner, PlatformWithdrawal $withdrawal)
    {
        return $this->refreshAccountWithdrawal($request, $this->account($request, $partner), $withdrawal);
    }

    private function account(Request $request, DelivererCompany $partner): User
    {
        abort_unless($request->user()?->isAdmin(), 403);

        return PartnerAccountService::accountFor($partner);
    }
}
