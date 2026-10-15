<?php

namespace App\Console\Commands;

use App\Models\PaymentGatewayFee;
use App\Services\KPayService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rattrapage des frais KPay : relit chez KPay (GET /payments/:id et
 * /payments/withdraw/:id) chaque opération connue dont les frais ne sont pas
 * encore enregistrés. Les frais sont capturés par KPayService lors de la lecture.
 */
class SyncKpayFees extends Command
{
    protected $signature = 'kpay:sync-fees {--days=30 : Ancienneté maximale des opérations (0 = toutes)}';

    protected $description = 'Enregistre les frais KPay réellement prélevés sur les encaissements et retraits aboutis';

    /** Frais repris de réponses KPay déjà conservées (sans appel). */
    private int $stored = 0;

    public function handle(KPayService $kpay): int
    {
        $since = (int) $this->option('days') > 0 ? now()->subDays((int) $this->option('days')) : null;
        $references = $this->references($since);

        // Clés suffisantes (pas besoin que KPay soit le rail actif) : les opérations
        // passées restent lisibles après une bascule vers ElgioPay.
        if (!$kpay->hasCredentials()) {
            $this->warn("Clés KPay absentes : {$this->stored} frais repris des réponses conservées, aucun appel.");

            return self::SUCCESS;
        }

        $known = PaymentGatewayFee::where('gateway', 'kpay')->pluck('gateway_id')->flip();

        $captured = 0;
        foreach ($references as $reference => $direction) {
            if (isset($known[$reference])) {
                continue;
            }
            $result = $direction === PaymentGatewayFee::PAYOUT
                ? $kpay->checkDisbursementStatus($reference)
                : $kpay->checkPaymentStatus($reference);
            if (strtoupper($result['status'] ?? '') === 'COMPLETED' && PaymentGatewayFee::knows('kpay', $reference)) {
                $captured++;
            }
        }

        $this->info("Frais KPay enregistrés : {$captured} relus chez KPay, {$this->stored} repris des réponses conservées");

        return self::SUCCESS;
    }

    /**
     * Identifiants KPay des opérations connues de l'app => sens (payin | payout).
     * KPay renvoie des UUID (la documentation montre pay_xxx / wdr_xxx) ; les
     * références des autres rails (Stripe cs_/pi_, ElgioPay préfixé…) sont écartées.
     */
    private function references($since): array
    {
        $sources = [
            ['orders', 'payment_reference', PaymentGatewayFee::PAYIN],
            ['orders', 'balance_payment_reference', PaymentGatewayFee::PAYIN],
            ['package_subscriptions', 'payment_reference', PaymentGatewayFee::PAYIN],
            ['diaspo_bookings', 'payment_reference', PaymentGatewayFee::PAYIN],
            ['dispute_shipments', 'payment_reference', PaymentGatewayFee::PAYIN],
            ['platform_withdrawals', 'kpay_reference', PaymentGatewayFee::PAYOUT],
        ];

        $refs = [];
        foreach ($sources as [$table, $column, $direction]) {
            if (!Schema::hasColumn($table, $column)) {
                continue;
            }
            DB::table($table)
                ->whereNotNull($column)
                ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
                ->pluck($column)
                ->each(function ($ref) use (&$refs, $direction) {
                    if (self::isKpayId($ref)) {
                        $refs[$ref] = $direction;
                    }
                });
        }

        // Recharges Wallet : la réponse authentifiée de KPay est conservée dans
        // metadata.kpay_response ; sinon l'identifiant (provider_reference) est relu.
        DB::table('wallet_transactions')
            ->where('provider', 'kpay')
            ->whereNotNull('metadata')
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->pluck('metadata')
            ->each(function ($m) use (&$refs) {
                $meta = json_decode((string) $m, true) ?: [];
                $stored = $meta['kpay_response']['data'] ?? null;
                if (is_array($stored) && PaymentGatewayFee::captureKpay($stored, PaymentGatewayFee::PAYIN)) {
                    $this->stored++;
                    return;
                }
                $ref = $meta['provider_reference'] ?? null;
                if (self::isKpayId($ref)) {
                    $refs[$ref] ??= PaymentGatewayFee::PAYIN;
                }
            });

        return $refs;
    }

    private static function isKpayId($ref): bool
    {
        return is_string($ref) && (
            preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $ref) === 1
            || str_starts_with($ref, 'pay_')
            || str_starts_with($ref, 'wdr_')
        );
    }
}
