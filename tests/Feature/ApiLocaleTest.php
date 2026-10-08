<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_validation_errors_follow_accept_language(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->putJson('/api/v1/auth/locale', [], ['Accept-Language' => 'fr'])
            ->assertStatus(422)
            ->assertJsonPath('errors.locale.0', 'Le champ langue est obligatoire.');

        $this->putJson('/api/v1/auth/locale', [], ['Accept-Language' => 'en-US,en;q=0.9'])
            ->assertStatus(422)
            ->assertJsonPath('errors.locale.0', 'The language field is required.');
    }

    public function test_without_header_the_account_language_is_used(): void
    {
        Sanctum::actingAs(User::factory()->create(['locale' => 'en']));

        $this->withHeader('Accept-Language', '')
            ->putJson('/api/v1/auth/locale', [])
            ->assertJsonPath('errors.locale.0', 'The language field is required.');
    }

    public function test_unsupported_language_falls_back_to_french(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->putJson('/api/v1/auth/locale', [], ['Accept-Language' => 'de'])
            ->assertJsonPath('errors.locale.0', 'Le champ langue est obligatoire.');
    }

    public function test_user_can_change_account_language(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/auth/locale', ['locale' => 'en'])
            ->assertOk()
            ->assertJsonPath('locale', 'en');
        $this->assertSame('en', $user->fresh()->locale);

        $this->putJson('/api/v1/auth/locale', ['locale' => 'de'])->assertStatus(422);
    }

    public function test_user_translate_uses_the_recipient_language(): void
    {
        app()->setLocale('fr');
        $english = User::factory()->make(['locale' => 'en']);

        $this->assertSame(
            'The phone number field is required.',
            $english->translate('validation.required', ['attribute' => 'phone number']),
        );
    }

    public function test_api_messages_follow_accept_language(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/posts', ['content' => ''], ['Accept-Language' => 'fr'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Le message ne peut pas être vide.');

        $this->postJson('/api/v1/posts', ['content' => ''], ['Accept-Language' => 'en'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The message cannot be empty.');
    }

    public function test_push_texts_use_the_recipient_language(): void
    {
        app()->setLocale('en');
        $french = User::factory()->make(['locale' => 'fr']);

        $this->assertSame(
            __('notifications.order_rated.title', [], 'fr'),
            $french->translate('notifications.order_rated.title'),
        );
        $this->assertNotSame(
            __('notifications.order_rated.title', [], 'en'),
            $french->translate('notifications.order_rated.title'),
        );
    }
}
