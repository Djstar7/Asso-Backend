<?php

namespace App\Services;

use App\Models\DelivererCodeSync;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/** Missions transporteur, sans nouvel encaissement : le partenaire a déjà été réglé. */
class CarrierCourierService
{
    public const OPEN_STEPS = ['pickup_requested', 'pickup_accepted', 'collected_by_carrier', 'partner_in_transit', 'agency_assigned', 'arrived', 'out_for_delivery'];

    public static function courierDone(Order $order): bool
    {
        return $order->status === 'delivered' || ($order->isAgencyPickup() && $order->tracking_status === 'ready_for_pickup');
    }

    public static function dropoff(Order $order): array
    {
        if ($order->isAgencyPickup() && $order->pickup_agency_snapshot) {
            $agency = $order->pickup_agency_snapshot;
            return ['name' => $agency['name'] ?? null, 'phone' => $agency['phone'] ?? null,
                'address' => $agency['address'] ?? null, 'address_details' => null, 'city' => $agency['city'] ?? null,
                'latitude' => null, 'longitude' => null];
        }
        return \App\Support\DeliveryPresenter::dropoffFor($order);
    }

    public static function presentation(Order $order): array
    {
        if (!$order->isCarrierDelivery()) {
            return ['status' => $order->status, 'carrier_managed' => false, 'can_complete' => true, 'carrier_steps' => []];
        }
        $assigned = $order->delivery_person_id !== null;
        $done = self::courierDone($order);
        $canComplete = !$done && $assigned && ($order->isAgencyPickup()
            ? $order->tracking_status === 'arrived' : $order->tracking_status === 'out_for_delivery');
        $steps = $assigned && !$done ? array_values(array_intersect(AssoShipmentService::nextSteps($order),
            ['collected_by_carrier', 'partner_in_transit', 'arrived', 'out_for_delivery'])) : [];
        return [
            'status' => $done ? 'delivered' : ($assigned ? $order->status : 'confirmed'),
            'carrier_managed' => true, 'can_complete' => $canComplete,
            'completion_kind' => $order->isAgencyPickup() ? 'agency' : 'client',
            'tracking_status' => $order->tracking_status,
            'carrier_steps' => array_map(fn ($step) => ['key' => $step, 'label' => OrderTrackingService::stepLabel($step)], $steps),
        ];
    }

    /** Après commit : une demande durable visible même si le push est indisponible. */
    public function request(Order $order): void
    {
        $requested = DB::transaction(function () use ($order) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (!$order->isCarrierDelivery() || $order->payment_status !== Order::PAYMENT_PAID
                || $order->status !== 'shipped' || !in_array($order->tracking_status, self::OPEN_STEPS, true)
                || $order->delivery_person_id || $order->carrier_courier_requested_at || !$order->deliveryCompany?->is_active) {
                return null;
            }
            $order->update(['carrier_courier_requested_at' => now(),
                'carrier_handover_code' => $order->isAgencyPickup() ? Order::generateConfirmationCode() : null]);
            return $order;
        });
        if (!$requested) {
            return;
        }
        DB::afterCommit(function () use ($requested) {
            $syncs = DelivererCodeSync::where('company_id', $requested->delivery_company_id)->active()->where('is_online', true)->with('user')->get()->unique('user_id');
            foreach ($syncs as $sync) {
                if (!$sync->user) {
                    continue;
                }
                try {
                    app(FirebaseMessagingService::class)->sendToUser($sync->user,
                        $sync->user->localized('notifications.new_delivery_request.title'),
                        $sync->user->localized('notifications.new_delivery_request.body', [
                            'order_number' => $requested->order_number, 'address' => $requested->delivery_address,
                            'fee' => number_format($requested->deliveryPriceShown(), 0, ',', ' '),
                        ]), ['type' => 'new_delivery_request', 'order_id' => (string) $requested->id,
                            'order_number' => $requested->order_number, 'company_id' => (string) $requested->delivery_company_id]);
                } catch (\Throwable $e) {
                    Log::warning('[CarrierCourier] Notification indisponible', ['order_id' => $requested->id, 'user_id' => $sync->user_id]);
                }
            }
        });
    }

    public function accept(Order $order, User $courier): Order
    {
        return DB::transaction(function () use ($order, $courier) {
            User::whereKey($courier->id)->lockForUpdate()->firstOrFail();
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->check($this->synced($order, $courier, true) && $order->carrier_courier_requested_at
                && $order->payment_status === Order::PAYMENT_PAID && $order->status === 'shipped'
                && in_array($order->tracking_status, self::OPEN_STEPS, true), 'disputes.course_unavailable');
            $this->check(!$order->delivery_person_id || $order->delivery_person_id === $courier->id, 'delivery.already_taken');
            if ($order->delivery_person_id === $courier->id) {
                return $order;
            }
            $active = Order::where('delivery_person_id', $courier->id)->where('status', 'shipped')
                ->where(fn ($q) => $q->whereNull('tracking_status')->orWhere('tracking_status', '!=', 'ready_for_pickup'))->count();
            $this->check($active < \App\Http\Controllers\Api\DeliveryController::MAX_ACTIVE_RUNS, 'delivery.max_active_runs', ['count' => \App\Http\Controllers\Api\DeliveryController::MAX_ACTIVE_RUNS]);
            $order->update(['delivery_person_id' => $courier->id]);
            if ($order->tracking_status === 'pickup_requested') {
                app(AssoShipmentService::class)->advance($order, ['step' => 'pickup_accepted'], $courier, 'deliverer');
            }
            return $order->fresh();
        });
    }

    public function advance(Order $order, User $courier, string $step): Order
    {
        return DB::transaction(function () use ($order, $courier, $step) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->assertAssigned($order, $courier);
            $this->check(in_array($step, array_column(self::presentation($order)['carrier_steps'], 'key'), true), 'shipment.invalid_transition');
            return app(AssoShipmentService::class)->advance($order, ['step' => $step], $courier, 'deliverer');
        });
    }

    public function complete(Order $order, User $courier, string $code): Order
    {
        return DB::transaction(function () use ($order, $courier, $code) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->assertAssigned($order, $courier);
            $this->check(self::presentation($order)['can_complete'], 'shipment.invalid_transition');
            $expected = $order->isAgencyPickup() ? $order->carrier_handover_code : $order->confirmation_code;
            $this->check($expected !== null && hash_equals((string) $expected, trim($code)), 'delivery.confirmation_code_incorrect');
            $step = $order->isAgencyPickup() ? 'ready_for_pickup' : 'delivered';
            $order = app(AssoShipmentService::class)->advance($order, ['step' => $step], $courier, 'deliverer');
            $order->update(['carrier_handover_code' => null, 'confirmed_by_deliverer_at' => now()]);
            return $order->fresh();
        });
    }

    private function assertAssigned(Order $order, User $courier): void
    {
        $this->check($order->isCarrierDelivery() && $order->carrier_courier_requested_at
            && $order->delivery_person_id === $courier->id && $order->status === 'shipped'
            && $order->payment_status === Order::PAYMENT_PAID && $this->synced($order, $courier), 'disputes.course_unavailable');
    }

    private function synced(Order $order, User $courier, bool $online = false): bool
    {
        return $order->deliveryCompany?->is_active && DelivererCodeSync::where('company_id', $order->delivery_company_id)
            ->where('user_id', $courier->id)->active()->when($online, fn ($q) => $q->where('is_online', true))->exists();
    }

    private function check(bool $condition, string $key, array $replace = []): void
    {
        if (!$condition) {
            throw ValidationException::withMessages(['shipment' => __($key, $replace)]);
        }
    }
}
