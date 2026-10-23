<?php

namespace App\Services;

use App\Models\DeliveryAgency;
use App\Models\Order;
use App\Models\User;
use App\Support\CountryCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** ASSO enregistre les opérations physiques et les confirmations du transporteur. */
class AssoShipmentService
{
    public const EXTERNAL_STEPS = ['pickup_requested', 'pickup_accepted', 'collected_by_carrier',
        'partner_in_transit', 'arrived', 'ready_for_pickup', 'out_for_delivery', 'delivered', 'collected_at_agency'];

    public static function nextSteps(Order $order): array
    {
        if (! $order->isCarrierDelivery() || $order->payment_status !== Order::PAYMENT_PAID
            || in_array($order->status, ['pending', 'cancelled', 'delivered'], true)) {
            return [];
        }
        if (in_array($order->status, ['confirmed', 'preparing'], true)) {
            return ['handed_to_carrier'];
        }
        $final = $order->isAgencyPickup() ? 'ready_for_pickup' : 'out_for_delivery';
        $international = $order->is_wholesale
            || ($order->delivery_breakdown['service_type'] ?? $order->deliveryCompany?->service_type) === 'international';
        $transportSteps = $order->is_wholesale ? ['customs', 'arrived_hub']
            : ($international ? ['customs', 'arrived'] : ['arrived']);

        return match ($order->tracking_status) {
            'handed_to_carrier' => array_merge(['in_transit'], $transportSteps),
            'in_transit' => $transportSteps,
            'customs' => $order->is_wholesale ? ['arrived_hub'] : ['arrived'],
            'arrived_hub' => ['pickup_requested'],
            'pickup_requested' => ['pickup_accepted'],
            'pickup_accepted' => ['collected_by_carrier'],
            'collected_by_carrier' => $order->leavesFromImportHub() ? [$final] : ['partner_in_transit'],
            'partner_in_transit' => ['arrived'],
            'agency_assigned' => ['arrived'],
            'arrived' => [$final],
            'ready_for_pickup' => $order->isAgencyPickup() ? ['collected_at_agency'] : ['out_for_delivery'],
            'out_for_delivery' => ! $order->isAgencyPickup() ? ['delivered'] : [],
            // Ancien colis sans étape reconnue : reprendre avec une confirmation d'acheminement.
            default => ['in_transit'],
        };
    }

    public function assign(Order $order, DeliveryAgency $agency, User $actor): void
    {
        DB::transaction(function () use ($order, $agency, $actor) {
            $order = Order::lockForUpdate()->findOrFail($order->id);
            $this->check($order->isAgencyPickup() && in_array($order->status, ['confirmed', 'preparing', 'shipped'], true), 'agency.invalid_state');
            $this->check(! in_array($order->tracking_status, ['arrived', 'ready_for_pickup'], true)
                || ! $order->delivery_agency_id, 'agency.assignment_locked');
            $agency = DeliveryAgency::with('company')->lockForUpdate()->findOrFail($agency->id);
            $this->check($agency->is_active && $agency->company?->is_active
                && $agency->deliverer_company_id === $order->delivery_company_id, 'agency.wrong_agency');
            $destination = $order->delivery_breakdown['destination_city'] ?? null;
            if (! $destination && ($route = $order->deliveryRoute)) {
                $originCity = $order->is_wholesale ? \App\Support\ImportHub::CITY : $order->items->first()?->product?->shop?->city;
                $destination = $route->bidirectional && $originCity && CountryCode::sameCity($originCity, $route->destination_city)
                    ? $route->origin_city : $route->destination_city;
            }
            $this->check(! $destination || CountryCode::sameCity($destination, $agency->city), 'agency.wrong_agency');
            $legacyArrival = ! $order->delivery_agency_id && in_array($order->tracking_status, ['arrived', 'ready_for_pickup'], true);
            $order->update(['delivery_agency_id' => $agency->id, 'pickup_agency_snapshot' => $agency->toPickupArray()]
                + ($legacyArrival ? ['tracking_status' => 'agency_assigned'] : []));
            $order->trackingEvents()->create([
                'step' => 'agency_assigned', 'label' => __('tracking.steps.agency_assigned'),
                'location' => $agency->name, 'actor_type' => 'admin', 'actor_id' => $actor->id, 'occurred_at' => now(),
            ]);
        });
    }

