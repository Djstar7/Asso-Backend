<?php

namespace App\Services\Suppliers\Cj;

use App\Jobs\SubmitCjOrderJob;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SupplierOrder;
use App\Models\SupplierOrderItem;
use App\Models\SupplierVariant;
use App\Models\User;
use App\Services\OrderService;
use App\Services\OrderTrackingService as OrderTracking;
use App\Support\AssoScope;
use App\Support\ImportHub;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Commandes CJ : une commande ASSO payée qui contient des produits CJ donne une
 * commande CJ livrée à l'entrepôt ASSO de Douala.
 *
 * Étapes chez CJ (chacune reprise là où elle s'est arrêtée en cas d'échec) :
 * création (createOrderV2, sans paiement) → panier (addCart) → confirmation
 * (addCartConfirm) → commande parente (saveGenerateParentOrder) → paiement par
 * le solde CJ (payBalanceV2). La commande ASSO n'est confirmée qu'après le
 * paiement CJ : tant que CJ n'a rien encaissé, une annulation rembourse le
 * client automatiquement.
 */
class CjOrders
{
    /** Code CJ « solde insuffisant ». */
    private const INSUFFICIENT_BALANCE = 1604000;

    public function __construct(private CjDriver $driver) {}

    /**
     * Crée la commande fournisseur d'une commande ASSO payée (idempotent).
     * Null si la commande ne contient aucun produit CJ ou n'est pas éligible.
     */
    public function capture(Order $order): ?SupplierOrder
    {
        $supplier = $this->driver->supplier();
        $existing = SupplierOrder::where('supplier_id', $supplier->id)->where('order_id', $order->id)->first();
        if ($existing || $order->payment_status !== Order::PAYMENT_PAID || $order->status !== 'pending' || $order->is_wholesale) {
            return $existing;
        }

        $lines = [];
        $unmapped = [];
        foreach (OrderItem::where('order_id', $order->id)->get() as $item) {
            $variant = $this->supplierVariantFor($item);
            if ($variant) {
                $lines[] = [$item, $variant];
            } elseif ($this->isSupplierProduct($item)) {
                $unmapped[] = $item->product_name;
            }
        }
        if ($lines === [] && $unmapped === []) {
            return null;
        }

        try {
            $supplierOrder = DB::transaction(function () use ($order, $lines, $unmapped, $supplier) {
                $expected = 0.0;
                foreach ($lines as [$item, $variant]) {
                    $expected += ((float) $variant->cost_usd + (float) $variant->supplierProduct->freight_usd) * (int) $item->quantity;
                }
                $supplierOrder = SupplierOrder::create([
                    'supplier_id' => $supplier->id,
                    'order_id' => $order->id,
                    'status' => $unmapped ? SupplierOrder::FAILED : SupplierOrder::AWAITING_APPROVAL,
                    'is_sandbox' => (bool) $supplier->is_sandbox,
                    'external_number' => Str::limit('ASSO-'.$order->order_number, 50, ''),
                    'expected_cost_usd' => round($expected, 2),
                    'last_error' => $unmapped ? 'Déclinaison CJ introuvable pour : '.implode(', ', $unmapped).'. Réimportez le produit ou annulez la commande.' : null,
                    'payload' => $unmapped ? ['unmapped' => $unmapped] : null,
                ]);
                foreach ($lines as [$item, $variant]) {
                    SupplierOrderItem::create([
                        'supplier_order_id' => $supplierOrder->id,
                        'order_item_id' => $item->id,
                        'supplier_variant_id' => $variant->id,
                        'external_variant_id' => $variant->external_id,
                        'quantity' => (int) $item->quantity,
                        'unit_cost_usd' => $variant->cost_usd,
                        'unit_freight_usd' => $variant->supplierProduct->freight_usd,
                    ]);
                }

                return $supplierOrder;
            });
        } catch (UniqueConstraintViolationException) {
            return SupplierOrder::where('supplier_id', $supplier->id)->where('order_id', $order->id)->first();
        }

        if ($supplierOrder->status === SupplierOrder::AWAITING_APPROVAL && $supplier->setting('auto_submit', false)) {
            $this->queueSubmission($supplierOrder, null);
        }

        return $supplierOrder;
    }

