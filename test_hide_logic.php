#!/usr/bin/env php
<?php

/**
 * Script de test pour vérifier la logique de hide des conversations
 *
 * Usage:
 *   php test_hide_logic.php <conversation_id> <user_id>
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Conversation;
use App\Models\ConversationHide;
use App\Models\Message;

// Récupérer les arguments
if ($argc < 3) {
    echo "Usage: php test_hide_logic.php <conversation_id> <user_id>\n";
    exit(1);
}

$conversationId = (int) $argv[1];
$userId = (int) $argv[2];

echo "========================================\n";
echo "TEST: Logique de Hide pour Conversation #{$conversationId}\n";
echo "Utilisateur: #{$userId}\n";
echo "========================================\n\n";

// 1. Vérifier si la conversation existe
$conversation = Conversation::find($conversationId);
if (!$conversation) {
    echo "❌ Conversation #{$conversationId} introuvable\n";
    exit(1);
}
echo "✅ Conversation trouvée\n\n";

// 2. Vérifier s'il y a une entrée de hide
$hideEntry = ConversationHide::where('conversation_id', $conversationId)
    ->where('user_id', $userId)
    ->first();

if ($hideEntry) {
    echo "🙈 HIDE TROUVÉ:\n";
    echo "   └─ Date du hide: {$hideEntry->created_at->toIso8601String()}\n";
    echo "   └─ ID: {$hideEntry->id}\n\n";
} else {
    echo "👁️  Pas de hide pour cet utilisateur\n\n";
}

// 3. Compter tous les messages
$totalMessages = Message::where('conversation_id', $conversationId)->count();
echo "📊 Total messages dans la conversation: {$totalMessages}\n\n";

// 4. Si hide existe, compter les messages avant et après
if ($hideEntry) {
    $messagesBeforeHide = Message::where('conversation_id', $conversationId)
        ->where('created_at', '<=', $hideEntry->created_at)
        ->count();

    $messagesAfterHide = Message::where('conversation_id', $conversationId)
        ->where('created_at', '>', $hideEntry->created_at)
        ->count();

    echo "📧 Messages AVANT le hide (cachés): {$messagesBeforeHide}\n";
    echo "📧 Messages APRÈS le hide (visibles): {$messagesAfterHide}\n\n";

    // Afficher les messages après le hide
    if ($messagesAfterHide > 0) {
        echo "📝 Messages visibles (créés après {$hideEntry->created_at->toIso8601String()}):\n";
        $visibleMessages = Message::where('conversation_id', $conversationId)
            ->where('created_at', '>', $hideEntry->created_at)
            ->orderBy('created_at', 'asc')
            ->get();

        foreach ($visibleMessages as $msg) {
            $isSystem = $msg->is_system ? '🤖 SYSTÈME' : '💬 USER';
            $preview = substr($msg->message, 0, 50);
            echo "   {$isSystem} [{$msg->created_at->toIso8601String()}] {$preview}\n";
        }
    }
}

echo "\n========================================\n";
echo "✅ Test terminé\n";
echo "========================================\n";