    /** Coordonnées du partenaire et référence connue, sans inventer une réponse externe. */
    public static function formDefaults(Order $order, ?string $step = null, ?string $trackingNumber = null): array
    {
        $company = $order->deliveryCompany;
        $destinationStep = in_array($step, ['arrived', 'ready_for_pickup', 'out_for_delivery', 'delivered', 'collected_at_agency'], true);
        $shop = $order->is_wholesale ? \App\Support\ImportHub::shop() : $order->items->first()?->product?->shop;
        $city = $destinationStep
            ? ($order->delivery_city ?: ($order->delivery_breakdown['destination_city'] ?? null))
            : ($shop?->city ?: ($order->delivery_breakdown['origin_city'] ?? null));
        $agency = null;
        if ($destinationStep && $order->pickup_agency_snapshot && $order->delivery_agency_id) {
            $assigned = $order->deliveryAgency;
            if ($assigned?->deliverer_company_id === $order->delivery_company_id) {
                $agency = $order->pickup_agency_snapshot;
            }
        }
        if (!$agency && $company && $city) {
            $choices = DeliveryAgency::where('deliverer_company_id', $company->id)->where('is_active', true)->get()
                ->filter(fn ($a) => CountryCode::sameCity($a->city, $city));
            // Aucune agence arbitraire si plusieurs agences desservent la ville.
            if ($choices->count() === 1) {
                $agency = $choices->first()->toPickupArray();
            }
        }
        $phone = collect([$agency['phone'] ?? null, $company?->phone, $company?->user?->phone])
            ->first(fn ($value) => strlen(preg_replace('/\D/', '', (string) $value)) >= 8);
        $email = $company?->email ?: $company?->user?->email;
        $name = $agency['name'] ?? $company?->name;
        $contact = implode(' · ', array_filter(array_unique([$name, $phone, $email])));
        $location = $destinationStep && !$order->isAgencyPickup()
            ? ($order->delivery_address ?: $city)
            : ($agency['address'] ?? $shop?->address ?? $city);
        $reference = filled($trackingNumber) ? trim($trackingNumber) : $order->carrier_tracking_number;

        return [
            'partner_name' => $company?->name,
            'agency_name' => $agency['name'] ?? null,
            'phone' => $phone, 'email' => $email,
            'location' => mb_substr((string) $location, 0, 150),
            'confirmation_contact' => mb_substr($contact, 0, 150),
            'confirmation_reference' => mb_substr($reference ?: 'ASSO:'.$order->order_number.':'.$step, 0, 150),
            'confirmation_channel' => 'platform',
        ];
    }

    public function advance(Order $order, array $data, User $actor, string $actorType = 'admin'): Order
    {
        return DB::transaction(function () use ($order, $data, $actor, $actorType) {
            $order = Order::lockForUpdate()->findOrFail($order->id);
            $step = $data['step'];
            $this->check(in_array($step, self::nextSteps($order), true), 'shipment.invalid_transition');
            if (in_array($step, self::EXTERNAL_STEPS, true)) {
                $defaults = self::formDefaults($order, $step, $data['carrier_tracking_number'] ?? null);
                if (!filled($data['confirmation_contact'] ?? null)) {
                    $data['confirmation_contact'] = $defaults['confirmation_contact'];
                }
                if (!filled($data['confirmation_channel'] ?? null) || $data['confirmation_channel'] === 'platform') {
                    $data['confirmation_channel'] = 'platform';
                    if (!filled($data['confirmation_reference'] ?? null)) {
                        $data['confirmation_reference'] = $defaults['confirmation_reference'];
                    }
                }
                if (!filled($data['location'] ?? null)) {
                    $data['location'] = $defaults['location'];
                }
                foreach (['confirmation_contact', 'confirmation_reference', 'confirmation_channel'] as $field) {
                    $this->check(filled($data[$field] ?? null), 'shipment.confirmation_required');
                }
            }
            if ($order->isAgencyPickup() && in_array($step, ['ready_for_pickup', 'collected_at_agency'], true)) {
                $agency = $order->deliveryAgency()->with('company')->first();
                $this->check($agency && $agency->is_active && $agency->company?->is_active
                    && $agency->deliverer_company_id === $order->delivery_company_id && $order->pickup_agency_snapshot, 'agency.assignment_required');
            }
            $final = in_array($step, ['delivered', 'collected_at_agency'], true);
            if ($final) {
                $this->check($order->canBeHandedOver(), 'orders.balance_due_before_handover');
            }
            $updates = ['status' => $final ? 'delivered' : 'shipped', 'shipped_at' => $order->shipped_at ?? now()];
            if (filled($data['carrier_tracking_number'] ?? null)) {
                $updates['carrier_tracking_number'] = trim($data['carrier_tracking_number']);
            }
            if ($final) {
                $updates += ['delivered_at' => now(), 'confirmation_code' => null];
                if ($step === 'collected_at_agency') {
                    $updates += ['agency_collected_at' => now(), 'agency_collected_by' => $actor->id];
                }
            }
            $order->update($updates);
            app(OrderTrackingService::class)->record($order, $step,
                $data['location'] ?? ($order->isAgencyPickup() ? ($order->pickup_agency_snapshot['name'] ?? null) : null),
                $data['note'] ?? null, $actorType, $actor->id, notifyBuyer: true, confirmation: $data);
            if (!$order->carrier_courier_requested_at && in_array($step, CarrierCourierService::OPEN_STEPS, true)) {
                DB::afterCommit(fn () => app(CarrierCourierService::class)->request($order));
            }

            return $order->fresh();
        });
    }

    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['shipment' => __($message)]);
        }
    }
}