    /** Passe la commande en « envoi en cours » et met l'envoi en file. */
    public function queueSubmission(SupplierOrder $supplierOrder, ?int $adminId, bool $force = false): bool
    {
        $updated = SupplierOrder::whereKey($supplierOrder->id)->whereIn('status', SupplierOrder::SUBMITTABLE)
            ->update(['status' => SupplierOrder::SUBMITTING, 'approved_by' => $adminId ?? $supplierOrder->approved_by,
                'approved_at' => $supplierOrder->approved_at ?? now(), 'last_error' => null, 'updated_at' => now()]);
        if ($updated === 0) {
            return false;
        }
        SubmitCjOrderJob::dispatch($supplierOrder->id, $force)->afterCommit();

        return true;
    }

    /** Envoi et paiement chez CJ (appelé par SubmitCjOrderJob). */
    public function submit(SupplierOrder $supplierOrder, bool $force = false): void
    {
        $supplierOrder->refresh();
        if ($supplierOrder->status !== SupplierOrder::SUBMITTING) {
            return;
        }
        $order = Order::withoutGlobalScopes()->find($supplierOrder->order_id);
        if (! $order || $order->status === 'cancelled' || $order->payment_status !== Order::PAYMENT_PAID) {
            $this->closeUnpaid($supplierOrder, 'Commande ASSO annulée ou remboursée avant l’envoi à CJ.');

            return;
        }
        if (! empty($supplierOrder->payload['unmapped'])) {
            $supplierOrder->fail(SupplierOrder::FAILED, 'Déclinaison CJ introuvable pour : '.implode(', ', $supplierOrder->payload['unmapped']).'.');

            return;
        }

        if (! $supplierOrder->external_order_id && ($problem = $this->shippingAddressProblem())) {
            $supplierOrder->fail(SupplierOrder::NEEDS_REVIEW, $problem);

            return;
        }

        try {
            if (! $supplierOrder->external_order_id && ! $this->create($supplierOrder)) {
                return;
            }
            if (! $supplierOrder->shipment_order_id && ! $this->confirmCart($supplierOrder)) {
                return;
            }
            if (! $supplierOrder->paid_at && ! $this->pay($supplierOrder, $force)) {
                return;
            }
        } catch (CjApiException $e) {
            if ($e->isRetryable()) {
                throw $e; // la tâche réessaiera, l'état reste « envoi en cours »
            }
            $supplierOrder->fail(SupplierOrder::FAILED, 'Refus de CJ : '.$e->describe());

            return;
        }

        $this->confirmAssoOrder($supplierOrder, $order);
    }

    /**
     * Annule une commande pas encore payée chez CJ et rembourse le client.
     *
     * @return float montant rendu au client
     */
    public function cancel(SupplierOrder $supplierOrder, string $reason, ?int $adminId): float
    {
        $supplierOrder->refresh();
        if ($supplierOrder->status === SupplierOrder::SUBMITTING) {
            throw new \RuntimeException('Envoi à CJ en cours : réessayez dans une minute.');
        }
        if (! $supplierOrder->isUnpaid()) {
            throw new \RuntimeException('Commande déjà payée chez CJ : elle ne s’annule plus depuis ASSO. Ouvrez un litige chez CJ et traitez le client par une réclamation.');
        }

        return $this->closeUnpaid($supplierOrder, $reason, $adminId);
    }

    /** Relit l'état de la commande chez CJ (suivi planifié). */
    public function sync(SupplierOrder $supplierOrder): void
    {
        if (! $supplierOrder->external_order_id) {
            return;
        }
        $detail = (array) $this->driver->client()->get('shopping/order/getOrderDetail', ['orderId' => $supplierOrder->external_order_id]);
        $this->applyStatus($supplierOrder, $detail);
    }

