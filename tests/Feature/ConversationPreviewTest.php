<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConversationPreviewTest extends TestCase
{
    use RefreshDatabase;

    private function conversation(): Conversation
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Sanctum::actingAs($user);

        return Conversation::withoutEvents(fn () => Conversation::create([
            'user1_id' => $user->id,
            'user2_id' => $other->id,
        ]));
    }

    public function test_last_photo_exposes_its_url_and_caption(): void
    {
        $conversation = $this->conversation();
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $conversation->user2_id,
            'message' => 'Voici votre commande',
            'image_path' => 'messages/photo.jpg',
        ]);

        $this->getJson('/api/v1/conversations')->assertOk()
            ->assertJsonPath('conversations.0.last_message.message', 'Voici votre commande')
            ->assertJsonPath('conversations.0.last_message.image_path', media_url('messages/photo.jpg'));
    }

    public function test_text_after_a_photo_has_no_image_preview(): void
    {
        $conversation = $this->conversation();
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $conversation->user2_id,
            'message' => '',
            'image_path' => 'messages/photo.jpg',
        ]);
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $conversation->user1_id,
            'message' => 'Merci, bien reçu',
        ]);

        $this->getJson('/api/v1/conversations')->assertOk()
            ->assertJsonPath('conversations.0.last_message.message', 'Merci, bien reçu')
            ->assertJsonPath('conversations.0.last_message.image_path', null);
    }

    public function test_an_empty_conversation_has_no_last_message(): void
    {
        $this->conversation();

        $this->getJson('/api/v1/conversations')->assertOk()
            ->assertJsonPath('conversations.0.last_message', null);
    }
}
