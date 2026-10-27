<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Transaction;
use App\Services\KPayService;
use App\Services\MobileMoneyGateway;
use App\Services\PaymentMethodService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    /**
     * Liste des moyens de paiement entrants disponibles pour un montant donné.
     *
     * GET /v1/payments/methods?amount=1234&currency=XAF
     *
     * Renvoie TOUS les rails (jamais masqués selon le pays) avec, pour chacun :
     * disponibilité (`available` = activé ET montant ≥ minimum), minimum affiché
     * dans la devise du montant, et montant converti dans la devise du rail
     * (via les taux de change stockés). Le mobile grise les moyens `available=false`
     * et affiche `converted_amount` à la sélection. Avec `include_wallet=1`, l'option
     * « Wallet ASSO » (solde disponible) est ajoutée en tête.
     */
    public function methods(Request $request)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0',
            'currency' => 'nullable|string|size:3',
            'include_wallet' => 'nullable|boolean',
        ]);

        $currency = strtoupper($validated['currency'] ?? 'XAF');
        $methods = PaymentMethodService::forAmount((float) $validated['amount'], $currency);

        // Wallet ASSO en tête de liste pour les parcours qui l'acceptent.
        if ($request->boolean('include_wallet') && $request->user()) {
            array_unshift($methods, PaymentMethodService::walletOption(
                $request->user(),
                (float) $validated['amount'],
                $currency
            ));
        }

        return response()->json([
            'success' => true,
            'data' => [
                'amount' => (float) $validated['amount'],
                'currency' => $currency,
                'methods' => $methods,
            ],
        ]);
    }
    // Dans le contrôleur qui gère /v1/payments/methods