    /**
     * Alerte CJ « ORDER » : mise à jour de l'état, du transporteur et du numéro
     * de suivi. Renvoie [statut de l'alerte, note].
     */
    public function onWebhook(array $params, string $messageType): array
    {
        $cjId = (string) ($params['cjOrderId'] ?? '');
        $number = (string) ($params['orderNumber'] ?? $params['orderNum'] ?? '');
        $supplierOrder = SupplierOrder::where('supplier_id', $this->driver->supplier()->id)
            ->where(fn ($q) => $q->when($cjId !== '', fn ($q) => $q->orWhere('external_order_id', $cjId))
                ->when($number !== '', fn ($q) => $q->orWhere('external_number', $number)))
            ->when($cjId === '' && $number === '', fn ($q) => $q->whereRaw('1 = 0'))
            ->first();
        if (! $supplierOrder) {
            return ['ignored', 'Commande CJ inconnue d’ASSO.'];
        }
        // ORDER_CONNNECTED (orthographe CJ) : CJ attribue le vrai numéro de commande.
        if ($cjId !== '' && str_starts_with(strtoupper($messageType), 'ORDER_CONN')) {
            $supplierOrder->forceFill(['external_order_id' => $cjId])->save();
        }
        $this->applyStatus($supplierOrder, $params);

        return ['processed', 'Commande '.$supplierOrder->external_number.' : '.($params['orderStatus'] ?? 'mise à jour').'.'];
    }

    /**
     * Alerte CJ « LOGISTIC » : étape du transporteur (0 à 14). Le dédouanement
     * est montré au client ; un incident est signalé à l'admin.
     */
    public function onLogistics(array $params): array
    {
        $cjId = (string) ($params['orderId'] ?? '');
        $supplierOrder = $cjId !== ''
            ? SupplierOrder::where('supplier_id', $this->driver->supplier()->id)->where('external_order_id', $cjId)->first()
            : null;
        if (! $supplierOrder) {
            return ['ignored', 'Colis d’une commande CJ inconnue d’ASSO.'];
        }

        $code = is_numeric($params['trackingStatus'] ?? null) ? (int) $params['trackingStatus'] : null;
        $events = json_decode((string) ($params['logisticsTrackEvents'] ?? ''), true);
        $last = is_array($events) && $events !== [] ? end($events) : null;
        $eventText = is_array($last) ? trim(implode(' · ', array_filter([
            $last['eventTime'] ?? null, trim((string) ($last['activity'] ?? $last['statusDesc'] ?? '')), trim((string) ($last['location'] ?? '')),
        ]))) : null;

        $supplierOrder->fill(array_filter([
            'logistics_status' => $code,
            'logistics_event' => $eventText ? mb_substr($eventText, 0, 500) : null,
            'tracking_number' => $params['trackingNumber'] ?? null,
            'logistic_name' => $params['logisticName'] ?? null,
            'tracking_url' => isset($params['trackingUrl']) ? mb_substr((string) $params['trackingUrl'], 0, 500) : null,
        ], fn ($v) => $v !== null && $v !== ''));
        if ($code !== null && $code >= 1 && $supplierOrder->status === SupplierOrder::PAID) {
            $supplierOrder->fill(['status' => SupplierOrder::SHIPPED, 'shipped_at' => now()]);
        }
        if ($code === 12 && in_array($supplierOrder->status, SupplierOrder::OPEN, true)) {
            $supplierOrder->fill(['status' => SupplierOrder::DELIVERED, 'delivered_at' => now()]);
        }
        if (in_array($code, [13, 14], true) && $supplierOrder->status !== SupplierOrder::RECEIVED) {
            $supplierOrder->last_error = 'Incident transporteur signalé par CJ'.($eventText ? ' : '.$eventText : '').'. Suivez le colis et ouvrez un litige CJ si besoin.';
        }
        $supplierOrder->save();

        if (in_array($supplierOrder->status, [SupplierOrder::SHIPPED, SupplierOrder::DELIVERED], true)) {
            $this->recordStep($supplierOrder, 'in_transit', 'Chine', $this->shippedNote($supplierOrder));
        }
        if (in_array($code, [7, 8], true)) {
            $this->recordStep($supplierOrder, 'customs', 'Cameroun', null);
        }

        return ['processed', 'Colis '.($supplierOrder->tracking_number ?? $supplierOrder->external_number).' : étape transporteur '.($code ?? '?').'.'];
    }

