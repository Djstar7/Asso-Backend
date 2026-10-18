<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class MaritimeInsuranceLegacyBackfill
{
    public static function run(): void
    {
        DB::table('maritime_bookings')
            ->where('insurance_opt_in', false)
            ->where('insurance_amount', '>', 0)
            ->orderBy('id')
            ->chunkById(500, function ($bookings) {
                foreach ($bookings as $booking) {
                    $amount = (float) $booking->insurance_amount;
                    $declaredValue = (float) $booking->declared_value;
                    $rate = $declaredValue > 0 ? round($amount / $declaredValue * 100, 4) : 0;
                    $status = match ($booking->payment_status) {
                        'completed' => 'active',
                        'pending' => 'pending_payment',
                        'refunded' => 'refunded',
                        default => 'payment_failed',
                    };

                    DB::table('maritime_bookings')
                        ->where('id', $booking->id)
                        ->update([
                            'insurance_opt_in' => true,
                            'insurance_rate_applied' => $rate,
                            'insurance_quote_amount' => $amount,
                            // Pending/failed legacy bookings had a quote stored as insurance_amount,
                            // but never completed payment; retain the quote separately.
                            'insurance_amount' => in_array($booking->payment_status, ['completed', 'refunded'], true) ? $amount : 0,
                            'insurance_status' => $status,
                        ]);
                }
            });
    }
}
