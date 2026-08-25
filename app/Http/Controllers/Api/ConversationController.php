<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Events\MessageSent;
use App\Events\UserTyping;
use App\Events\UserOnlineStatus;
use App\Services\FirebaseMessagingService;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    /**
     * List user's conversations
     */
    public function index(Request $request)
    {
        $userId = $request->user()->id;

        $conversations = Conversation::with(['user1', 'user2', 'product', 'diaspoOffer', 'latestMessage'])
            ->where(function($q) use ($userId) {
                $q->where('user1_id', $userId)->orWhere('user2_id', $userId);
            })
            ->notHiddenBy($userId) // Exclure les conversations cachées par l'utilisateur
            ->orderBy('last_message_at', 'desc')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'conversations' => $conversations->getCollection()->map(function($conv) use ($userId) {
                $otherUser = $conv->getOtherUser($userId);
                $unreadCount = $conv->messages()->where('sender_id', '!=', $userId)->where('is_read', false)->count();

                return [
                    'id' => $conv->id,
                    'other_user' => [
                        'id' => $otherUser->id,
                        'name' => $otherUser->name,
                        'avatar' => $otherUser->avatar,
                        'phone' => $otherUser->phone,
                    ],
                    'product' => $conv->product ? [
                        'id' => $conv->product->id,
                        'name' => $conv->product->name,
                        'price' => (float) $conv->product->price,
                        'image' => $conv->product->primaryImage ? asset('storage/' . $conv->product->primaryImage->image_path) : null,
                    ] : null,
                    'diaspo_offer' => $conv->diaspoOffer ? [
                        'id' => $conv->diaspoOffer->id,
                        'departure_city' => $conv->diaspoOffer->departure_city,
                        'departure_country' => $conv->diaspoOffer->departure_country,
                        'arrival_city' => $conv->diaspoOffer->arrival_city,
                        'arrival_country' => $conv->diaspoOffer->arrival_country,
                        'price_per_kg' => (float) $conv->diaspoOffer->price_per_kg,
                        'currency' => $conv->diaspoOffer->currency,
                        'remaining_kg' => (float) $conv->diaspoOffer->remaining_kg,
                    ] : null,
                    'last_message' => $conv->latestMessage ? [
                        'message' => $conv->latestMessage->message,
                        'sender_id' => $conv->latestMessage->sender_id,
                        'is_read' => $conv->latestMessage->is_read,
                        'created_at' => $conv->latestMessage->created_at->toIso8601String(),
                    ] : null,
                    'unread_count' => $unreadCount,
                    'updated_at' => $conv->last_message_at?->toIso8601String() ?? $conv->created_at->toIso8601String(),
                ];
            }),
            'pagination' => [
                'current_page' => $conversations->currentPage(),
                'has_more' => $conversations->hasMorePages(),
                'total' => $conversations->total(),
            ],
        ]);
    }

    /**
     * Get or create a conversation with a user (optionally about a product)
     */
    public function startOrGet(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'product_id' => 'nullable|exists:products,id',
            'diaspo_offer_id' => 'nullable|exists:diaspo_offers,id',
        ]);

        $userId = $request->user()->id;
        $otherUserId = $request->user_id;

        if ($userId == $otherUserId) {
            return response()->json(['success' => false, 'message' => 'Vous ne pouvez pas vous envoyer un message'], 422);
        }

        // Find existing conversation (including hidden ones)
        $conversation = Conversation::where(function($q) use ($userId, $otherUserId) {
            $q->where('user1_id', $userId)->where('user2_id', $otherUserId);
        })->orWhere(function($q) use ($userId, $otherUserId) {
            $q->where('user1_id', $otherUserId)->where('user2_id', $userId);
        });

        if ($request->product_id) {
            $conversation = $conversation->where('product_id', $request->product_id);
        }

        if ($request->diaspo_offer_id) {
            $conversation = $conversation->where('diaspo_offer_id', $request->diaspo_offer_id);
        }

        $conversation = $conversation->first();

        if (!$conversation) {
            // Créer une nouvelle conversation
            $conversation = Conversation::create([
                'user1_id' => $userId,
                'user2_id' => $otherUserId,
                'product_id' => $request->product_id,
                'diaspo_offer_id' => $request->diaspo_offer_id,
                'last_message_at' => now(),
            ]);
        } else {
            // Si la conversation existe mais était cachée, envoyer un nouveau message système
            if ($conversation->isHiddenBy($userId)) {
                // Récupérer la date du hide
                $hideEntry = \App\Models\ConversationHide::where('conversation_id', $conversation->id)
                    ->where('user_id', $userId)
                    ->first();

                // Vérifier s'il n'y a pas déjà un message système créé après le hide
                $hasSystemMessageAfterHide = false;
                if ($hideEntry) {
                    $hasSystemMessageAfterHide = Message::where('conversation_id', $conversation->id)
                        ->where('is_system', true)
                        ->where('created_at', '>', $hideEntry->created_at)
                        ->exists();
                }

                // Créer un message système seulement s'il n'y en a pas déjà un après le hide
                if (!$hasSystemMessageAfterHide) {
                    // Récupérer l'utilisateur système
                    $systemUser = \App\Models\User::where('email', 'system@asso.app')->first();

                    if ($systemUser) {
                        // Message de sécurité à envoyer automatiquement
                        $securityMessage = "Vous prenez un risque en envoyant votre numéro de téléphone / e-mail\n\n" .
                            "Ici nous garantissons la sécurité de vos échanges, c'est pourquoi nous vous conseillons de " .
                            "rester sur la messagerie pour discuter et passer par le paiement sécurisé pour vos transactions.\n\n" .
                            "→ En savoir plus";

                        // Créer le message système
                        Message::create([
                            'conversation_id' => $conversation->id,
                            'sender_id' => $systemUser->id,
                            'message' => $securityMessage,
                            'is_system' => true,
                            'is_read' => false,
                        ]);

                        \Log::info("✅ Message de sécurité envoyé pour la conversation réouverte #{$conversation->id}");
                    }
                }

                // Note: unhideFor ne fait rien maintenant, mais on garde l'appel pour la cohérence
                $conversation->unhideFor($userId);
            }
        }

        $conversation->load(['user1', 'user2', 'product', 'diaspoOffer']);
        $otherUser = $conversation->getOtherUser($userId);

        return response()->json([
            'success' => true,
            'conversation' => [
                'id' => $conversation->id,
                'other_user' => [
                    'id' => $otherUser->id,
                    'name' => $otherUser->name,
                    'avatar' => $otherUser->avatar,
                    'phone' => $otherUser->phone,
                ],
                'product' => $conversation->product ? [
                    'id' => $conversation->product->id,
                    'name' => $conversation->product->name,
                ] : null,
                'diaspo_offer' => $conversation->diaspoOffer ? [
                    'id' => $conversation->diaspoOffer->id,
                    'departure_city' => $conversation->diaspoOffer->departure_city,
                    'departure_country' => $conversation->diaspoOffer->departure_country,
                    'arrival_city' => $conversation->diaspoOffer->arrival_city,
                    'arrival_country' => $conversation->diaspoOffer->arrival_country,
                ] : null,
            ],
        ]);
    }

    /**
     * Get messages in a conversation
     */
    public function messages(Request $request, $conversationId)
    {
        $userId = $request->user()->id;

        $conversation = Conversation::where(function($q) use ($userId) {
            $q->where('user1_id', $userId)->orWhere('user2_id', $userId);
        })->findOrFail($conversationId);

        // Vérifier si l'utilisateur a caché cette conversation
        $hideEntry = \App\Models\ConversationHide::where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->first();

        \Log::info("🔍 [CHAT] Loading messages for conversation #{$conversationId}", [
            'user_id' => $userId,
            'has_hide_entry' => $hideEntry !== null,
            'hide_date' => $hideEntry ? $hideEntry->created_at->toIso8601String() : null,
        ]);

        // Mark messages from other user as read
        $markAsReadQuery = Message::where('conversation_id', $conversationId)
            ->where('sender_id', '!=', $userId)
            ->where('is_read', false);

        // Ne marquer comme lus que les messages créés après le hide (si existe)
        if ($hideEntry) {
            $markAsReadQuery->where('created_at', '>', $hideEntry->created_at);
            \Log::info("📧 [CHAT] Marking messages as read only after hide date");
        }

        $markAsReadQuery->update(['is_read' => true, 'read_at' => now()]);

        // Charger les messages
        $messagesQuery = Message::with(['sender', 'product.primaryImage', 'product.images', 'diaspoOffer'])
            ->where('conversation_id', $conversationId);

        // Filtrer pour ne montrer que les messages créés après le hide (si existe)
        if ($hideEntry) {
            $messagesQuery->where('created_at', '>', $hideEntry->created_at);
            \Log::info("🔒 [CHAT] Filtering messages to show only those created after: {$hideEntry->created_at->toIso8601String()}");
        }

        $messages = $messagesQuery->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 50));

        \Log::info("📊 [CHAT] Messages loaded", [
            'total_messages' => $messages->total(),
            'current_page' => $messages->currentPage(),
        ]);

        return response()->json([
            'success' => true,
            'messages' => $messages->getCollection()->map(fn($msg) => [
                'id' => $msg->id,
                'sender_id' => $msg->sender_id,
                'message' => $msg->message,
                'image_path' => $msg->image_path ? asset('storage/' . $msg->image_path) : null,
                'product_id' => $msg->product_id,
                'product' => $msg->product ? [
                    'id' => $msg->product->id,
                    'name' => $msg->product->name,
                    'price' => (float) $msg->product->price,
                    'primary_image' => $msg->product->primaryImage ?
                        asset('storage/' . $msg->product->primaryImage->image_path) : null,
                    'image' => $msg->product->primaryImage ?
                        asset('storage/' . $msg->product->primaryImage->image_path) : null,
                    'images' => $msg->product->images ?
                        $msg->product->images->map(fn($img) => asset('storage/' . $img->image_path))->toArray() : [],
                ] : null,
                'diaspo_offer_id' => $msg->diaspo_offer_id,
                'diaspo_offer' => $msg->diaspoOffer ? [
                    'id' => $msg->diaspoOffer->id,
                    'departure_city' => $msg->diaspoOffer->departure_city,
                    'departure_country' => $msg->diaspoOffer->departure_country,
                    'arrival_city' => $msg->diaspoOffer->arrival_city,
                    'arrival_country' => $msg->diaspoOffer->arrival_country,
                    'price_per_kg' => (float) $msg->diaspoOffer->price_per_kg,
                    'currency' => $msg->diaspoOffer->currency,
                    'remaining_kg' => (float) $msg->diaspoOffer->remaining_kg,
                ] : null,
                'is_read' => $msg->is_read,
                'is_system' => $msg->is_system ?? false,
                'is_mine' => $msg->sender_id == $userId,
                'created_at' => $msg->created_at->toIso8601String(),
            ]),
            'pagination' => [
                'current_page' => $messages->currentPage(),
                'has_more' => $messages->hasMorePages(),
            ],
        ]);
    }

    /**
     * Send a message in a conversation
     */
    public function sendMessage(Request $request, $conversationId)
    {
        $request->validate([
            'message' => 'nullable|string|max:2000',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:5120', // Max 5MB
            'product_id' => 'nullable|exists:products,id', // Optionnel: taguer un produit
            'diaspo_offer_id' => 'nullable|exists:diaspo_offers,id', // Optionnel: taguer une offre Diaspo
        ]);

        // Au moins un message ou une image doit être fourni
        if (!$request->message && !$request->hasFile('image')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous devez envoyer un message ou une image'
            ], 422);
        }

        $userId = $request->user()->id;

        $conversation = Conversation::where(function($q) use ($userId) {
            $q->where('user1_id', $userId)->orWhere('user2_id', $userId);
        })->findOrFail($conversationId);

        // Si la conversation était cachée par l'utilisateur, la réafficher
        if ($conversation->isHiddenBy($userId)) {
            $conversation->unhideFor($userId);
        }

        // Vérifier si l'AUTRE utilisateur a caché cette conversation
        $otherUserId = $conversation->user1_id == $userId ? $conversation->user2_id : $conversation->user1_id;
        if ($conversation->isHiddenBy($otherUserId)) {
            // Récupérer la date du hide de l'autre utilisateur
            $hideEntry = \App\Models\ConversationHide::where('conversation_id', $conversationId)
                ->where('user_id', $otherUserId)
                ->first();

            // Vérifier s'il n'y a pas déjà un message système créé après le hide
            $hasSystemMessageAfterHide = false;
            if ($hideEntry) {
                $hasSystemMessageAfterHide = Message::where('conversation_id', $conversationId)
                    ->where('is_system', true)
                    ->where('created_at', '>', $hideEntry->created_at)
                    ->exists();
            }

            // Créer un message système pour l'autre utilisateur s'il n'y en a pas déjà un
            if (!$hasSystemMessageAfterHide) {
                $systemUser = \App\Models\User::where('email', 'system@asso.app')->first();
                if ($systemUser) {
                    $securityMessage = "Vous prenez un risque en envoyant votre numéro de téléphone / e-mail\n\n" .
                        "Ici nous garantissons la sécurité de vos échanges, c'est pourquoi nous vous conseillons de " .
                        "rester sur la messagerie pour discuter et passer par le paiement sécurisé pour vos transactions.\n\n" .
                        "→ En savoir plus";

                    Message::create([
                        'conversation_id' => $conversationId,
                        'sender_id' => $systemUser->id,
                        'message' => $securityMessage,
                        'is_system' => true,
                        'is_read' => false,
                    ]);

                    \Log::info("✅ Message de sécurité créé pour l'utilisateur #{$otherUserId} (conversation #{$conversationId})");
                }
            }
        }

        // Upload de l'image si présente
        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('messages', 'public');
        }

        $message = Message::create([
            'conversation_id' => $conversationId,
            'sender_id' => $userId,
            'message' => $request->message,
            'image_path' => $imagePath,
            'product_id' => $request->product_id, // Ajouter product_id
            'diaspo_offer_id' => $request->diaspo_offer_id, // Ajouter diaspo_offer_id
            'is_read' => false,
        ]);

        // Charger le produit et l'offre Diaspo avec leurs images si présents
        $message->load(['product.primaryImage', 'product.images', 'diaspoOffer']);

        $conversation->update(['last_message_at' => now()]);

        // Broadcast message en temps réel via WebSocket
        try {
            broadcast(new MessageSent($message))->toOthers();
            \Log::info('[CHAT] Message broadcasted via WebSocket', ['message_id' => $message->id]);
        } catch (\Exception $e) {
            \Log::error('[CHAT] Failed to broadcast message', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
            // Ne pas bloquer la réponse si le broadcast échoue
        }

        // Envoyer une notification push au destinataire
        try {
            $sender = $request->user();
            $recipient = User::find($otherUserId);

            if ($recipient) {
                $fcmService = app(FirebaseMessagingService::class);

                // Construire le titre et le corps de la notification
                $notificationTitle = $sender->name;
                $notificationBody = $message->message
                    ? (strlen($message->message) > 100 ? substr($message->message, 0, 100) . '...' : $message->message)
                    : ($message->image_path ? 'Photo' : 'Message');

                // Données supplémentaires pour la navigation
                $notificationData = [
                    'type' => 'new_message',
                    'conversation_id' => (string) $conversationId,
                    'sender_id' => (string) $userId,
                    'sender_name' => $sender->name,
                    'message_id' => (string) $message->id,
                    'screen' => 'chatdetail',
                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                ];

                // Envoyer la notification
                $fcmService->sendToUser(
                    $recipient,
                    $notificationTitle,
                    $notificationBody,
                    $notificationData
                );

                \Log::info('[CHAT] Push notification sent to recipient', [
                    'message_id' => $message->id,
                    'recipient_id' => $otherUserId,
                    'sender_id' => $userId,
                ]);
            }
        } catch (\Exception $e) {
            \Log::error('[CHAT] Failed to send push notification', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // Ne pas bloquer la réponse si la notification échoue
        }

        return response()->json([
            'success' => true,
            'message' => [
                'id' => $message->id,
                'sender_id' => $message->sender_id,
                'message' => $message->message,
                'image_path' => $message->image_path ? asset('storage/' . $message->image_path) : null,
                'product_id' => $message->product_id,
                'product' => $message->product ? [
                    'id' => $message->product->id,
                    'name' => $message->product->name,
                    'price' => (float) $message->product->price,
                    'image' => $message->product->primaryImage ?
                        asset('storage/' . $message->product->primaryImage->image_path) : null,
                ] : null,
                'diaspo_offer_id' => $message->diaspo_offer_id,
                'diaspo_offer' => $message->diaspoOffer ? [
                    'id' => $message->diaspoOffer->id,
                    'departure_city' => $message->diaspoOffer->departure_city,
                    'departure_country' => $message->diaspoOffer->departure_country,
                    'arrival_city' => $message->diaspoOffer->arrival_city,
                    'arrival_country' => $message->diaspoOffer->arrival_country,
                    'price_per_kg' => (float) $message->diaspoOffer->price_per_kg,
                    'currency' => $message->diaspoOffer->currency,
                ] : null,
                'is_mine' => true,
                'created_at' => $message->created_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * Broadcast typing indicator
     */
    public function typing(Request $request, $conversationId)
    {
        $request->validate(['is_typing' => 'required|boolean']);

        $userId = $request->user()->id;
        $userName = $request->user()->name;

        // Vérifier que l'utilisateur fait partie de la conversation
        $conversation = Conversation::where(function($q) use ($userId) {
            $q->where('user1_id', $userId)->orWhere('user2_id', $userId);
        })->findOrFail($conversationId);

        // Broadcast typing status
        broadcast(new UserTyping(
            $conversationId,
            $userId,
            $userName,
            $request->is_typing
        ))->toOthers();

        return response()->json(['success' => true]);
    }

    /**
     * Update user online status
     */
    public function updateOnlineStatus(Request $request)
    {
        $request->validate(['is_online' => 'required|boolean']);

        $user = $request->user();
        $isOnline = $request->is_online;
        $lastSeen = $isOnline ? null : now()->toIso8601String();

        // Broadcast online status
        broadcast(new UserOnlineStatus(
            $user->id,
            $user->name,
            $isOnline,
            $lastSeen
        ))->toOthers();

        return response()->json([
            'success' => true,
            'is_online' => $isOnline,
            'last_seen' => $lastSeen,
        ]);
    }

    /**
     * Hide a conversation for the current user
     * (Does not delete it, just hides it from their conversation list)
     */
    public function hide(Request $request, $conversationId)
    {
        $userId = $request->user()->id;

        $conversation = Conversation::where(function($q) use ($userId) {
            $q->where('user1_id', $userId)->orWhere('user2_id', $userId);
        })->findOrFail($conversationId);

        // Cacher la conversation pour cet utilisateur
        $conversation->hideFor($userId);

        \Log::info("🙈 [CHAT] Conversation #{$conversationId} hidden by user #{$userId}", [
            'hidden_at' => now()->toIso8601String(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Conversation masquée avec succès',
        ]);
    }
}