    /** Commande fournisseur retrouvée par un numéro scanné ou saisi (suivi, CJ, ASSO-…, commande ASSO). */
    public function findByCode(string $code): \Illuminate\Support\Collection
    {
        $code = trim($code);
        if ($code === '') {
            return collect();
        }

        return SupplierOrder::where('supplier_id', $this->driver->supplier()->id)
            ->where(fn ($q) => $q->where('tracking_number', $code)->orWhere('external_order_id', $code)
                ->orWhere('external_number', $code)->orWhere('external_number', 'ASSO-'.$code))
            ->get();
    }

    /**
     * Colis reçu à l'entrepôt de Douala : le client est prévenu ; en livraison à
     * domicile, la course part vers les livreurs ; en livraison par transporteur
     * (SOLEX…), l'admin remet le colis depuis Expéditions. Renvoie un message.
     */
    public function receive(SupplierOrder $supplierOrder, User $admin): string
    {
        $updated = SupplierOrder::whereKey($supplierOrder->id)->whereIn('status', SupplierOrder::RECEIVABLE)
            ->update(['status' => SupplierOrder::RECEIVED, 'hub_received_at' => now(), 'hub_received_by' => $admin->id,
                'delivered_at' => $supplierOrder->delivered_at ?? now(), 'updated_at' => now()]);
        if ($updated === 0) {
            throw new \RuntimeException('Ce colis n’est pas attendu à l’entrepôt (déjà reçu, annulé ou pas encore payé chez CJ).');
        }
        $supplierOrder->refresh();

        $order = Order::withoutGlobalScopes()->find($supplierOrder->order_id);
        if (! $order || $order->status === 'cancelled') {
            return 'Colis reçu, mais la commande ASSO est annulée : mettez-le de côté.';
        }
        $this->recordStep($supplierOrder, OrderTracking::IMPORT_HUB_STEP, ImportHub::CITY, null, $admin->id);

        if ($order->isCarrierDelivery()) {
            return 'Colis reçu, client prévenu. Remettez-le au transporteur depuis Expéditions.';
        }
        if (in_array($order->status, ['confirmed', 'preparing'], true)) {
            app(OrderService::class)->requestDelivery($order);

            return 'Colis reçu, client prévenu. La demande de course est partie vers les livreurs.';
        }

        return 'Colis reçu, client prévenu.';
    }

    /** Bac à sable : fait avancer la commande d'une étape chez CJ (300 → 400 → 500 → 600). */
    public function sandboxAdvance(SupplierOrder $supplierOrder): string
    {
        if (! $supplierOrder->is_sandbox || ! $supplierOrder->external_order_id || ! in_array($supplierOrder->status, SupplierOrder::OPEN, true)) {
            throw new \RuntimeException('Réservé aux commandes de test payées.');
        }
        $target = min(600, (int) ($supplierOrder->payload['sandbox_status'] ?? 300) + 100);
        $this->driver->client()->post('shopping/sandbox/updateStatus', ['orderId' => $supplierOrder->external_order_id, 'targetStatus' => $target]);
        $supplierOrder->forceFill(['payload' => array_merge((array) $supplierOrder->payload, ['sandbox_status' => $target])])->save();
        $this->sync($supplierOrder);

        return match ($target) {
            400 => 'en préparation',
            500 => 'expédiée',
            default => 'terminée',
        };
    }

