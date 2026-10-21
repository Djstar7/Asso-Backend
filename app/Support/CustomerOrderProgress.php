<?php

namespace App\Support;

use App\Models\DelivererCompany;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;

/** Parcours acheteur : étapes métier de l'admin, adaptées au mode de remise. */
class CustomerOrderProgress
{
    public const FILTERS = ['awaiting_payment', 'to_confirm', 'to_validate', 'to_ship', 'preparing', 'shipped', 'in_transit', 'last_mile', 'ready_for_pickup', 'delivered', 'cancelled'];

    public static function forOrder(Order $order): array
    {
        $wholesale = (bool) $order->is_wholesale;
        $agency = $order->isAgencyPickup();
        $stage = $wholesale ? WholesaleOrderStage::of($order) : LocalOrderStage::of($order);
        $steps = $wholesale
            ? ['awaiting_payment', 'to_validate', 'to_ship', 'in_transit', 'last_mile', 'delivered']
            : ['awaiting_payment', 'to_confirm', 'preparing', 'shipped', 'delivered'];

        if ($agency) {
            $steps = $wholesale
                ? ['awaiting_payment', 'to_validate', 'to_ship', 'in_transit', 'ready_for_pickup', 'delivered']
                : ['awaiting_payment', 'to_confirm', 'preparing', 'in_transit', 'ready_for_pickup', 'delivered'];
            // L'arrivée à l'entrepôt ou dans la ville n'autorise pas encore le retrait.
            if ($order->status === 'shipped') {
                $stage = $order->tracking_status === 'ready_for_pickup' ? 'ready_for_pickup' : 'in_transit';
            }
        }

        return [
            'kind' => $wholesale ? 'wholesale' : ($order->has_local_products ? 'local' : 'retail'),
            'delivery_option' => $agency ? 'agency_pickup' : 'home_delivery',
            'stage' => $stage,
            'steps' => $stage === 'cancelled' ? ['cancelled'] : $steps,
        ];
    }

    /** Même définition que Order::isAgencyPickup(), utilisable avant pagination. */
    private static function agencyOrders(): Builder
    {
        return Order::query()->select('orders.id')
            ->where('delivery_mode', Order::DELIVERY_CARRIER)
            ->whereNull('delivery_zone_id')->whereNull('delivery_city_grid_id')
            ->where(function ($q) {
                $q->where('delivery_breakdown->delivery_option', 'agency_pickup')
                    ->orWhere(function ($legacy) {
                        $legacy->whereNull('delivery_breakdown->delivery_option')
                            ->where(function ($mode) {
                                $mode->where('delivery_breakdown->service_mode', DelivererCompany::MODE_AGENCY)
                                    ->orWhere(fn ($company) => $company->whereNull('delivery_breakdown->service_mode')
                                        ->whereHas('deliveryCompany', fn ($partner) => $partner->where('service_mode', DelivererCompany::MODE_AGENCY)));
                            });
                    });
            });
    }

    public static function apply(Builder $query, string $stage): Builder
    {
        return match ($stage) {
            'awaiting_payment' => $query->where('status', 'pending')->where('payment_status', '!=', Order::PAYMENT_PAID),
            'to_confirm', 'preparing' => LocalOrderStage::apply($query->where('is_wholesale', false), $stage),
            'to_validate', 'to_ship' => WholesaleOrderStage::apply($query->where('is_wholesale', true), $stage),
            'shipped' => $query->where('is_wholesale', false)->where('status', 'shipped')->whereNotIn('id', self::agencyOrders()),
            'ready_for_pickup' => $query->where('status', 'shipped')->where('tracking_status', 'ready_for_pickup')->whereIn('id', self::agencyOrders()),
            'last_mile' => WholesaleOrderStage::apply($query->where('is_wholesale', true)->whereNotIn('id', self::agencyOrders()), $stage),
            'in_transit' => $query->where('status', 'shipped')->where(function ($q) {
                $q->where(fn ($agency) => $agency->whereIn('id', self::agencyOrders())
                    ->where(fn ($tracking) => $tracking->whereNull('tracking_status')->orWhere('tracking_status', '!=', 'ready_for_pickup')))
                    ->orWhere(fn ($home) => WholesaleOrderStage::apply($home->where('is_wholesale', true)->whereNotIn('id', self::agencyOrders()), 'in_transit'));
            }),
            'delivered', 'cancelled' => $query->where('status', $stage),
            default => $query->where('status', $stage),
        };
    }
}
