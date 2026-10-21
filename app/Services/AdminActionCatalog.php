<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Dispute;
use App\Models\DisputeShipment;
use App\Models\MaritimeBooking;
use App\Models\Order;
use App\Models\RealEstate\Dispute as EstateDispute;
use App\Models\RealEstate\LandlordVerification;
use App\Models\RealEstate\Listing;
use App\Models\Shop;
use App\Models\ShopLocationRequest;
use App\Models\SupportTicket;
use App\Models\User;
use App\Support\AssoScope;
use App\Support\DepositOrderStage;
use App\Support\LocalOrderStage;
use App\Support\WholesaleOrderStage;
use Illuminate\Database\Eloquent\Builder;

/** Les tâches réellement accessibles à un destinataire, sans usurper sa session. */
class AdminActionCatalog
{
    public function forUser(User $user): array
    {
        if (! $user->isBackofficeStaff() || $user->isTechnicalAccount()) {
            return [];
        }
        $groups = [];
        $add = function (string $section, string $route, Builder $query, callable $item) use (&$groups, $user) {
            if (! $user->canAccessAdminRoute($route)) {
                return;
            }
            foreach ($query->orderBy('id')->lazyById(200) as $record) {
                [$label, $parameters, $state] = $item($record);
                $this->append($groups, $section, $route, $record->getKey(), $label, $parameters, $state);
            }
        };

        $add('estate_listings', 'admin.real-estate.listings.edit', Listing::where('status', 'pending_review'),
            fn ($r) => [$r->reference.' · '.$r->title, [$r->id], $r->updated_at?->toISOString()]);
        $add('landlords', 'admin.real-estate.verifications.show', LandlordVerification::where('status', 'pending'),
            fn ($r) => ['Dossier bailleur #'.$r->id, [$r->id], $r->updated_at?->toISOString()]);
        $add('estate_disputes', 'admin.real-estate.disputes.show', EstateDispute::where('status', 'open'),
            fn ($r) => ['Litige immobilier #'.$r->id, [$r->id], $r->updated_at?->toISOString()]);

        // Les droits d'une section ne lèvent pas le périmètre boutiques ASSO.
        $shops = Shop::withoutGlobalScope(AssoScope::NAME)->pending();
        $locations = ShopLocationRequest::withoutGlobalScope(AssoScope::NAME)->pending();
        if (AssoScope::restricts($user)) {
            $shops->where('user_id', AssoScope::ownerId());
            $locations->whereHas('shop', fn ($q) => $q->withoutGlobalScope(AssoScope::NAME)->where('user_id', AssoScope::ownerId()));
        }
        $add('shops', 'admin.shops.show', $shops,
            fn ($r) => [$r->name.' · Boutique/vendeur à vérifier', [$r->id], $r->updated_at?->toISOString()]);
        $add('shop_locations', 'admin.shops.show', $locations,
            fn ($r) => ['Déplacement de boutique #'.$r->id, [$r->shop_id], $r->updated_at?->toISOString()]);

        $orders = Order::withoutGlobalScope(AssoScope::NAME)
            ->where('payment_status', Order::PAYMENT_PAID)->whereNotIn('status', ['delivered', 'cancelled']);
        if (AssoScope::restricts($user)) {
            AssoScope::orders($orders);
        }
        // Une commande n'apparaît qu'une fois, dans sa première section accessible.
        $orderRoutes = ['deposit' => 'admin.deposit-orders.show', 'wholesale' => 'admin.wholesale-orders.show',
            'asso' => 'admin.asso-orders.show', 'local' => 'admin.local-orders.show'];
        if (collect($orderRoutes)->contains(fn ($route) => $user->canAccessAdminRoute($route))) {
            foreach ($orders->with('items:id,order_id,seller_id')->orderBy('id')->lazyById(200) as $order) {
                $platform = AssoScope::ownerId();
                $isAsso = $platform > 0 && $order->items->isNotEmpty() && $order->items->every(fn ($i) => (int) $i->seller_id === $platform);
                $sections = [
                    'deposit' => $order->isDepositOrder(), 'wholesale' => (bool) $order->is_wholesale,
                    'asso' => $isAsso && ! $order->is_wholesale, 'local' => (bool) $order->has_local_products,
                ];
                foreach ($sections as $section => $applies) {
                    $route = $orderRoutes[$section];
                    if (! $applies || ! $user->canAccessAdminRoute($route)) {
                        continue;
                    }
                    [$stage, $label] = match ($section) {
                        'deposit' => [DepositOrderStage::of($order), DepositOrderStage::label(DepositOrderStage::of($order))],
                        'wholesale' => [WholesaleOrderStage::of($order), WholesaleOrderStage::label(WholesaleOrderStage::of($order))],
                        default => [LocalOrderStage::of($order), LocalOrderStage::label(LocalOrderStage::of($order))],
                    };
                    $trackingLabel = OrderTrackingService::stepLabel($order->tracking_status);
                    if ($trackingLabel) {
                        $label .= ' · '.$trackingLabel;
                    }
                    $state = implode(':', [$stage, $order->tracking_status, $order->verification_status, $order->balance_status]);
                    $this->append($groups, $section, $route, $order->id, '#'.$order->order_number.' · '.$label, [$order->id], $state);
                    break;
                }
            }
        }

        $disputes = Dispute::withoutGlobalScope(AssoScope::NAME)->open();
        $shipments = DisputeShipment::withoutGlobalScope(AssoScope::NAME)
            ->where('payer', 'asso')->where('payment_status', '!=', DisputeShipment::PAYMENT_PAID);
        if (AssoScope::restricts($user)) {
            $filterOrder = fn ($q) => AssoScope::orders($q->withoutGlobalScope(AssoScope::NAME));
            $disputes->whereHas('order', $filterOrder);
            $shipments->whereHas('dispute.order', $filterOrder);
        }
        $add('disputes', 'admin.disputes.show', $disputes,
            fn ($r) => [$r->number.' · '.(Dispute::STATUSES[$r->status] ?? $r->status), [$r->id], $r->status]);
        $add('dispute_shipments', 'admin.disputes.show', $shipments,
            fn ($r) => ['Course de litige #'.$r->id.' · Paiement ASSO attendu', [$r->dispute_id], $r->payment_status]);
        $add('support', 'admin.support.show', SupportTicket::whereIn('status', ['open', 'in_progress']),
            fn ($r) => [$r->ticket_number.' · '.$r->subject, [$r->id], $r->updated_at?->toISOString()]);
        if ($user->canAccessAdminRoute('admin.messages.show') && ($supportId = SupportService::userId())) {
            $incoming = fn ($q) => $q->where('sender_id', '!=', $supportId)->where('is_system', false)->where('is_read', false);
            $conversations = Conversation::where(fn ($q) => $q->where('user1_id', $supportId)->orWhere('user2_id', $supportId))
                ->whereHas('messages', $incoming)
                ->withCount(['messages as pending_count' => $incoming])
                ->withMax(['messages as latest_incoming_id' => $incoming], 'id');
            $add('messages', 'admin.messages.show', $conversations,
                fn ($r) => ['Conversation #'.$r->id.' · '.$r->pending_count.' message(s) à lire', [$r->id], (string) $r->latest_incoming_id]);
        }
        $add('diaspo', 'admin.diaspo.verifications.show', User::where('diaspo_verification_status', 'pending'),
            fn ($r) => ['Vérification DIASPO #'.$r->id, [$r->id], 'pending']);
        $add('maritime', 'admin.maritime.bookings.show', MaritimeBooking::whereIn('status', [MaritimeBooking::STATUS_PAID,
            MaritimeBooking::STATUS_DROPPED_OFF, MaritimeBooking::STATUS_SHIPPED, MaritimeBooking::STATUS_ARRIVED]),
            fn ($r) => ['Envoi #'.$r->id.' · '.$r->statusLabel(), [$r->id], $r->status]);

        ksort($groups);

        return $groups;
    }

    private function append(array &$groups, string $section, string $route, int $id, string $label, array $parameters, ?string $state): void
    {
        $groups[$section] ??= [
            'count' => 0, 'items' => [], 'states' => [],
            'url' => route(\Illuminate\Support\Str::beforeLast($route, '.').'.index'),
        ];
        $groups[$section]['count']++;
        $groups[$section]['states'][] = $id.':'.$state;
        if (count($groups[$section]['items']) < config('admin_alerts.items_per_section', 20)) {
            $groups[$section]['items'][] = ['label' => $label, 'url' => route($route, $parameters)];
        }
    }
}