    public function applyStatus(SupplierOrder $supplierOrder, array $info): void
    {
        $status = strtoupper((string) ($info['orderStatus'] ?? ''));
        $supplierOrder->fill(array_filter([
            'external_status' => $status !== '' ? mb_substr($status, 0, 30) : null,
            'logistic_name' => $info['logisticName'] ?? null,
            'tracking_number' => $info['trackNumber'] ?? null,
            'tracking_url' => isset($info['trackingUrl']) ? mb_substr((string) $info['trackingUrl'], 0, 500) : null,
        ], fn ($v) => $v !== null && $v !== ''));
        $supplierOrder->last_synced_at = now();

        switch ($status) {
            case 'SHIPPED':
                if ($supplierOrder->status === SupplierOrder::PAID) {
                    $supplierOrder->fill(['status' => SupplierOrder::SHIPPED, 'shipped_at' => now()]);
                    $supplierOrder->save();
                    $this->recordStep($supplierOrder, 'in_transit', 'Chine', $this->shippedNote($supplierOrder));
                }
                break;
            case 'DELIVERED':
                if (in_array($supplierOrder->status, SupplierOrder::OPEN, true)) {
                    $supplierOrder->fill(['status' => SupplierOrder::DELIVERED, 'delivered_at' => now(), 'shipped_at' => $supplierOrder->shipped_at ?? now()]);
                }
                break;
            case 'CANCELLED':
                if ($supplierOrder->isUnpaid() && $supplierOrder->status !== SupplierOrder::SUBMITTING) {
                    $supplierOrder->save();
                    $this->closeUnpaid($supplierOrder, 'Commande annulée par CJ.');

                    return;
                }
                if (in_array($supplierOrder->status, [...SupplierOrder::OPEN, SupplierOrder::DELIVERED], true)) {
                    $supplierOrder->fill([
                        'status' => SupplierOrder::SUPPLIER_CANCELLED,
                        'cancelled_at' => now(),
                        'last_error' => 'CJ a annulé la commande après paiement : vérifiez que le solde CJ a été recrédité et remboursez le client par une réclamation.',
                    ]);
                }
                break;
        }
        $supplierOrder->save();
    }

    // ------------------------------------------------------------------

    private function create(SupplierOrder $supplierOrder): bool
    {
        $client = $this->driver->client();

        // Une tentative précédente a pu aboutir chez CJ sans que la réponse arrive.
        if ($supplierOrder->submitted_at) {
            try {
                $found = (array) $client->get('shopping/order/getOrderDetail', ['orderId' => $supplierOrder->external_number]);
                if (! empty($found['orderId'])) {
                    $supplierOrder->forceFill(['external_order_id' => (string) $found['orderId'], 'external_status' => $found['orderStatus'] ?? null])->save();

                    return true;
                }
            } catch (CjApiException $e) {
                if ($e->isRetryable()) {
                    throw $e;
                }
            }
        }

        $products = $supplierOrder->items()->get()->map(fn (SupplierOrderItem $i) => [
            'vid' => $i->external_variant_id, 'quantity' => $i->quantity,
        ])->all();
        $line = $this->chooseLine($this->driver->catalog()->freightFor($products), $supplierOrder);
        if ($line === null) {
            $supplierOrder->fail(SupplierOrder::NEEDS_REVIEW, 'Aucun transporteur CJ ne livre ce colis au Cameroun aujourd’hui. Réessayez plus tard ou annulez la commande.');

            return false;
        }
        $supplierOrder->forceFill([
            'submitted_at' => now(),
            'logistic_name' => $line['name'],
            'payload' => array_merge((array) $supplierOrder->payload, ['aging' => $line['aging']]),
        ])->save();

        $address = $this->shippingAddress();
        $data = (array) $client->post('shopping/order/createOrderV2', $address + [
            'orderNumber' => $supplierOrder->external_number,
            'payType' => 3,
            'isSandbox' => $supplierOrder->is_sandbox ? 1 : 0,
            'logisticName' => $line['name'],
            'fromCountryCode' => (string) $this->driver->supplier()->setting('from_country_code', 'CN'),
            'remark' => 'ASSO '.$supplierOrder->external_number,
            'products' => $supplierOrder->items()->get()->map(fn (SupplierOrderItem $i) => [
                'vid' => $i->external_variant_id,
                'quantity' => $i->quantity,
                'storeLineItemId' => (string) $i->order_item_id,
            ])->all(),
        ]);

        if (empty($data['orderId'])) {
            $supplierOrder->fail(SupplierOrder::FAILED, 'CJ n’a pas renvoyé de numéro de commande.');

            return false;
        }
        $supplierOrder->forceFill([
            'external_order_id' => (string) $data['orderId'],
            'external_status' => $data['orderStatus'] ?? null,
            'quoted_cost_usd' => is_numeric($data['orderAmount'] ?? null) ? (float) $data['orderAmount'] : $supplierOrder->quoted_cost_usd,
            'payload' => array_merge((array) $supplierOrder->payload, ['create' => $data]),
        ])->save();

        return true;
    }

