<?php

namespace App\Services;

use App\Events\MessageSent;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FreightInquiryMessaging
{
    public static function notify(Message $message, User $recipient): void
    {
        DB::afterCommit(function () use ($message, $recipient) {
            try {
                broadcast(new MessageSent($message));
            } catch (\Throwable $e) {
                Log::warning('Freight quote message broadcast failed', ['message' => $message->id, 'error' => $e->getMessage()]);
            }
            try {
                $sender = $message->sender;
                app(FirebaseMessagingService::class)->sendToUser($recipient, $sender->name, mb_substr($message->message, 0, 150), [
                    'type' => 'new_message', 'conversation_id' => (string) $message->conversation_id,
                    'sender_id' => (string) $sender->id, 'sender_name' => $sender->name,
                    'message_id' => (string) $message->id, 'screen' => 'chatdetail',
                    'notification_key' => 'freight-message:'.$message->id,
                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                ]);
            } catch (\Throwable $e) {
                Log::warning('Freight quote push failed', ['message' => $message->id, 'error' => $e->getMessage()]);
            }
        });
    }
}
