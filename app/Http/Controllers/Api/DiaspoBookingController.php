<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Helpers\CurrencyHelper;
use App\Models\DiaspoBooking;
use App\Models\DiaspoOffer;
use App\Models\User;
use App\Models\Conversation;
use App\Models\Transaction;
use App\Models\CommissionRange;
use App\Services\WalletService;
use App\Services\FcmService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DiaspoBookingController extends Controller
{
    protected $walletService;
    protected $fcmService;

    public function __construct(WalletService $walletService, FcmService $fcmService)
    {
        $this->walletService = $walletService;
        $this->fcmService = $fcmService;
    }

    /**
     * Get all bookings for current user (as buyer or seller)
     */
    public function index(Request $request)
    {
        $userId = auth()->id();

        $query = DiaspoBooking::with(['diaspoOffer.user', 'buyer', 'seller', 'conversation']);

        // Filtrer par rôle
        if ($request->role === 'buyer') {
            $query->forBuyer($userId);
        } elseif ($request->role === 'seller') {
            $query->forSeller($userId);
        } else {
            // Les deux
            $query->where(function ($q) use ($userId) {
                $q->where('buyer_user_id', $userId)
                  ->orWhere('seller_user_id', $userId);
            });
        }

        // Filtrer par status
        if ($request->status) {
            $query->where('status', $request->status);
        }

        $bookings = $query->latest()->paginate($request->per_page ?? 20);

        return response()->json([
            'success' => true,
            'data' => $bookings,
        ]);
    }

    /**
     * Get a single booking
     */
    public function show($id)
    {
        $booking = DiaspoBooking::with(['diaspoOffer.user', 'buyer', 'seller', 'conversation'])
            ->findOrFail($id);

        // Vérifier que l'utilisateur est concerné
        if ($booking->buyer_user_id !== auth()->id() && $booking->seller_user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Non autorisé',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $booking,
        ]);
    }

    /**
     * Create a booking (acheter des kilos)
     */
    public function store(Request $request, $offerId)
    {
        $validated = $request->validate([
            'kg_booked' => 'required|numeric|min:0.1',
            'notes' => 'nullable|string|max:1000',
            'payment_method' => 'nullable|in:freemopay,paypal',
        ]);

        $offer = DiaspoOffer::findOrFail($offerId);
        $buyer = auth()->user();
        $kgBooked = $validated['kg_booked'];
        $paymentMethod = $validated['payment_method'] ?? 'freemopay';

        // Vérifications
        if ($offer->user_id === $buyer->id) {
            return response()->json([
                'success' => false,
                'message' => 'Vous ne pouvez pas réserver votre propre offre',
            ], 400);
        }

        if (!$offer->is_available) {
            return response()->json([
                'success' => false,
                'message' => 'Cette offre n\'est plus disponible',
            ], 400);
        }

        if ($kgBooked > $offer->remaining_kg) {
            return response()->json([
                'success' => false,
                'message' => "Quantité non disponible. Il ne reste que {$offer->remaining_kg} kg",
            ], 400);
        }

        // Calcul du prix (convert to FCFA if offer is in different currency)
        $pricePerKgInFcfa = $offer->price_per_kg;
        if ($offer->currency && strtoupper($offer->currency) !== 'FCFA') {
            $pricePerKgInFcfa = CurrencyHelper::convert($offer->price_per_kg, $offer->currency, 'FCFA');
        }
        $subtotal = $kgBooked * $pricePerKgInFcfa;

        // Calcul de la commission selon la plage
        $commissionRange = CommissionRange::where('min_amount', '<=', $subtotal)
            ->where('max_amount', '>=', $subtotal)
            ->first();

        $commissionRate = $commissionRange ? $commissionRange->commission_rate : 0;
        $commissionAmount = ($subtotal * $commissionRate) / 100;
        $total = $subtotal + $commissionAmount;

        // Vérifier le solde wallet selon le payment method
        $walletField = $paymentMethod === 'paypal' ? 'paypal_wallet_balance' : 'freemopay_wallet_balance';
        $currentBalance = $buyer->{$walletField} ?? 0;

        if ($currentBalance < $total) {
            $providerName = $paymentMethod === 'paypal' ? 'PayPal' : 'FreeMoPay';
            return response()->json([
                'success' => false,
                'message' => "Solde {$providerName} insuffisant. Rechargez votre wallet.",
                'required' => $total,
                'available' => $currentBalance,
                'payment_method' => $paymentMethod,
            ], 400);
        }

        DB::beginTransaction();
        try {
            // Créer la réservation (store converted price in FCFA)
            $booking = DiaspoBooking::create([
                'diaspo_offer_id' => $offer->id,
                'buyer_user_id' => $buyer->id,
                'seller_user_id' => $offer->user_id,
                'kg_booked' => $kgBooked,
                'price_per_kg' => $pricePerKgInFcfa, // Converted to FCFA
                'subtotal' => $subtotal,
                'commission_amount' => $commissionAmount,
                'total_price' => $total,
                'status' => 'paid',
                'payment_status' => 'completed',
                'payment_method' => $paymentMethod,
                'paid_at' => now(),
                'notes' => $validated['notes'] ?? null,
            ]);

            // Déduire de l'acheteur via WalletService (use selected payment method)
            $this->walletService->debit(
                $buyer,
                $total,
                "Achat de {$kgBooked}kg - Offre #{$offer->id}",
                'diaspo_booking',
                $booking->id,
                [
                    'kg_booked' => $kgBooked,
                    'original_price_per_kg' => $offer->price_per_kg,
                    'original_currency' => $offer->currency ?? 'FCFA',
                    'price_per_kg_fcfa' => $pricePerKgInFcfa,
                    'subtotal' => $subtotal,
                    'commission' => $commissionAmount,
                    'payment_method' => $paymentMethod,
                ],
                $paymentMethod
            );

            // Ajouter les fonds au vendeur directement en locked (escrow)
            $seller = User::find($offer->user_id);

            // Déterminer la colonne de locked balance selon le payment method
            $lockedBalanceField = $paymentMethod === 'paypal' ? 'locked_paypal_balance' : 'locked_freemopay_balance';
            $walletBalanceField = $paymentMethod === 'paypal' ? 'paypal_wallet_balance' : 'freemopay_wallet_balance';

            // Incrémenter le locked balance du vendeur selon le provider utilisé par le buyer
            $seller->increment($lockedBalanceField, $subtotal);

            // Créer une transaction wallet pour traçabilité (type 'lock')
            \App\Models\WalletTransaction::create([
                'user_id' => $seller->id,
                'type' => 'lock',
                'amount' => $subtotal,
                'balance_before' => $seller->{$walletBalanceField},
                'balance_after' => $seller->{$walletBalanceField}, // Balance principale ne change pas
                'description' => "Vente en attente: {$kgBooked}kg - Réservation #{$booking->id}",
                'reference_type' => 'diaspo_booking',
                'reference_id' => $booking->id,
                'metadata' => [
                    'kg_booked' => $kgBooked,
                    'original_price_per_kg' => $offer->price_per_kg,
                    'original_currency' => $offer->currency ?? 'FCFA',
                    'price_per_kg_fcfa' => $pricePerKgInFcfa,
                    'buyer_id' => $buyer->id,
                    'buyer_name' => "{$buyer->first_name} {$buyer->last_name}",
                    'buyer_payment_method' => $paymentMethod,
                    'confirmation_code' => $booking->confirmation_code,
                    'locked_amount' => $subtotal,
                    'locked_balance_after' => $seller->{$lockedBalanceField},
                ],
                'status' => 'completed',
                'provider' => $paymentMethod,
            ]);

            // Réduire les kg disponibles
            $offer->decrement('remaining_kg', $kgBooked);
            $offer->increment('bookings_count');

            // Créer/récupérer la conversation
            $conversation = Conversation::firstOrCreate([
                'user1_id' => min($buyer->id, $seller->id),
                'user2_id' => max($buyer->id, $seller->id),
            ]);
            $booking->update(['conversation_id' => $conversation->id]);

            // Log pour debugging
            Log::info("[DiaspoBooking] Nouvelle réservation créée", [
                'booking_id' => $booking->id,
                'buyer_id' => $buyer->id,
                'seller_id' => $seller->id,
                'amount' => $subtotal,
                'confirmation_code' => $booking->confirmation_code,
            ]);

            // Send notification to seller
            $this->fcmService->sendToUser(
                $seller,
                '🔒 Nouvelle réservation reçue',
                "Vous avez reçu {$subtotal} FCFA (bloqués). Livrez les {$kgBooked} kg à {$buyer->first_name} et entrez le code pour débloquer.",
                [
                    'type' => 'diaspo_booking_received',
                    'booking_id' => (string) $booking->id,
                    'amount' => (string) $subtotal,
                    'kg' => (string) $kgBooked,
                    'buyer_name' => "{$buyer->first_name} {$buyer->last_name}",
                    'screen' => 'wallet',
                ]
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Réservation confirmée',
                'data' => $booking->fresh(['diaspoOffer', 'buyer', 'seller', 'conversation']),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la réservation: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Seller confirms delivery by entering the confirmation code
     * This unlocks the funds for the seller
     */
    public function sellerConfirmDelivery(Request $request, $id)
    {
        $request->validate([
            'confirmation_code' => 'required|string|size:6',
        ]);

        $booking = DiaspoBooking::findOrFail($id);
        $seller = auth()->user();

        // Vérifier que c'est le vendeur
        if ($booking->seller_user_id !== $seller->id) {
            return response()->json([
                'success' => false,
                'message' => 'Non autorisé. Seul le vendeur peut confirmer la livraison.',
            ], 403);
        }

        // Vérifier le status
        if ($booking->status === 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'Cette réservation est déjà confirmée',
            ], 400);
        }

        if ($booking->status === 'cancelled') {
            return response()->json([
                'success' => false,
                'message' => 'Cette réservation a été annulée',
            ], 400);
        }

        if ($booking->status !== 'paid') {
            return response()->json([
                'success' => false,
                'message' => 'Cette réservation n\'est pas encore payée',
            ], 400);
        }

        // Vérifier le code
        if (!$booking->verifyConfirmationCode($request->confirmation_code)) {
            return response()->json([
                'success' => false,
                'message' => 'Code de confirmation incorrect. Demandez au client de vous fournir le bon code.',
            ], 400);
        }

        DB::beginTransaction();
        try {
            // Marquer comme complété
            $booking->update([
                'status' => 'completed',
                'confirmed_by_buyer_at' => now(),
            ]);

            // Déterminer le provider utilisé lors du paiement
            $paymentMethod = $booking->payment_method ?? 'freemopay';
            $lockedBalanceField = $paymentMethod === 'paypal' ? 'locked_paypal_balance' : 'locked_freemopay_balance';
            $walletBalanceField = $paymentMethod === 'paypal' ? 'paypal_wallet_balance' : 'freemopay_wallet_balance';

            // Débloquer les fonds du vendeur et les ajouter au solde disponible
            $seller->decrement($lockedBalanceField, $booking->subtotal);
            $seller->increment($walletBalanceField, $booking->subtotal);

            // Créer une transaction wallet pour traçabilité (type 'unlock')
            \App\Models\WalletTransaction::create([
                'user_id' => $seller->id,
                'type' => 'unlock',
                'amount' => $booking->subtotal,
                'balance_before' => $seller->{$walletBalanceField} - $booking->subtotal,
                'balance_after' => $seller->{$walletBalanceField},
                'description' => "Vente confirmée: {$booking->kg_booked}kg - Réservation #{$booking->id}",
                'reference_type' => 'diaspo_booking',
                'reference_id' => $booking->id,
                'metadata' => [
                    'buyer_id' => $booking->buyer_user_id,
                    'confirmation_code' => $booking->confirmation_code,
                    'confirmed_at' => now()->toDateTimeString(),
                    'unlocked_amount' => $booking->subtotal,
                ],
                'status' => 'completed',
                'provider' => $paymentMethod,
            ]);

            Log::info("[DiaspoBooking] Livraison confirmée par le vendeur", [
                'booking_id' => $booking->id,
                'seller_id' => $seller->id,
                'amount' => $booking->subtotal,
                'code_used' => $request->confirmation_code,
            ]);

            // Send notification to buyer
            $buyer = $booking->buyer;
            $this->fcmService->sendToUser(
                $buyer,
                '✅ Livraison confirmée',
                "Le vendeur a confirmé la livraison de vos {$booking->kg_booked} kg. Merci d'avoir utilisé ASSO!",
                [
                    'type' => 'diaspo_delivery_confirmed',
                    'booking_id' => (string) $booking->id,
                    'kg' => (string) $booking->kg_booked,
                    'screen' => 'diaspo',
                ]
            );

            // Send notification to seller
            $this->fcmService->sendToUser(
                $seller,
                '💰 Fonds débloqués!',
                "Vous avez reçu {$booking->subtotal} FCFA dans votre wallet. Les fonds sont maintenant disponibles.",
                [
                    'type' => 'wallet_unlocked',
                    'booking_id' => (string) $booking->id,
                    'amount' => (string) $booking->subtotal,
                    'screen' => 'wallet',
                ]
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Livraison confirmée! Les fonds sont maintenant disponibles dans votre wallet.',
                'data' => $booking->fresh(['diaspoOffer', 'buyer', 'seller']),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("[DiaspoBooking] Erreur lors de la confirmation vendeur", [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la confirmation: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * @deprecated Use sellerConfirmDelivery() instead
     * Confirm receipt with confirmation code (OLD - kept for backward compatibility)
     */
    public function confirmReceipt(Request $request, $id)
    {
        // Redirect to the new method
        return $this->sellerConfirmDelivery($request, $id);
    }

    /**
     * Seller confirms delivery using only the confirmation code
     * Finds the booking automatically by code for the authenticated seller
     */
    public function confirmByCodeOnly(Request $request)
    {
        $request->validate([
            'confirmation_code' => 'required|string|size:6',
        ]);

        $seller = auth()->user();
        $code = strtoupper($request->confirmation_code);

        // Find booking by confirmation code for this seller
        $booking = DiaspoBooking::where('seller_user_id', $seller->id)
            ->where('confirmation_code', $code)
            ->where('status', 'paid')
            ->first();

        if (!$booking) {
            return response()->json([
                'success' => false,
                'message' => 'Code invalide ou aucune réservation en attente avec ce code. Vérifiez que le code est correct et que la réservation est payée.',
            ], 404);
        }

        DB::beginTransaction();
        try {
            // Marquer comme complété
            $booking->update([
                'status' => 'completed',
                'confirmed_by_buyer_at' => now(),
            ]);

            // Déterminer le provider utilisé lors du paiement
            $paymentMethod = $booking->payment_method ?? 'freemopay';
            $lockedBalanceField = $paymentMethod === 'paypal' ? 'locked_paypal_balance' : 'locked_freemopay_balance';
            $walletBalanceField = $paymentMethod === 'paypal' ? 'paypal_wallet_balance' : 'freemopay_wallet_balance';

            // Débloquer les fonds du vendeur et les ajouter au solde disponible
            $seller->decrement($lockedBalanceField, $booking->subtotal);
            $seller->increment($walletBalanceField, $booking->subtotal);

            // Créer une transaction wallet pour traçabilité (type 'unlock')
            \App\Models\WalletTransaction::create([
                'user_id' => $seller->id,
                'type' => 'unlock',
                'amount' => $booking->subtotal,
                'balance_before' => $seller->{$walletBalanceField} - $booking->subtotal,
                'balance_after' => $seller->{$walletBalanceField},
                'description' => "Vente confirmée: {$booking->kg_booked}kg - Réservation #{$booking->id}",
                'reference_type' => 'diaspo_booking',
                'reference_id' => $booking->id,
                'metadata' => [
                    'buyer_id' => $booking->buyer_user_id,
                    'confirmation_code' => $booking->confirmation_code,
                    'confirmed_at' => now()->toDateTimeString(),
                    'unlocked_amount' => $booking->subtotal,
                    'confirmed_via' => 'quick_code_entry',
                ],
                'status' => 'completed',
                'provider' => $paymentMethod,
            ]);

            Log::info("[DiaspoBooking] Livraison confirmée par code rapide", [
                'booking_id' => $booking->id,
                'seller_id' => $seller->id,
                'amount' => $booking->subtotal,
                'code_used' => $code,
            ]);

            // Send notification to buyer
            $buyer = $booking->buyer;
            $this->fcmService->sendToUser(
                $buyer,
                '✅ Livraison confirmée',
                "Le vendeur a confirmé la livraison de vos {$booking->kg_booked} kg. Merci d'avoir utilisé ASSO!",
                [
                    'type' => 'diaspo_delivery_confirmed',
                    'booking_id' => (string) $booking->id,
                    'kg' => (string) $booking->kg_booked,
                    'screen' => 'diaspo',
                ]
            );

            // Send notification to seller
            $this->fcmService->sendToUser(
                $seller,
                '💰 Fonds débloqués!',
                "Vous avez reçu {$booking->subtotal} FCFA dans votre wallet. Les fonds sont maintenant disponibles.",
                [
                    'type' => 'wallet_unlocked',
                    'booking_id' => (string) $booking->id,
                    'amount' => (string) $booking->subtotal,
                    'screen' => 'wallet',
                ]
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Livraison confirmée! Les fonds sont maintenant disponibles dans votre wallet.',
                'data' => $booking->fresh(['diaspoOffer', 'buyer', 'seller']),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("[DiaspoBooking] Erreur lors de la confirmation par code", [
                'code' => $code,
                'seller_id' => $seller->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la confirmation: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Cancel a booking
     */
    public function cancel(Request $request, $id)
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $booking = DiaspoBooking::findOrFail($id);
        $user = auth()->user();

        // Vérifier que c'est l'acheteur ou le vendeur
        if ($booking->buyer_user_id !== $user->id && $booking->seller_user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Non autorisé',
            ], 403);
        }

        // Ne peut pas annuler si déjà complété
        if ($booking->status === 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'Cette réservation est déjà complétée',
            ], 400);
        }

        DB::beginTransaction();
        try {
            // Annuler la réservation
            $booking->cancel($request->reason);

            $buyer = User::find($booking->buyer_user_id);
            $seller = User::find($booking->seller_user_id);

            // Déterminer le payment method utilisé (depuis la metadata si possible, sinon freemopay par défaut)
            $buyerPaymentMethod = 'freemopay'; // Default
            // TODO: Stocker payment_method dans le booking pour pouvoir le récupérer ici

            // Rembourser l'acheteur via WalletService (dans le même wallet qu'il a utilisé)
            $this->walletService->credit(
                $buyer,
                $booking->total_price,
                null,
                "Remboursement - Annulation réservation #{$booking->id}",
                [
                    'booking_id' => $booking->id,
                    'cancel_reason' => $request->reason,
                    'cancelled_by' => $user->id,
                ],
                $buyerPaymentMethod
            );

            // Déterminer le provider utilisé lors du paiement
            $paymentMethod = $booking->payment_method ?? 'freemopay';
            $lockedBalanceField = $paymentMethod === 'paypal' ? 'locked_paypal_balance' : 'locked_freemopay_balance';
            $walletBalanceField = $paymentMethod === 'paypal' ? 'paypal_wallet_balance' : 'freemopay_wallet_balance';

            // Débloquer les fonds du vendeur (simplement décrémenter locked_balance)
            $seller->decrement($lockedBalanceField, $booking->subtotal);

            // Créer une transaction wallet pour traçabilité (type 'unlock')
            \App\Models\WalletTransaction::create([
                'user_id' => $seller->id,
                'type' => 'unlock',
                'amount' => $booking->subtotal,
                'balance_before' => $seller->{$walletBalanceField},
                'balance_after' => $seller->{$walletBalanceField}, // Balance principale ne change pas
                'description' => "Déblocage - Annulation réservation #{$booking->id}",
                'reference_type' => 'diaspo_booking',
                'reference_id' => $booking->id,
                'metadata' => [
                    'cancel_reason' => $request->reason,
                    'cancelled_by' => $user->id,
                    'unlocked_amount' => $booking->subtotal,
                ],
                'status' => 'completed',
                'provider' => $paymentMethod,
            ]);

            // Remettre les kg disponibles
            $offer = DiaspoOffer::find($booking->diaspo_offer_id);
            $offer->increment('remaining_kg', $booking->kg_booked);
            $offer->decrement('bookings_count');

            Log::info("[DiaspoBooking] Réservation annulée", [
                'booking_id' => $booking->id,
                'cancelled_by' => $user->id,
                'reason' => $request->reason,
                'refunded_amount' => $booking->total_price,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Réservation annulée et remboursée',
                'data' => $booking->fresh(),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("[DiaspoBooking] Erreur lors de l'annulation", [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'annulation: ' . $e->getMessage(),
            ], 500);
        }
    }
}
