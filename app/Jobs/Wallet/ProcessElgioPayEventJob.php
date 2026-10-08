<?php

namespace App\Jobs\Wallet;

use App\Http\Controllers\Api\DiaspoController;
use App\Models\DiaspoBooking;
use App\Models\Order;
use App\Models\PackageSubscription;
use App\Models\PlatformWithdrawal;
use App\Models\WalletTransaction;
use App\Models\DisputeShipment;
use App\Services\DepositOrderService;
use App\Services\DisputeService;
use App\Services\ElgioPayService;
use App\Services\MobileMoneyGateway;
use App\Services\OrderService;
use App\Services\PackageSubscriptionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Traite un webhook ElgioPay (file `deposits` ou `withdrawals`).
 *
 * Retrouve l'objet métier via la référence que NOUS avons transmise à
 * l'initiation (WALLET-/WITHDRAW-/DIASPO-/SUB-/numéro de commande), puis le
 * finalise en relisant le statut auprès de l'API avec la référence STOCKÉE de cet
 * objet — jamais à partir du corps du webhook. Toutes les finalisations sont
 * idempotentes (verrous de ligne), donc webhook, polling mobile et scheduler
 * peuvent se croiser sans double crédit.
 */
class ProcessElgioPayEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;
    public $timeout = 90;

    public function __construct(
        public string $kind,           // 'payment' | 'payout'
        public ?string $elgiopayId,    // transaction_id / payout_id ElgioPay (sans préfixe)
        public ?string $reference = null, // notre référence (si fournie par le webhook)
    ) {}

    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function handle(ElgioPayService $elgiopay, MobileMoneyGateway $gateway): void
    {
        $reference = $this->reference;

        // Référence absente du webhook : on la relit chez ElgioPay.
        if (!$reference && $this->elgiopayId) {
            $status = $this->kind === 'payout'
                ? $elgiopay->checkDisbursementStatus($this->elgiopayId)
                : $elgiopay->checkPaymentStatus($this->elgiopayId);
            if (($status['status'] ?? null) === 'ERROR') {
                throw new \RuntimeException('ElgioPay indisponible : ' . ($status['message'] ?? ''));
            }
            $reference = $status['external_reference'] ?? null;
        }

        Log::info('[ElgioPay event] Traitement', [
            'kind' => $this->kind,
            'id' => $this->elgiopayId,
            'reference' => $reference,
            'attempt' => $this->attempts(),
        ]);

        if ($this->kind === 'payout') {
            $this->handlePayout($reference);
            return;
        }

        // En pratique ElgioPay ne renvoie PAS notre référence (ni à la création,
        // ni au statut, ni dans le webhook) : on retrouve l'objet par l'id stocké.
        if (!$reference && $this->elgiopayId) {
            $reference = $this->referenceFromStoredId(MobileMoneyGateway::ELGIOPAY_PREFIX . $this->elgiopayId);
        }

        $this->handlePayment((string) $reference, $gateway);
    }

    private function handlePayout(?string $reference): void
    {
        $withdrawalId = null;
        if ($reference && str_starts_with($reference, 'WITHDRAW-')) {
            $withdrawalId = (int) substr($reference, strlen('WITHDRAW-'));
        } elseif ($this->elgiopayId) {
            $withdrawalId = PlatformWithdrawal::where('kpay_reference', MobileMoneyGateway::ELGIOPAY_PREFIX . $this->elgiopayId)->value('id');
        }

        if (!$withdrawalId) {
            Log::warning('[ElgioPay event] Retrait introuvable', ['id' => $this->elgiopayId, 'reference' => $reference]);
            return;
        }

        ProcessWithdrawalStatusJob::dispatchSync($withdrawalId);
    }

    private function handlePayment(string $reference, MobileMoneyGateway $gateway): void
    {
        if (str_starts_with($reference, 'WALLET-')) {
            ProcessDepositStatusJob::dispatchSync((int) substr($reference, strlen('WALLET-')));
            return;
        }

        if (str_starts_with($reference, 'DIASPO-')) {
            $booking = DiaspoBooking::find((int) substr($reference, strlen('DIASPO-')));
            if ($booking && $booking->payment_status === 'pending' && $booking->payment_reference) {
                $status = $this->authoritativeStatus($gateway, $booking->payment_reference);
                if (in_array($status, ['COMPLETED', 'SUCCESS', 'SUCCESSFUL'], true)) {
                    app(DiaspoController::class)->confirmBookingPayment($booking);
                } elseif (in_array($status, ['FAILED', 'CANCELLED'], true)) {
                    app(DiaspoController::class)->failBookingPayment($booking);
                }
            }
            return;
        }

        if (str_starts_with($reference, 'SUB-')) {
            $subscription = PackageSubscription::find((int) substr($reference, strlen('SUB-')));
            if ($subscription) {
                app(PackageSubscriptionService::class)->checkAndConfirm($subscription);
            }
            return;
        }

        // Course d'un litige payée par le vendeur (LITIGE-{shipmentId}).
        if ($shipment = DisputeService::shipmentForKpayReference($reference)) {
            if (!$shipment->isPaid() && $shipment->payment_mode === 'kpay_direct' && $shipment->payment_reference) {
                $status = $this->authoritativeStatus($gateway, $shipment->payment_reference);
                $disputes = app(DisputeService::class);
                if (in_array($status, ['COMPLETED', 'SUCCESS', 'SUCCESSFUL'], true)) {
                    $disputes->confirmPayment($shipment);
                } elseif (in_array($status, ['FAILED', 'CANCELLED'], true)) {
                    $disputes->failPayment($shipment);
                }
            }
            return;
        }

        // Solde d'une commande avec acompte ({order_number}-SOLDE).
        if ($balanceOrder = DepositOrderService::orderForBalanceReference($reference)) {
            if ($balanceOrder->balance_status === Order::BALANCE_UNLOCKED
                && $balanceOrder->balance_payment_method === 'kpay_direct'
                && $balanceOrder->balance_payment_reference) {
                $status = $this->authoritativeStatus($gateway, $balanceOrder->balance_payment_reference);
                $deposits = app(DepositOrderService::class);
                if (in_array($status, ['COMPLETED', 'SUCCESS', 'SUCCESSFUL'], true)) {
                    $deposits->confirmBalancePayment($balanceOrder);
                } elseif (in_array($status, ['FAILED', 'CANCELLED'], true)) {
                    $deposits->failBalancePayment($balanceOrder);
                }
            }
            return;
        }

        $order = $reference !== ''
            ? Order::where('order_number', $reference)->where('payment_method', 'kpay_direct')->first()
            : null;
        if ($order) {
            if ($order->payment_status === 'pending' && $order->payment_reference) {
                $status = $this->authoritativeStatus($gateway, $order->payment_reference);
                if (in_array($status, ['COMPLETED', 'SUCCESS', 'SUCCESSFUL'], true)) {
                    app(OrderService::class)->confirmKpayOrderPayment($order);
                } elseif (in_array($status, ['FAILED', 'CANCELLED'], true)) {
                    app(OrderService::class)->failKpayOrderPayment($order);
                }
            }
            return;
        }

        Log::warning('[ElgioPay event] Paiement sans objet correspondant', ['id' => $this->elgiopayId, 'reference' => $reference]);
    }

    /** Reconstitue notre référence à partir de l'id ElgioPay stocké à l'initiation. */
    private function referenceFromStoredId(string $stored): ?string
    {
        if ($id = WalletTransaction::where('type', 'credit')->where('metadata->provider_reference', $stored)->value('id')) {
            return "WALLET-{$id}";
        }
        if ($id = DiaspoBooking::where('payment_reference', $stored)->value('id')) {
            return "DIASPO-{$id}";
        }
        if ($id = PackageSubscription::where('payment_reference', $stored)->value('id')) {
            return "SUB-{$id}";
        }
        if ($id = DisputeShipment::where('payment_reference', $stored)->value('id')) {
            return DisputeService::KPAY_PREFIX . $id;
        }
        if ($balanceOrder = Order::where('balance_payment_reference', $stored)->first()) {
            return DepositOrderService::balanceReference($balanceOrder);
        }
        return Order::where('payment_reference', $stored)->value('order_number');
    }

    /** Statut relu chez le prestataire ; une indisponibilité relance le job (retry). */
    private function authoritativeStatus(MobileMoneyGateway $gateway, string $storedReference): string
    {
        $status = strtoupper($gateway->checkPaymentStatus($storedReference)['status'] ?? 'UNKNOWN');
        if ($status === 'ERROR') {
            throw new \RuntimeException("Statut indisponible pour {$storedReference}");
        }
        return $status;
    }

    public function failed(\Throwable $e): void
    {
        // Le scheduler (CheckPendingDeposits/Withdrawals) et le polling mobile
        // reprendront la transaction : aucun état n'est perdu.
        Log::error('[ElgioPay event] Abandon après retries', [
            'kind' => $this->kind,
            'id' => $this->elgiopayId,
            'reference' => $this->reference,
            'error' => $e->getMessage(),
        ]);
    }
}
