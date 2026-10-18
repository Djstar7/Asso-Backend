<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\MaritimeBooking;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class MaritimePlatformConversationService
{
    public function contactPlatform(MaritimeBooking $booking, User $customer): Conversation
    {
        return DB::transaction(function () use ($booking, $customer) {
            $booking = MaritimeBooking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if ($booking->sender_user_id !== $customer->id) {
                abort(404);
            }
            if ($booking->status !== MaritimeBooking::STATUS_SHIPPED) {
                throw new MaritimeBookingException('Platform contact is available only after the booking is shipped.');
            }

            $resolvedPlatform = CommissionService::platformAccount();
            if (! $resolvedPlatform || $resolvedPlatform->is($customer)) {
                throw new MaritimeBookingException('The official platform account is unavailable.');
            }
            // Serializes lookup/create for all conversations against the official account.
            $platform = User::whereKey($resolvedPlatform->id)->lockForUpdate()->firstOrFail();
            $firstId = min($customer->id, $platform->id);
            $secondId = max($customer->id, $platform->id);

            $conversation = Conversation::where(function ($query) use ($firstId, $secondId) {
                $query->where(fn ($pair) => $pair->where('user1_id', $firstId)->where('user2_id', $secondId))
                    ->orWhere(fn ($pair) => $pair->where('user1_id', $secondId)->where('user2_id', $firstId));
            })
                ->orderBy('id')
                ->lockForUpdate()
                ->first();
            if (! $conversation) {
                $conversation = Conversation::create([
                    'user1_id' => $firstId,
                    'user2_id' => $secondId,
                    'product_id' => null,
                    'diaspo_offer_id' => null,
                    'last_message_at' => now(),
                ]);
            }

            $conversation->maritimeBookings()->syncWithoutDetaching([$booking->id]);

            return $conversation->load('user1:id,first_name,last_name', 'user2:id,first_name,last_name');
        });
    }
}
