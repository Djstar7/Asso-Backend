<?php

namespace App\Models;

use App\Services\ExchangeRateService;
use App\Services\MobileMoneyGateway;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Frais prélevés par le prestataire sur une opération aboutie.
 *
 * KPay (documentation « Paiements » / « Retraits ») :
 *  - encaissement : ASSO reçoit `netAmount` = `amount` − `feeAmount` → charge ASSO ;
 *  - retrait : le bénéficiaire reçoit `netAmount` → frais à la charge du bénéficiaire.
 *
 * ElgioPay (GET /api/v1/payments/{id}) :
 *  - encaissement : ASSO reçoit `amount.net_amount` = `amount.total` − `amount.fees` → charge ASSO ;
 *  - retrait : la réponse ne porte aucun frais → rien n'est enregistré.
 */
class PaymentGatewayFee extends Model
{
    public const PAYIN = 'payin';
    public const PAYOUT = 'payout';

    protected $fillable = [
        'gateway', 'direction', 'gateway_id', 'external_id',
        'amount', 'net_amount', 'fee_amount', 'currency', 'fee_amount_xaf',
        'provider', 'completed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'fee_amount' => 'decimal:2',
        'fee_amount_xaf' => 'decimal:2',
        'completed_at' => 'datetime',
    ];

    /**
     * Enregistre les frais d'un objet Payment / Withdrawal KPay abouti (idempotent).
     * Ne lève jamais : un échec ne doit pas bloquer le traitement du paiement.
     */
    public static function captureKpay(array $data, string $direction): ?self
    {
        if (strtoupper((string) ($data['status'] ?? '')) !== 'COMPLETED') {
            return null;
        }

        return self::record('kpay', $direction, $data['id'] ?? null, [
            'amount' => $data['amount'] ?? null,
            'net' => $data['netAmount'] ?? null,
            'fee' => $data['feeAmount'] ?? null,
            'currency' => $data['currency'] ?? null,
            'external_id' => $data['externalId'] ?? null,
            'provider' => $data['provider'] ?? null,
            'completed_at' => $data['completedAt'] ?? null,
        ]);
    }

    /**
     * Enregistre les frais d'un encaissement ElgioPay abouti (idempotent), lus sur
     * GET /api/v1/payments/{id} : `amount.total` (payé par le client),
     * `amount.fees` (prélevés par ElgioPay), `amount.net_amount` (reçu par ASSO).
     * Corps plat (doc) ou enveloppé dans `data` (SDK) acceptés.
     *
     * L'identifiant est enregistré préfixé (`elgiopay:<id>`), comme les références
     * stockées par l'app. Les retraits ElgioPay ne renvoient aucun frais : rien
     * n'est enregistré pour eux.
     */
    public static function captureElgioPay(array $data): ?self
    {
        $body = is_array($data['data'] ?? null) && isset($data['data']['transaction_id']) ? $data['data'] : $data;
        if (strtolower((string) ($body['status'] ?? '')) !== 'completed' || empty($body['transaction_id'])) {
            return null;
        }

        $amount = is_array($body['amount'] ?? null) ? $body['amount'] : ['total' => $body['amount'] ?? null];

        return self::record('elgiopay', self::PAYIN, MobileMoneyGateway::ELGIOPAY_PREFIX . $body['transaction_id'], [
            'amount' => $amount['total'] ?? null,
            'net' => $amount['net_amount'] ?? null,
            'fee' => $amount['fees'] ?? null,
            'currency' => $amount['currency'] ?? $body['currency'] ?? null,
            'external_id' => $body['reference'] ?? $body['metadata']['order_id'] ?? null,
            'provider' => $body['payment']['method'] ?? null,
            'completed_at' => $body['completed_at'] ?? null,
        ]);
    }

    /**
     * Persistance commune : les frais déclarés font foi ; à défaut, l'écart
     * brut/net. Sans l'un ni l'autre, rien n'est enregistré.
     */
    private static function record(string $gateway, string $direction, $gatewayId, array $f): ?self
    {
        try {
            if (!$gatewayId || !is_numeric($f['amount'])) {
                return null;
            }

            $amount = (float) $f['amount'];
            $net = is_numeric($f['net']) ? (float) $f['net'] : null;
            $fee = is_numeric($f['fee'])
                ? (float) $f['fee']
                : ($net !== null ? max(0.0, $amount - $net) : null);
            if ($fee === null) {
                return null;
            }

            $currency = strtoupper((string) ($f['currency'] ?: 'XAF'));
            $feeXaf = $currency === 'XAF'
                ? $fee
                : ExchangeRateService::convertAmount($currency, 'XAF', $fee);

            return self::updateOrCreate(
                ['gateway' => $gateway, 'gateway_id' => (string) $gatewayId],
                [
                    'direction' => $direction,
                    'external_id' => $f['external_id'],
                    'amount' => $amount,
                    'net_amount' => $net,
                    'fee_amount' => $fee,
                    'currency' => $currency,
                    'fee_amount_xaf' => $feeXaf !== null ? round($feeXaf, 2) : null,
                    'provider' => $f['provider'],
                    'completed_at' => !empty($f['completed_at']) ? Carbon::parse($f['completed_at']) : now(),
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('[PaymentGatewayFee] Frais non enregistrés', ['gateway' => $gateway, 'id' => $gatewayId, 'error' => $e->getMessage()]);

            return null;
        }
    }

    public static function knows(string $gateway, string $gatewayId): bool
    {
        return self::where('gateway', $gateway)->where('gateway_id', $gatewayId)->exists();
    }
}
