<?php

namespace App\Services\Suppliers\Cj;

use App\Models\SupplierDispute;
use App\Models\SupplierOrder;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Litiges CJ (colis perdu, abîmé, non conforme…) pour une commande payée.
 * CJ ne renvoie pas l'identifiant du litige à la création : il est retrouvé
 * dans la liste des litiges de la commande, puis relu périodiquement.
 */
class CjDisputes
{
    public function __construct(private CjDriver $driver) {}

    /**
     * Données du formulaire : lignes de la commande ouvrables en litige, motifs
     * proposés par CJ, résultats possibles et montant maximal remboursable.
     *
     * @return array{products: list<array>, reasons: list<array{id: int, name: string}>, expect: list<int>, max_amount: ?float}
     */
    public function prepare(SupplierOrder $supplierOrder): array
    {
        $this->ensureDisputable($supplierOrder);
        $client = $this->driver->client();
        $data = (array) $client->get('disputes/disputeProducts', ['orderId' => $supplierOrder->external_order_id]);
        $products = array_values(array_filter((array) ($data['productInfoList'] ?? []), fn ($p) => ($p['canChoose'] ?? true) !== false));
        if ($products === []) {
            return ['products' => [], 'reasons' => [], 'expect' => [], 'max_amount' => null];
        }

        $confirm = (array) $client->post('disputes/disputeConfirmInfo', [
            'orderId' => $supplierOrder->external_order_id,
            'productInfoList' => array_map(fn ($p) => [
                'lineItemId' => (string) $p['lineItemId'], 'quantity' => (int) ($p['quantity'] ?? 1), 'price' => (float) ($p['price'] ?? 0),
            ], $products),
        ]);

        return [
            'products' => $products,
            'reasons' => array_values(array_map(fn ($r) => ['id' => (int) $r['disputeReasonId'], 'name' => (string) ($r['reasonName'] ?? $r['disputeReasonId'])],
                array_filter((array) ($confirm['disputeReasonList'] ?? []), fn ($r) => isset($r['disputeReasonId'])))),
            'expect' => array_values(array_map('intval', (array) ($confirm['expectResultOptionList'] ?? [SupplierDispute::EXPECT_REFUND]))),
            'max_amount' => is_numeric($confirm['maxAmount'] ?? null) ? (float) $confirm['maxAmount'] : null,
        ];
    }

    /**
     * Ouvre le litige chez CJ.
     *
     * @param  array<string, int>  $quantities  quantité par lineItemId CJ
     */
    public function open(SupplierOrder $supplierOrder, int $reasonId, int $expectType, string $message, array $quantities, User $admin): SupplierDispute
    {
        $form = $this->prepare($supplierOrder);
        $reason = collect($form['reasons'])->firstWhere('id', $reasonId);
        if (! $reason) {
            throw new \InvalidArgumentException('Motif de litige invalide pour cette commande.');
        }
        if (! in_array($expectType, $form['expect'] ?: [SupplierDispute::EXPECT_REFUND], true)) {
            throw new \InvalidArgumentException('Résultat attendu non proposé par CJ pour cette commande.');
        }
        $lines = [];
        foreach ($form['products'] as $product) {
            $qty = (int) ($quantities[(string) $product['lineItemId']] ?? 0);
            if ($qty > 0) {
                $lines[] = [
                    'lineItemId' => (string) $product['lineItemId'],
                    'quantity' => min($qty, (int) ($product['quantity'] ?? $qty)),
                    'price' => (float) ($product['price'] ?? 0),
                    'name' => (string) ($product['cjProductName'] ?? $product['sku'] ?? ''),
                ];
            }
        }
        if ($lines === []) {
            throw new \InvalidArgumentException('Choisissez au moins un article et sa quantité.');
        }

        $dispute = SupplierDispute::create([
            'supplier_order_id' => $supplierOrder->id,
            'business_id' => 'ASSO-D'.Str::upper(Str::random(10)),
            'reason_id' => $reasonId,
            'reason_name' => $reason['name'],
            'expect_type' => $expectType,
            'message' => $message,
            'lines' => $lines,
            'amount_usd' => round(array_sum(array_map(fn ($l) => $l['price'] * $l['quantity'], $lines)), 2),
            'opened_by' => $admin->id,
        ]);

        try {
            $this->driver->client()->post('disputes/create', [
                'orderId' => $supplierOrder->external_order_id,
                'businessDisputeId' => $dispute->business_id,
                'disputeReasonId' => $reasonId,
                'expectType' => $expectType,
                'refundType' => 1, // remboursement sur le solde CJ
                'messageText' => Str::limit($message, 500, ''),
                'imageUrl' => [],
                'productInfoList' => array_map(fn ($l) => ['lineItemId' => $l['lineItemId'], 'quantity' => $l['quantity'], 'price' => $l['price']], $lines),
            ]);
        } catch (CjApiException $e) {
            $dispute->delete();
            throw $e;
        }

        try {
            $this->sync($dispute);
        } catch (CjApiException) {
            // L'identifiant CJ sera retrouvé au prochain passage de cj:sync-orders.
        }

        return $dispute->refresh();
    }

    /** Relit l'état du litige chez CJ (et retrouve son identifiant si besoin). */
    public function sync(SupplierDispute $dispute): void
    {
        $supplierOrder = $dispute->supplierOrder;
        $data = (array) $this->driver->client()->get('disputes/getDisputeList', ['orderId' => $supplierOrder->external_order_id, 'pageSize' => 50]);
        $list = collect((array) ($data['list'] ?? []));

        $row = $dispute->external_dispute_id
            ? $list->firstWhere('disputeId', $dispute->external_dispute_id)
            : $list->reject(fn ($r) => SupplierDispute::where('external_dispute_id', (string) ($r['disputeId'] ?? ''))->exists())
                ->sortByDesc(fn ($r) => (int) ($r['createDate'] ?? 0))->first();

        $dispute->last_synced_at = now();
        if ($row) {
            $final = is_numeric($row['finallyDeal'] ?? null) ? (int) $row['finallyDeal'] : null;
            $dispute->fill([
                'external_dispute_id' => (string) $row['disputeId'],
                'status' => isset($row['status']) ? mb_substr((string) $row['status'], 0, 50) : $dispute->status,
                'final_result' => $final,
                'refund_usd' => is_numeric($row['refundMoney'] ?? null) ? (float) $row['refundMoney'] : $dispute->refund_usd,
                'resend_order_id' => ($row['resendOrderCode'] ?? '') !== '' ? (string) $row['resendOrderCode'] : $dispute->resend_order_id,
                'closed_at' => $final !== null ? ($dispute->closed_at ?? now()) : null,
            ]);
        }
        $dispute->save();
    }

    private function ensureDisputable(SupplierOrder $supplierOrder): void
    {
        if (! $supplierOrder->external_order_id || ! in_array($supplierOrder->status, SupplierOrder::DISPUTABLE, true)) {
            throw new \InvalidArgumentException('Un litige CJ ne s’ouvre que sur une commande payée chez CJ.');
        }
    }
}
