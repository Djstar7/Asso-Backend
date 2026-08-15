<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Transaction;
use App\Services\KPayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    /**
     * Initialize payment for an order
     */
    public function initiate(Request $request)
    {
        $request->validate([
            'order_id' => 'required|exists:orders,id',
            'payment_method' => 'required|in:kpay,mobile,paypal,cash',
            // provider = code opérateur KPay (ex. MTN_MOMO_CMR) — détermine pays et devise
            'provider' => 'required_if:payment_method,kpay,mobile|string',
            'phone_number' => 'required_if:payment_method,kpay,mobile|string',
        ]);

        $order = Order::where('user_id', $request->user()->id)
            ->where('payment_status', 'pending')
            ->findOrFail($request->order_id);

        if ($request->payment_method === 'kpay' || $request->payment_method === 'mobile') {
            $kpay = new KPayService();
            $result = $kpay->initializePayment([
                'amount' => (int) $order->total,
                'provider' => $request->provider,
                'phone_number' => $request->phone_number,
                'description' => "Commande {$order->order_number}",
                'external_reference' => $order->order_number,
            ]);

            if ($result['success']) {
                // Create transaction record — external_reference = externalId (order_number)
                // pour retrouver la transaction depuis le webhook KPay ; l'id KPay
                // (pour le polling) est conservé dans metadata.
                $transaction = Transaction::create([
                    'reference' => 'TXN' . strtoupper(substr(md5(uniqid()), 0, 10)),
                    'buyer_id' => $request->user()->id,
                    'amount' => $order->total,
                    'currency' => 'XAF',
                    'status' => 'pending',
                    'type' => 'purchase',
                    'payment_method' => 'mobile',
                    'external_reference' => $order->order_number,
                    'description' => "Paiement commande {$order->order_number}",
                    'metadata' => ['order_id' => $order->id, 'kpay_id' => $result['id'], 'kpay_reference' => $result['reference'], 'kpay_data' => $result['data']],
                    'payer_name' => $request->user()->name,
                ]);

                $order->update([
                    'payment_method' => 'mobile',
                    'payment_reference' => $result['reference'] ?? $transaction->reference,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Paiement initié. Veuillez valider sur votre téléphone.',
                    'payment_reference' => $result['reference'],
                    'kpay_id' => $result['id'],
                    'transaction_id' => $transaction->id,
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], 422);
        }

        if ($request->payment_method === 'cash') {
            $order->update([
                'payment_method' => 'cash',
                'payment_status' => 'pending',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Commande confirmée. Paiement à la livraison.',
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Méthode de paiement non supportée',
        ], 422);
    }

    /**
     * Check payment status
     */
    public function status(Request $request, $reference)
    {
        // $reference = id KPay (pay_xxx) conservé côté client (metadata.kpay_id)
        $kpay = new KPayService();
        $result = $kpay->checkPaymentStatus($reference);

        return response()->json([
            'success' => true,
            'payment_status' => $result['status'],
            'data' => $result['data'],
        ]);
    }

    /**
     * KPay webhook callback (deposits).
     * Signature HMAC-SHA256 (hex) sur le corps BRUT, en-tête X-KPAY-Signature.
     */
    public function webhookKpay(Request $request)
    {
        $rawBody = $request->getContent();
        $signature = $request->header('X-KPAY-Signature');

        if (!KPayService::verifyWebhookSignature($rawBody, $signature)) {
            Log::warning('KPay webhook: signature invalide', ['event' => $request->header('X-KPAY-Event')]);
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        Log::info('KPay webhook received', $request->all());

        // externalId permet de retrouver la transaction (idempotence) ; status terminal.
        $externalId = $request->input('externalId');
        $status = $request->input('status'); // COMPLETED | FAILED | CANCELLED

        if (!$externalId || !$status) {
            return response()->json(['message' => 'Invalid payload'], 400);
        }

        // Recharge wallet (externalId = WALLET-{walletTransactionId}).
        // Traitement SYNCHRONE : la finalisation re-vérifie le statut directement
        // auprès de KPay (source d'autorité) — fonctionne sans worker de queue et
        // ne peut PAS être falsifié par un faux webhook (le crédit dépend de la
        // réponse authentifiée de KPay, pas du corps du webhook).
        if (str_starts_with($externalId, 'WALLET-')) {
            $walletTxId = (int) substr($externalId, strlen('WALLET-'));
            if ($walletTxId > 0) {
                \App\Jobs\Wallet\ProcessDepositStatusJob::dispatchSync($walletTxId);
            }
            return response()->json(['message' => 'Deposit webhook processed']);
        }

        // Retrait wallet (externalId = WITHDRAW-{withdrawalId})
        if (str_starts_with($externalId, 'WITHDRAW-')) {
            $withdrawalId = (int) substr($externalId, strlen('WITHDRAW-'));
            if ($withdrawalId > 0) {
                \App\Jobs\Wallet\ProcessWithdrawalStatusJob::dispatchSync($withdrawalId);
            }
            return response()->json(['message' => 'Withdrawal webhook processed']);
        }

        // Réservation diaspo payée en direct KPay (externalId = DIASPO-{bookingId})
        if (str_starts_with($externalId, 'DIASPO-')) {
            $bookingId = (int) substr($externalId, strlen('DIASPO-'));
            $booking = \App\Models\DiaspoBooking::find($bookingId);
            if ($booking) {
                if (in_array($status, ['COMPLETED', 'SUCCESS', 'SUCCESSFUL'])) {
                    app(DiaspoController::class)->confirmBookingPayment($booking);
                } elseif (in_array($status, ['FAILED', 'CANCELLED'])) {
                    // Idempotent + valeurs d'enum valides (payment_status n'a pas de 'failed').
                    app(DiaspoController::class)->failBookingPayment($booking);
                }
            }
            return response()->json(['message' => 'Diaspo booking webhook processed']);
        }

        // Commande payée en direct KPay (externalId = order_number)
        $directOrder = Order::where('order_number', $externalId)
            ->where('payment_method', 'kpay_direct')
            ->first();
        if ($directOrder) {
            if (in_array($status, ['COMPLETED', 'SUCCESS', 'SUCCESSFUL'])) {
                app(\App\Services\OrderService::class)->confirmKpayOrderPayment($directOrder);
            } elseif (in_array($status, ['FAILED', 'CANCELLED'])) {
                app(\App\Services\OrderService::class)->failKpayOrderPayment($directOrder);
            }
            return response()->json(['message' => 'Order payment webhook processed']);
        }

        // Sinon : paiement de commande (Transaction)
        $transaction = Transaction::where('external_reference', $externalId)->first();

        if (!$transaction) {
            Log::warning('KPay webhook: transaction not found', ['externalId' => $externalId]);
            return response()->json(['message' => 'Transaction not found'], 404);
        }

        // Idempotence : ne pas retraiter une transaction déjà finalisée.
        if (in_array($transaction->status, ['completed', 'cancelled'])) {
            return response()->json(['message' => 'Already processed']);
        }

        $orderId = $transaction->metadata['order_id'] ?? null;

        if ($status === 'COMPLETED') {
            $transaction->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            if ($orderId) {
                $order = Order::with('items')->find($orderId);
                if ($order) {
                    $order->update([
                        'payment_status' => 'paid',
                        'status' => 'confirmed',
                        'confirmed_at' => now(),
                    ]);

                    // Add to sellers' pending_earnings (money locked until client confirms)
                    $sellerTotals = [];
                    foreach ($order->items as $item) {
                        $sellerId = $item->seller_id;
                        if (!isset($sellerTotals[$sellerId])) {
                            $sellerTotals[$sellerId] = 0;
                        }
                        $sellerTotals[$sellerId] += (float) $item->total_price;
                    }

                    foreach ($sellerTotals as $sellerId => $amount) {
                        \App\Models\User::where('id', $sellerId)
                            ->increment('pending_earnings', $amount);
                    }
                }
            }
        } elseif (in_array($status, ['FAILED', 'CANCELLED'])) {
            $transaction->update(['status' => 'cancelled']);

            if ($orderId) {
                Order::where('id', $orderId)->update(['payment_status' => 'failed']);
            }
        }

        return response()->json(['message' => 'Webhook processed']);
    }
}