    private function confirmCart(SupplierOrder $supplierOrder): bool
    {
        $client = $this->driver->client();
        $ids = ['cjOrderIdList' => [$supplierOrder->external_order_id]];
        try {
            $client->post('shopping/order/addCart', $ids);
        } catch (CjApiException $e) {
            if ($e->isRetryable()) {
                throw $e;
            }
            // Déjà au panier après une tentative interrompue : la confirmation tranchera.
        }
        $data = (array) $client->post('shopping/order/addCartConfirm', $ids);
        if (! empty($data['shipmentsId'])) {
            $supplierOrder->forceFill(['shipment_order_id' => (string) $data['shipmentsId']])->save();

            return true;
        }

        $reasons = collect((array) ($data['interceptOrders'] ?? []))
            ->flatMap(fn ($o) => array_column((array) ($o['interceptReason'] ?? []), 'simpleTip'))
            ->filter()->unique()->values()->all();
        $supplierOrder->fail(SupplierOrder::NEEDS_REVIEW, 'CJ bloque la commande : '.($reasons ? implode(' ; ', $reasons) : 'raison non précisée').'. Corrigez chez CJ puis relancez, ou annulez la commande.');

        return false;
    }

    private function pay(SupplierOrder $supplierOrder, bool $force): bool
    {
        $client = $this->driver->client();
        if (! $supplierOrder->pay_id) {
            $data = (array) $client->post('shopping/order/saveGenerateParentOrder', ['shipmentOrderId' => $supplierOrder->shipment_order_id]);
            if (empty($data['payId'])) {
                $supplierOrder->fail(SupplierOrder::FAILED, 'CJ n’a pas préparé le paiement de la commande.');

                return false;
            }
            $amount = $data['paymentInformation']['actualPayment'] ?? $data['orderMoney'] ?? null;
            $supplierOrder->forceFill([
                'pay_id' => (string) $data['payId'],
                'quoted_cost_usd' => is_numeric($amount) ? (float) $amount : $supplierOrder->quoted_cost_usd,
            ])->save();
        }

        $quote = (float) $supplierOrder->quoted_cost_usd;
        $expected = (float) $supplierOrder->expected_cost_usd;
        $tolerance = (float) $this->driver->supplier()->setting('cost_tolerance_percent', 15);
        if (! $force && $expected > 0 && $quote > $expected * (1 + $tolerance / 100)) {
            $supplierOrder->fail(SupplierOrder::NEEDS_REVIEW, sprintf(
                'Coût CJ de %s USD au lieu de %s USD prévus (+%d %%). Vérifiez, puis cliquez sur « Payer quand même » ou annulez la commande.',
                number_format($quote, 2, ',', ' '), number_format($expected, 2, ',', ' '), (int) round(($quote / $expected - 1) * 100)
            ));

            return false;
        }

        if (! $supplierOrder->is_sandbox) {
            $balance = $this->driver->refreshBalance();
            if ($balance !== null && $balance < $quote) {
                $supplierOrder->fail(SupplierOrder::AWAITING_BALANCE, sprintf(
                    'Solde CJ de %s USD pour une commande de %s USD : rechargez le solde CJ puis relancez l’envoi.',
                    number_format($balance, 2, ',', ' '), number_format($quote, 2, ',', ' ')
                ));

                return false;
            }
        }

        try {
            $client->post('shopping/pay/payBalanceV2', ['shipmentOrderId' => $supplierOrder->shipment_order_id, 'payId' => $supplierOrder->pay_id]);
        } catch (CjApiException $e) {
            if ($e->cjCode === self::INSUFFICIENT_BALANCE) {
                $supplierOrder->fail(SupplierOrder::AWAITING_BALANCE, 'Solde CJ insuffisant : rechargez le solde CJ puis relancez l’envoi.');

                return false;
            }
            if (! $e->isRetryable()) {
                // Le paiement préparé a pu expirer : il sera regénéré au prochain envoi.
                $supplierOrder->forceFill(['pay_id' => null])->save();
            }
            throw $e;
        }

        $supplierOrder->forceFill([
            'status' => SupplierOrder::PAID,
            'paid_at' => now(),
            'expected_hub_at' => $this->expectedHubDate($supplierOrder),
            'paid_cost_usd' => $quote ?: null,
            'external_status' => 'UNSHIPPED',
            'last_error' => null,
        ])->save();
        try {
            $this->driver->refreshBalance();
        } catch (\Throwable) {
            // Le solde affiché sera relevé au prochain passage.
        }

        return true;
    }

