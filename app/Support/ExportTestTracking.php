<?php

namespace App\Support;

use App\Models\Order;

class ExportTestTracking
{
    public static function enabled(): bool
    {
        return (bool) config('shipping.export_test_tracking', false);
    }

    public static function isTest(?string $number): bool
    {
        return str_starts_with(strtoupper(trim((string) $number)), 'TEST-ASSO-');
    }

    public static function numberFor(Order $order): ?string
    {
        if (filled($order->carrier_tracking_number)) {
            return $order->carrier_tracking_number;
        }

        return self::enabled() && $order->isInternationalExport()
            ? 'TEST-ASSO-'.$order->id.'-'.($order->tracking_number ?: $order->order_number)
            : null;
    }
}
