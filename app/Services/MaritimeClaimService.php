<?php

namespace App\Services;

use App\Models\MaritimeBooking;
use App\Models\MaritimeIndemnityClaim;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MaritimeClaimService
{
    public function payoutProposal(MaritimeIndemnityClaim $claim): array
    {
        return DB::transaction(function () use ($claim) {
            $claim = MaritimeIndemnityClaim::whereKey($claim->id)->lockForUpdate()->firstOrFail();
            $booking = MaritimeBooking::whereKey($claim->maritime_booking_id)->lockForUpdate()->with('sender')->firstOrFail();

            return $this->computePayoutProposal($claim, $booking);
        });
    }

    public function reportLoss(MaritimeBooking $booking, User $customer, string $reason): MaritimeIndemnityClaim
    {
        return DB::transaction(function () use ($booking, $customer, $reason) {
            $lockedBooking = MaritimeBooking::whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if ($lockedBooking->sender_user_id !== $customer->id
                || ! $lockedBooking->insurance_opt_in
                || $lockedBooking->insurance_status !== 'active'
                || (float) $lockedBooking->insurance_amount <= 0
                || $lockedBooking->payment_status !== 'completed'
                || ! in_array($lockedBooking->status, [MaritimeBooking::STATUS_SHIPPED, MaritimeBooking::STATUS_ARRIVED], true)) {
                throw new MaritimeBookingException('A loss report requires active, fully paid maritime insurance on a shipped parcel.');
            }

            if ($lockedBooking->indemnityClaim()->exists()) {
                throw new MaritimeBookingException('A loss report already exists for this booking.');
            }

            $claim = $lockedBooking->indemnityClaim()->create([
                'status' => MaritimeIndemnityClaim::REPORTED,
                'customer_reason' => $reason,
            ]);
            $claim->addHistory('loss_reported', null, MaritimeIndemnityClaim::REPORTED, $customer->id, $reason);

            return $claim->load('histories');
        });
    }

    public function confirmLoss(MaritimeIndemnityClaim $claim, User $admin, string $reason): MaritimeIndemnityClaim
    {
        return DB::transaction(function () use ($claim, $admin, $reason) {
            $claim = MaritimeIndemnityClaim::whereKey($claim->id)->lockForUpdate()->firstOrFail();
            if ($claim->status !== MaritimeIndemnityClaim::REPORTED) {
                throw new MaritimeBookingException('Only a reported claim can be confirmed as a loss.');
            }

            $claim->update([
                'status' => MaritimeIndemnityClaim::LOSS_CONFIRMED,
                'loss_confirmation_reason' => $reason,
                'confirmed_by' => $admin->id,
                'loss_confirmed_at' => now(),
            ]);
            $claim->addHistory('loss_confirmed', MaritimeIndemnityClaim::REPORTED, MaritimeIndemnityClaim::LOSS_CONFIRMED, $admin->id, $reason);

            return $claim->load('histories');
        });
    }

    public function review(MaritimeIndemnityClaim $claim, User $admin, string $decision, string $reason): MaritimeIndemnityClaim
    {
        return DB::transaction(function () use ($claim, $admin, $decision, $reason) {
            $claim = MaritimeIndemnityClaim::whereKey($claim->id)->lockForUpdate()->firstOrFail();
            if ($claim->status !== MaritimeIndemnityClaim::LOSS_CONFIRMED) {
                throw new MaritimeBookingException('A claim must have a confirmed loss before approval or refusal.');
            }

            $status = $decision === 'approve' ? MaritimeIndemnityClaim::APPROVED : MaritimeIndemnityClaim::REFUSED;
            $claim->update([
                'status' => $status,
                'decision_reason' => $reason,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ]);
            $claim->addHistory($decision === 'approve' ? 'approved' : 'refused', MaritimeIndemnityClaim::LOSS_CONFIRMED, $status, $admin->id, $reason);

            return $claim->load('histories');
        });
    }

    public function pay(
        MaritimeIndemnityClaim $claim,
        User $admin,
        string $reason,
        float $confirmedProposalXaf
    ): MaritimeIndemnityClaim {
        $paidClaim = DB::transaction(function () use ($claim, $admin, $reason, $confirmedProposalXaf) {
            $claim = MaritimeIndemnityClaim::whereKey($claim->id)->lockForUpdate()->firstOrFail();
            $booking = MaritimeBooking::whereKey($claim->maritime_booking_id)->lockForUpdate()->with('sender')->firstOrFail();
            $proposal = $this->computePayoutProposal($claim, $booking);
            $payout = $proposal['amount_xaf'];
            if (round($confirmedProposalXaf, 2) !== $payout) {
                throw new MaritimeBookingException('The indemnity proposal changed. Review the latest server amount before confirming.');
            }

            $platform = CommissionService::platformAccount();
            if (! $platform || $platform->is($booking->sender)) {
                throw new MaritimeBookingException('The official platform account is unavailable.');
            }

            $operationReference = "MARITIME-CLAIM-{$claim->id}-PAYOUT";
            app(WalletService::class)->transferBetweenUsers(
                from: $platform,
                to: $booking->sender,
                amount: $payout,
                referenceType: 'maritime_indemnity_claim_payout',
                referenceId: $claim->id,
                operationReference: $operationReference,
                reason: $reason,
                adminId: $admin->id
            );

            $claim->update([
                'status' => MaritimeIndemnityClaim::PAID,
                'payout_amount_xaf' => $payout,
                'payout_operation_reference' => $operationReference,
                'paid_by' => $admin->id,
                'paid_at' => now(),
            ]);
            $claim->addHistory('paid', MaritimeIndemnityClaim::APPROVED, MaritimeIndemnityClaim::PAID, $admin->id, $reason, $payout, $operationReference);

            return $claim->load('booking.sender', 'histories');
        });

        try {
            $sender = $paidClaim->booking->sender;
            if ($sender) {
                app(FirebaseMessagingService::class)->sendToUser(
                    $sender,
                    $sender->localized('notifications.maritime_indemnity_paid.title', ['amount' => number_format((float) $paidClaim->payout_amount_xaf, 0, ',', ' ')]),
                    $sender->localized('notifications.maritime_indemnity_paid.body', [
                        'amount' => number_format((float) $paidClaim->payout_amount_xaf, 0, ',', ' '),
                        'booking' => $paidClaim->maritime_booking_id,
                        'reference' => $paidClaim->payout_operation_reference,
                    ]),
                    [
                        'type' => 'maritime_indemnity_paid',
                        'claim_id' => (string) $paidClaim->id,
                        'maritime_booking_id' => (string) $paidClaim->maritime_booking_id,
                        'amount_xaf' => (string) $paidClaim->payout_amount_xaf,
                        'operation_reference' => $paidClaim->payout_operation_reference,
                    ]
                );
            }
        } catch (\Throwable $e) {
            Log::warning('[Maritime] Indemnity notification failed', ['claim_id' => $paidClaim->id, 'error' => $e->getMessage()]);
        }

        return $paidClaim;
    }

    private function computePayoutProposal(MaritimeIndemnityClaim $claim, MaritimeBooking $booking): array
    {
        if ($claim->status !== MaritimeIndemnityClaim::APPROVED
            || $claim->paid_at !== null
            || $claim->payout_operation_reference !== null) {
            throw new MaritimeBookingException('Only an approved, unpaid claim can be compensated.');
        }
        if (! $booking->insurance_opt_in
            || $booking->insurance_status !== 'active'
            || (float) $booking->insurance_amount <= 0
            || $booking->payment_status !== 'completed'
            || ! in_array($booking->status, [MaritimeBooking::STATUS_SHIPPED, MaritimeBooking::STATUS_ARRIVED], true)
            || $claim->loss_confirmed_at === null) {
            throw new MaritimeBookingException('The booking does not have eligible active insurance and a confirmed loss.');
        }

        $configuredCap = Setting::get('maritime_indemnity_cap_xaf');
        if (! is_numeric($configuredCap) || (float) $configuredCap <= 0) {
            throw new MaritimeBookingException('The global indemnity ceiling must be configured before a payout.');
        }

        $declaredValueXaf = strtoupper($booking->currency) === PaymentMethodService::PIVOT
            ? (float) $booking->declared_value
            : ExchangeRateService::convertAmount($booking->currency, PaymentMethodService::PIVOT, (float) $booking->declared_value);
        if ($declaredValueXaf === null || $declaredValueXaf <= 0) {
            throw new MaritimeBookingException('The declared value cannot be converted to XAF.');
        }

        $amount = round(min((float) $configuredCap, $declaredValueXaf), 2);
        if ($amount <= 0) {
            throw new MaritimeBookingException('The computed indemnity is invalid.');
        }

        return [
            'claim_id' => $claim->id,
            'booking_id' => $booking->id,
            'booking_reference' => "MARITIME-{$booking->id}",
            'client' => $booking->sender?->name,
            'declared_value' => (float) $booking->declared_value,
            'declared_value_currency' => $booking->currency,
            'declared_value_xaf' => round($declaredValueXaf, 2),
            'global_cap_xaf' => round((float) $configuredCap, 2),
            'amount_xaf' => $amount,
        ];
    }
}
