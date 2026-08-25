<?php

namespace App\Http\Controllers\Admin;

use App\Events\MessageSent;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\FirebaseMessagingService;
use App\Services\SupportService;
use Illuminate\Http\Request;

/**
 * Messagerie admin (style WhatsApp) du COMPTE SUPPORT ASSO.
 *
 * Réutilise les modèles Conversation/Message du chat mobile : une conversation
 * « support » est simplement une conversation dont un des deux participants est le
 * compte support. Répondre depuis l'admin crée un Message émis par le compte support,
 * qui apparaît donc directement dans le chatdetail mobile du client.
 */
class MessageController extends Controller
{
    /**
     * Liste des conversations support + (optionnel) fil de la conversation sélectionnée.
     * GET /admin/messages
     */
    public function index(Request $request)
    {
        return $this->render($request, $request->query('conversation'));
    }

    /**
     * Fil d'une conversation support (même vue que l'index, avec sélection).
     * GET /admin/messages/{conversation}
     */
    public function show(Request $request, $conversation)
    {
        return $this->render($request, (int) $conversation);
    }

    /**
     * Répondre au client : crée un Message émis par le compte support dans la
     * conversation existante.
     * POST /admin/messages/{conversation}/reply
     */
    public function reply(Request $request, $conversationId)
    {
        $request->validate([
            'message' => 'required|string|max:2000',
        ]);

        $support = SupportService::user();
        if (!$support) {
            return redirect()->route('admin.messages.index')
                ->with('error', 'Compte support non configuré.');
        }

        // La conversation doit impliquer le compte support.
        $conversation = Conversation::where('id', $conversationId)
            ->where(function ($q) use ($support) {
                $q->where('user1_id', $support->id)->orWhere('user2_id', $support->id);
            })
            ->firstOrFail();

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $support->id,
            'message' => $request->message,
            'is_read' => false,
        ]);

        $conversation->update(['last_message_at' => now()]);

        // Temps réel (WebSocket) — même canal que le chat mobile.
        try {
            broadcast(new MessageSent($message))->toOthers();
        } catch (\Throwable $e) {
            \Log::warning('[AdminMessages] broadcast échec: ' . $e->getMessage());
        }

        // Notification push au client destinataire.
        try {
            $clientId = $conversation->user1_id == $support->id
                ? $conversation->user2_id
                : $conversation->user1_id;
            $client = User::find($clientId);
            if ($client) {
                app(FirebaseMessagingService::class)->sendToUser(
                    $client,
                    $support->name,
                    strlen($request->message) > 100 ? substr($request->message, 0, 100) . '...' : $request->message,
                    [
                        'type' => 'new_message',
                        'conversation_id' => (string) $conversation->id,
                        'sender_id' => (string) $support->id,
                        'sender_name' => $support->name,
                        'message_id' => (string) $message->id,
                        'screen' => 'chatdetail',
                        'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                    ]
                );
            }
        } catch (\Throwable $e) {
            \Log::warning('[AdminMessages] FCM échec: ' . $e->getMessage());
        }

        return redirect()->route('admin.messages.show', $conversation->id)
            ->with('success', 'Réponse envoyée.');
    }

    /**
     * Construit la vue liste + fil (avec marquage lu des messages entrants).
     */
    private function render(Request $request, $selectedId)
    {
        $support = SupportService::user();

        if (!$support) {
            return view('admin.messages.index', [
                'support' => null,
                'conversations' => collect(),
                'selected' => null,
                'messages' => collect(),
                'client' => null,
            ]);
        }

        // Conversations impliquant le support, triées par activité récente.
        $conversations = Conversation::with(['user1', 'user2', 'latestMessage', 'product', 'diaspoOffer'])
            ->where(function ($q) use ($support) {
                $q->where('user1_id', $support->id)->orWhere('user2_id', $support->id);
            })
            ->orderByRaw('COALESCE(last_message_at, created_at) DESC')
            ->get()
            ->map(function ($conv) use ($support) {
                $other = $conv->getOtherUser($support->id);
                $conv->setAttribute('other_user', $other);
                $conv->setAttribute('unread_count', $conv->messages()
                    ->where('sender_id', '!=', $support->id)
                    ->where('is_read', false)
                    ->count());
                return $conv;
            });

        $selected = null;
        $messages = collect();
        $client = null;

        if ($selectedId) {
            $selected = $conversations->firstWhere('id', (int) $selectedId);

            if ($selected) {
                // Marquer comme lus les messages ENTRANTS (émis par le client).
                Message::where('conversation_id', $selected->id)
                    ->where('sender_id', '!=', $support->id)
                    ->where('is_read', false)
                    ->update(['is_read' => true, 'read_at' => now()]);

                $selected->setAttribute('unread_count', 0);

                $messages = Message::with('sender')
                    ->where('conversation_id', $selected->id)
                    ->orderBy('created_at', 'asc')
                    ->get();

                $client = $selected->getOtherUser($support->id);
            }
        }

        return view('admin.messages.index', [
            'support' => $support,
            'conversations' => $conversations,
            'selected' => $selected,
            'messages' => $messages,
            'client' => $client,
        ]);
    }
}
