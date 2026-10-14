<?php

namespace App\Models;

use App\Services\ExchangeRateService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Frais prélevés par le prestataire sur une opération aboutie.
 *
 * KPay (documentation « Paiements » / « Retraits ») :
 *  - encaissement : ASSO reçoit `netAmount` = `amount` − `feeAmount` → charge ASSO ;
 *  - retrait : le bénéficiaire reçoit `netAmount` → frais à la charge du bénéficiaire.
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
        try {
            $id = $data['id'] ?? null;
            if (!$id || strtoupper((string) ($data['status'] ?? '')) !== 'COMPLETED' || !is_numeric($data['amount'] ?? null)) {
                return null;
            }

            $amount = (float) $data['amount'];
            $net = is_numeric($data['netAmount'] ?? null) ? (float) $data['netAmount'] : null;
            // feeAmount fait foi ; à défaut, l'écart brut/net documenté.
            $fee = is_numeric($data['feeAmount'] ?? null)
                ? (float) $data['feeAmount']
                : ($net !== null ? max(0.0, $amount - $net) : null);
            if ($fee === null) {
                return null;
            }

            $currency = strtoupper((string) ($data['currency'] ?? 'XAF'));
            $feeXaf = $currency === 'XAF'
                ? $fee
                : ExchangeRateService::convertAmount($currency, 'XAF', $fee);

            return self::updateOrCreate(
                ['gateway' => 'kpay', 'gateway_id' => (string) $id],
                [
                    'direction' => $direction,
                    'external_id' => $data['externalId'] ?? null,
                    'amount' => $amount,
                    'net_amount' => $net,
                    'fee_amount' => $fee,
                    'currency' => $currency,
                    'fee_amount_xaf' => $feeXaf !== null ? round($feeXaf, 2) : null,
                    'provider' => $data['provider'] ?? null,
                    'completed_at' => !empty($data['completedAt']) ? Carbon::parse($data['completedAt']) : now(),
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('[PaymentGatewayFee] Frais KPay non enregistrés', ['id' => $data['id'] ?? null, 'error' => $e->getMessage()]);

            return null;
        }
    }

    public static function knows(string $gateway, string $gatewayId): bool
    {
        return self::where('gateway', $gateway)->where('gateway_id', $gatewayId)->exists();
    }
}