    /**
     * Après le paiement CJ, la commande ASSO est confirmée comme par le vendeur
     * (client prévenu, règlement). La course du livreur n'est demandée qu'à
     * l'arrivée du colis à l'entrepôt de Douala.
     */
    private function confirmAssoOrder(SupplierOrder $supplierOrder, Order $order): void
    {
        $order->refresh();
        if ($order->status !== 'pending') {
            return;
        }
        $vendor = User::find(AssoScope::ownerId());
        try {
            if (! $vendor) {
                throw new \RuntimeException('compte plateforme ASSO introuvable');
            }
            app(OrderService::class)->confirmBySeller($order, $vendor, $supplierOrder->approved_by ? 'admin' : 'system', $supplierOrder->approved_by);
        } catch (\Throwable $e) {
            Log::error('[CjOrders] Commande payée chez CJ mais non confirmée dans ASSO', ['supplier_order_id' => $supplierOrder->id, 'error' => $e->getMessage()]);
            $supplierOrder->forceFill(['last_error' => 'Payée chez CJ, mais la commande ASSO n’a pas pu être confirmée : '.$e->getMessage()])->save();
        }
    }

    /** Fin d'une commande non payée chez CJ : suppression chez CJ si possible, client remboursé. */
    private function closeUnpaid(SupplierOrder $supplierOrder, string $reason, ?int $adminId = null): float
    {
        $note = null;
        if ($supplierOrder->external_order_id && $supplierOrder->external_status !== 'CANCELLED') {
            try {
                $this->driver->client()->delete('shopping/order/deleteOrder', ['orderId' => $supplierOrder->external_order_id]);
            } catch (CjApiException $e) {
                // Commande déjà confirmée chez CJ mais jamais payée : elle expire seule.
                $note = 'Commande non supprimée chez CJ (non payée, elle expirera) : '.$e->describe();
            }
        }

        $refunded = 0.0;
        $order = Order::withoutGlobalScopes()->find($supplierOrder->order_id);
        if ($order && $order->status === 'pending') {
            $refunded = app(OrderService::class)->rejectBySeller($order, $reason, $adminId ? 'admin' : 'system', $adminId);
        }
        $supplierOrder->forceFill([
            'status' => SupplierOrder::CANCELLED,
            'cancelled_at' => now(),
            'last_error' => Str::limit(trim($reason.' '.($note ?? '')), 1000),
        ])->save();

        return $refunded;
    }

    /**
     * Étape du parcours montrée au client (une seule fois par étape), au nom
     * d'ASSO : seule ASSO peut enregistrer l'acheminement d'un colis transporteur.
     */
    private function recordStep(SupplierOrder $supplierOrder, string $step, ?string $location, ?string $note, ?int $actorId = null): void
    {
        $order = Order::withoutGlobalScopes()->find($supplierOrder->order_id);
        if (! $order || in_array($order->status, ['pending', 'cancelled', 'delivered'], true)
            || $order->trackingEvents()->where('step', $step)->exists()) {
            return;
        }
        try {
            app(OrderTracking::class)->record($order, $step, $location, $note, 'admin', $actorId ?? $supplierOrder->approved_by, true);
        } catch (\Throwable $e) {
            Log::warning('[CjOrders] Étape de suivi non enregistrée', ['order_id' => $order->id, 'step' => $step, 'error' => $e->getMessage()]);
        }
    }

    private function shippedNote(SupplierOrder $supplierOrder): string
    {
        return trim('Expédié par le fournisseur'.($supplierOrder->logistic_name ? ' ('.$supplierOrder->logistic_name.')' : '')
            .($supplierOrder->tracking_number ? ', suivi '.$supplierOrder->tracking_number : '').'.');
    }