public function operatorAvailability(Request $request, KPayService $kpay)
{
    $provider = $request->validate([
        'provider' => ['required', 'string'],
        'operation' => ['nullable', 'in:DEPOSIT,PAYOUT'],
    ]);
    $op = $provider['operation'] ?? 'DEPOSIT';
    $available = $kpay->isProviderAvailable($provider['provider'], $op);

    return response()->json(['data' => [
        'provider' => $provider['provider'],
        'available' => $available,
        'suggestions' => $available ? [] : $kpay->suggestAlternatives($provider['provider'], $op),
    ]]);
}
    /**
     * Aperçu du prix public d'un produit pour le vendeur qui le saisit.
     *
     * GET /v1/pricing/preview?price=10000&currency=XAF
     * → { seller_price, buyer_price, currency } : le client paie buyer_price
     *   (commission ASSO incluse), le vendeur reçoit seller_price.
     */
    public function pricingPreview(Request $request)
    {
        $validated = $request->validate([
            'price' => 'required|numeric|min:0',
            'currency' => 'nullable|string|size:3',
        ]);

        $currency = strtoupper($validated['currency'] ?? 'XAF');
        $price = (float) $validated['price'];
        $priceXaf = $currency === 'XAF'
            ? $price
            : \App\Services\ExchangeRateService::convertAmount($currency, 'XAF', $price);
        // Sans taux, la tranche de commission serait choisie sur un prix non converti.
        if ($priceXaf === null) {
            return response()->json([
                'success' => false,
                'message' => __('payments.rate_unavailable', ['currency' => $currency, 'pivot' => 'XAF']),
            ], 422);
        }
        $rate = \App\Services\CommissionService::rateFor($priceXaf);

        return response()->json([
            'success' => true,
            'data' => [
                'seller_price' => $price,
                'buyer_price' => \App\Services\CommissionService::markup($price, $rate, $currency),
                'currency' => $currency,
            ],
        ]);
    }

    /**
     * Initialize payment for an order
     */
    public function initiate(Request $request)
    {
        $request->validate([
            'order_id' => 'required|exists:orders,id',
            'payment_method' => 'required|in:kpay,mobile,cash',
            // provider = code opérateur KPay (ex. MTN_MOMO_CMR) — détermine pays et devise
            'provider' => 'required_if:payment_method,kpay,mobile|string',
            'phone_number' => 'required_if:payment_method,kpay,mobile|string',
        ]);

        $order = Order::where('user_id', $request->user()->id)
            ->where('payment_status', 'pending')
            ->findOrFail($request->order_id);

        if ($request->payment_method === 'kpay' || $request->payment_method === 'mobile') {
            // Le total est en XAF ; l'opérateur encaisse dans sa devise (cf. OrderService).
            $payCurrency = \App\Services\KPayCatalog::currencyForProvider($request->provider);
            $payAmount = $payCurrency === 'XAF'
                ? (float) $order->total
                : \App\Services\ExchangeRateService::convertAmount('XAF', $payCurrency, (float) $order->total);
            if ($payAmount === null) {
                return response()->json([
                    'success' => false,
                    'message' => __('payments.conversion_unavailable_retry', ['currency' => $payCurrency]),
                ], 422);
            }
            $order->update(['payment_currency' => $payCurrency, 'payment_amount' => round($payAmount)]);

            $kpay = app(MobileMoneyGateway::class);
            $result = $kpay->initializePayment([
                'amount' => (float) round($payAmount),
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
                    'message' => __('payments.initiated_confirm_on_phone'),
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
                'message' => __('payments.cash_on_delivery_confirmed'),
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => __('payments.method_not_supported'),
        ], 422);
    }

    /**
     * Check payment status
     */
    public function status(Request $request, $reference)
    {
        // $reference = id KPay (pay_xxx) conservé côté client (metadata.kpay_id)
        $result = app(MobileMoneyGateway::class)->checkPaymentStatus($reference);

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

        $this->captureKpayFees($request);

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

        // Réservation immobilière (externalId = IMMO-…) : statut relu chez le
        // prestataire avant toute confirmation, jamais pris du webhook.
        if (str_starts_with($externalId, 'IMMO-')) {
            $reservation = \App\Models\RealEstate\Reservation::where('reference', $externalId)->first();
            if ($reservation) {
                app(\App\Services\RealEstate\RealEstateReservationService::class)->checkAndConfirm($reservation);
            }
            return response()->json(['message' => 'Real estate webhook processed']);
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

        // Envoi maritime payé en direct KPay (externalId = MARITIME-{bookingId})
        if (str_starts_with($externalId, 'MARITIME-')) {
            $booking = \App\Models\MaritimeBooking::find((int) substr($externalId, strlen('MARITIME-')));
            if ($booking) {
                $maritime = app(\App\Services\MaritimeBookingService::class);
                if (in_array($status, ['COMPLETED', 'SUCCESS', 'SUCCESSFUL'])) {
                    $maritime->confirmPayment($booking);
                } elseif (in_array($status, ['FAILED', 'CANCELLED'])) {
                    $maritime->failPayment($booking);
                }
            }
            return response()->json(['message' => 'Maritime booking webhook processed']);
        }

        // Course d'un litige payée par le vendeur (externalId = LITIGE-{shipmentId})
        if ($disputeShipment = \App\Services\DisputeService::shipmentForKpayReference($externalId)) {
            $disputes = app(\App\Services\DisputeService::class);
            if (in_array($status, ['COMPLETED', 'SUCCESS', 'SUCCESSFUL'])) {
                $disputes->confirmPayment($disputeShipment);
            } elseif (in_array($status, ['FAILED', 'CANCELLED'])) {
                $disputes->failPayment($disputeShipment);
            }
            return response()->json(['message' => 'Dispute shipment webhook processed']);
        }

        // Solde d'une commande avec acompte (externalId = {order_number}-SOLDE)
        if ($balanceOrder = \App\Services\DepositOrderService::orderForBalanceReference($externalId)) {
            $deposits = app(\App\Services\DepositOrderService::class);
            if (in_array($status, ['COMPLETED', 'SUCCESS', 'SUCCESSFUL'])) {
                $deposits->confirmBalancePayment($balanceOrder);
            } elseif (in_array($status, ['FAILED', 'CANCELLED'])) {
                $deposits->failBalancePayment($balanceOrder);
            }
            return response()->json(['message' => 'Order balance webhook processed']);
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
                    // ⚠️ CHEMIN LEGACY DÉPRÉCIÉ (ancien flux Transaction/Freemopay).
                    // Injoignable pour les commandes actuelles : le neuf passe par 'wallet'
                    // (débit direct, sans webhook) ou 'kpay_direct' (traité plus haut). Ce
                    // chemin est INCOHÉRENT avec l'encaissement direct — il force 'confirmed'
                    // en sautant la validation vendeur (VendorOrderController::validate, qui
                    // seule crédite désormais vendeur/livreur/ASSO) et alimente le solde
                    // legacy `pending_earnings` qui n'a AUCUN mécanisme de libération dans le
                    // modèle unifié. On log une alerte pour repérer et traiter à la main toute
                    // occurrence en prod plutôt que de créditer un solde bloqué à jamais.
                    Log::warning('[PaymentController] Webhook KPay via chemin LEGACY (Transaction+pending_earnings) — incompatible encaissement direct, à traiter manuellement', [
                        'transaction_id' => $transaction->id,
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'external_reference' => $externalId,
                    ]);

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

    /**
     * Le webhook KPay ne porte ni netAmount ni feeAmount : sur un événement abouti,
     * l'objet complet est relu (GET authentifié) pour enregistrer les frais réels.
     */
    private function captureKpayFees(Request $request): void
    {
        $id = (string) $request->input('paymentId', '');
        if ($id === '' || strtoupper((string) $request->input('status')) !== 'COMPLETED'
            || \App\Models\PaymentGatewayFee::knows('kpay', $id)) {
            return;
        }

        $event = (string) ($request->input('event') ?: $request->header('X-KPAY-Event', ''));
        if (str_starts_with($event, 'refund.')) {
            return;
        }
        $kpay = app(KPayService::class);
        str_starts_with($event, 'payout.')
            ? $kpay->checkDisbursementStatus($id)
            : $kpay->checkPaymentStatus($id);
    }
}