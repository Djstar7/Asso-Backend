<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * P8 — Voice of Customer : publications, réactions et commentaires réellement persistés.
 */
class VoiceOfCustomerTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->author = User::factory()->create(['role' => 'client', 'first_name' => 'Awa']);
        $this->reader = User::factory()->create(['role' => 'client']);
    }

    private function createPost(bool $anonymous = false): int
    {
        Sanctum::actingAs($this->author);

        return $this->postJson('/api/v1/posts', ['content' => 'Super service ASSO', 'is_anonymous' => $anonymous])
            ->assertCreated()
            ->assertJsonPath('data.is_my_post', true)
            ->json('data.id');
    }

    public function test_post_is_persisted_and_listed_for_everyone(): void
    {
        $id = $this->createPost();

        Sanctum::actingAs($this->reader);
        $this->getJson('/api/v1/posts')
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $id)
            ->assertJsonPath('data.data.0.user.first_name', 'Awa')
            ->assertJsonPath('data.data.0.is_my_post', false);

        Sanctum::actingAs($this->author);
        $this->getJson('/api/v1/posts/my-posts')->assertOk()->assertJsonPath('data.total', 1);
    }

    public function test_empty_or_too_long_message_is_rejected_with_a_readable_message(): void
    {
        Sanctum::actingAs($this->author);

        $this->postJson('/api/v1/posts', ['content' => ''])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Le message ne peut pas être vide.');

        $this->postJson('/api/v1/posts', ['content' => str_repeat('a', 5001)])->assertStatus(422);
    }

    public function test_anonymous_post_hides_author_to_others_only(): void
    {
        $id = $this->createPost(true);

        Sanctum::actingAs($this->reader);
        $this->getJson("/api/v1/posts/{$id}")
            ->assertOk()
            ->assertJsonPath('data.user', null)
            ->assertJsonPath('data.user_id', null);

        Sanctum::actingAs($this->author);
        $this->getJson("/api/v1/posts/{$id}")->assertJsonPath('data.user.first_name', 'Awa');
    }

    public function test_reactions_are_persisted_toggled_and_switched(): void
    {
        $id = $this->createPost();
        Sanctum::actingAs($this->reader);

        $this->postJson("/api/v1/posts/{$id}/react", ['type' => 'like'])
            ->assertJsonPath('data.likes_count', 1)
            ->assertJsonPath('data.user_reaction', 'like');

        $this->postJson("/api/v1/posts/{$id}/react", ['type' => 'dislike'])
            ->assertJsonPath('data.likes_count', 0)
            ->assertJsonPath('data.dislikes_count', 1)
            ->assertJsonPath('data.user_reaction', 'dislike');

        $this->postJson("/api/v1/posts/{$id}/react", ['type' => 'dislike'])
            ->assertJsonPath('data.dislikes_count', 0)
            ->assertJsonPath('data.user_reaction', null);

        $this->postJson("/api/v1/posts/{$id}/react", ['type' => 'like']);
        $this->getJson('/api/v1/posts')
            ->assertJsonPath('data.data.0.likes_count', 1)
            ->assertJsonPath('data.data.0.is_liked', true);
    }

    public function test_only_the_author_can_edit_or_delete(): void
    {
        $id = $this->createPost();

        Sanctum::actingAs($this->reader);
        $this->putJson("/api/v1/posts/{$id}", ['content' => 'Piraté'])->assertForbidden();
        $this->deleteJson("/api/v1/posts/{$id}")->assertForbidden();

        Sanctum::actingAs($this->author);
        $this->putJson("/api/v1/posts/{$id}", ['content' => 'Modifié'])->assertOk()->assertJsonPath('data.content', 'Modifié');
        $this->deleteJson("/api/v1/posts/{$id}")->assertOk();
        $this->assertSoftDeleted('posts', ['id' => $id]);
        $this->getJson('/api/v1/posts')->assertJsonPath('data.total', 0);
    }

    public function test_comments_and_replies_are_added_counted_and_anonymised(): void
    {
        $id = $this->createPost();

        Sanctum::actingAs($this->reader);
        $commentId = $this->postJson("/api/v1/posts/{$id}/comments", ['content' => 'Je confirme', 'is_anonymous' => true])
            ->assertCreated()
            ->assertJsonPath('comments_count', 1)
            ->json('data.id');

        Sanctum::actingAs($this->author);
        $replyId = $this->postJson("/api/v1/posts/{$id}/comments", ['content' => 'Merci !', 'parent_id' => $commentId])
            ->assertCreated()
            ->assertJsonPath('data.parent_id', $commentId)
            ->assertJsonPath('comments_count', 2)
            ->json('data.id');

        // Répondre à une réponse rattache au même fil.
        $this->postJson("/api/v1/posts/{$id}/comments", ['content' => 'Encore', 'parent_id' => $replyId])
            ->assertJsonPath('data.parent_id', $commentId);

        $this->getJson("/api/v1/posts/{$id}/comments")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user.first_name', 'Anonyme')
            ->assertJsonPath('data.0.user_id', null)
            ->assertJsonCount(2, 'data.0.replies');

        $this->assertSame(3, Post::find($id)->comments_count);

        Sanctum::actingAs($this->reader);
        $this->deleteJson("/api/v1/posts/{$id}/comments/{$commentId}")
            ->assertOk()
            ->assertJsonPath('comments_count', 0);
    }

    public function test_comment_likes_toggle(): void
    {
        $id = $this->createPost();
        $commentId = $this->postJson("/api/v1/posts/{$id}/comments", ['content' => 'Top'])->json('data.id');

        Sanctum::actingAs($this->reader);
        $this->postJson("/api/v1/posts/{$id}/comments/{$commentId}/react")->assertJsonPath('data.likes_count', 1);
        $this->postJson("/api/v1/posts/{$id}/comments/{$commentId}/react")->assertJsonPath('data.likes_count', 0);
    }

    public function test_admin_comment_deletion_does_not_double_decrement(): void
    {
        $id = $this->createPost();
        $this->postJson("/api/v1/posts/{$id}/comments", ['content' => 'Un']);
        $commentId = $this->postJson("/api/v1/posts/{$id}/comments", ['content' => 'Deux'])->json('data.id');

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)
            ->delete("/admin/diaspo/posts/{$id}/comments/{$commentId}")
            ->assertRedirect();

        $this->assertSame(1, Post::find($id)->comments_count);
        $this->actingAs($admin)->get('/admin/diaspo/posts')->assertOk()->assertSee('Posts DIASPO');
    }
}