    /** Paiement + préparation CJ + délai maximal du transporteur (« 12-20 » jours). */
    private function expectedHubDate(SupplierOrder $supplierOrder): \Illuminate\Support\Carbon
    {
        $aging = (string) ($supplierOrder->payload['aging'] ?? '');
        if ($aging === '') {
            $aging = (string) optional($supplierOrder->items()->with('supplierVariant.supplierProduct')->first()?->supplierVariant?->supplierProduct)->logistic_aging;
        }
        preg_match_all('/\d+/', $aging, $m);
        $days = $m[0] ? max(array_map('intval', $m[0])) : 30;

        return now()->addDays(min(120, $days + (int) $this->driver->supplier()->setting('supplier_processing_days', 3)));
    }

    /** Transporteur préféré, sinon celui retenu à l'import, sinon le moins cher. */
    private function chooseLine(array $lines, SupplierOrder $supplierOrder): ?array
    {
        if ($lines === []) {
            return null;
        }
        $wanted = array_filter([
            (string) $this->driver->supplier()->setting('preferred_logistic', ''),
            (string) optional($supplierOrder->items()->with('supplierVariant.supplierProduct')->first()?->supplierVariant?->supplierProduct)->logistic_name,
        ]);
        foreach ($wanted as $name) {
            foreach ($lines as $line) {
                if (strcasecmp($line['name'], $name) === 0) {
                    return $line;
                }
            }
        }

        return $lines[0];
    }

    /** Adresse de l'entrepôt ASSO de Douala (réglages, sinon boutique ASSO Import Douala). */
    /**
     * Raison qui empêche d'envoyer une commande à CJ faute d'adresse de
     * livraison exploitable (le transporteur et la douane joignent l'entrepôt
     * par téléphone), ou null si l'adresse est complète.
     */
    public function shippingAddressProblem(): ?string
    {
        $address = $this->shippingAddress();
        $missing = array_keys(array_filter([
            'téléphone' => trim($address['shippingPhone']) === '',
            'adresse' => trim($address['shippingAddress']) === '' || $address['shippingAddress'] === ImportHub::CITY,
            'destinataire' => trim($address['shippingCustomerName']) === '',
        ]));

        return $missing
            ? 'Adresse de l’entrepôt de Douala incomplète ('.implode(', ', $missing).') : remplissez « Livraison des colis CJ » dans l’onglet Commandes CJ, puis relancez l’envoi.'
            : null;
    }

    /** Adresse de livraison envoyée à CJ (entrepôt ASSO de Douala). */
    public function shippingAddress(): array
    {
        $supplier = $this->driver->supplier();
        $hub = ImportHub::shop();

        return [
            'shippingCountryCode' => (string) $supplier->setting('to_country_code', 'CM'),
            'shippingCountry' => 'Cameroon',
            'shippingProvince' => (string) $supplier->setting('ship_to_province', 'Littoral'),
            'shippingCity' => (string) ($supplier->setting('ship_to_city') ?: ($hub?->city ?: ImportHub::CITY)),
            'shippingAddress' => Str::limit((string) ($supplier->setting('ship_to_address') ?: trim(implode(', ', array_filter([$hub?->address, $hub?->location_label]))) ?: ImportHub::CITY), 500, ''),
            'shippingCustomerName' => Str::limit((string) ($supplier->setting('ship_to_name') ?: ImportHub::NAME), 50, ''),
            'shippingPhone' => Str::limit((string) ($supplier->setting('ship_to_phone') ?: ($hub?->phone ?? '')), 20, ''),
            'shippingZip' => (string) ($supplier->setting('ship_to_zip') ?? ''),
        ];
    }

    private function supplierVariantFor(OrderItem $item): ?SupplierVariant
    {
        $base = SupplierVariant::where('is_active', true)
            ->whereHas('supplierProduct', fn ($q) => $q->where('supplier_id', $this->driver->supplier()->id)->where('product_id', $item->product_id))
            ->with('supplierProduct');

        return $item->product_variant_id
            ? $base->where('product_variant_id', $item->product_variant_id)->first()
            : $base->whereNull('product_variant_id')->first();
    }

    private function isSupplierProduct(OrderItem $item): bool
    {
        return \App\Models\SupplierProduct::where('supplier_id', $this->driver->supplier()->id)->where('product_id', $item->product_id)->exists();
    }
}
