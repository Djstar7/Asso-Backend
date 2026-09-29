<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileAvatarTest extends TestCase
{
    use RefreshDatabase;

    /** L'application envoie la photo en multipart : POST + _method=PUT. */
    private function sendAvatar(): \Illuminate\Testing\TestResponse
    {
        return $this->post('/api/v1/auth/profile', [
            '_method' => 'PUT',
            'first_name' => 'Awa',
            'last_name' => 'Ndiaye',
            'avatar' => UploadedFile::fake()->image('photo.jpg', 400, 400),
        ], ['Accept' => 'application/json']);
    }

    public function test_the_photo_is_stored_and_returned_with_the_profile(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($user = User::factory()->create());

        $response = $this->sendAvatar()->assertOk();

        $path = $response->json('user.avatar');
        $this->assertStringStartsWith('avatars/', $path);
        Storage::disk('public')->assertExists($path);
        $this->assertSame($path, $user->fresh()->avatar);
    }

    public function test_a_new_photo_removes_the_previous_file(): void
    {
        Storage::fake('public');
        $old = UploadedFile::fake()->image('old.jpg')->store('avatars', 'public');
        Sanctum::actingAs(User::factory()->create(['avatar' => $old]));

        $new = $this->sendAvatar()->assertOk()->json('user.avatar');

        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertExists($new);
    }

    public function test_a_photo_outside_the_avatars_folder_is_left_alone(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('shops/logo.jpg', 'x');
        $user = User::factory()->create(['avatar' => 'shops/logo.jpg']);

        $user->update(['avatar' => 'avatars/new.jpg']);

        Storage::disk('public')->assertExists('shops/logo.jpg');
    }

    public function test_updating_other_fields_keeps_the_photo(): void
    {
        Storage::fake('public');
        $path = UploadedFile::fake()->image('p.jpg')->store('avatars', 'public');
        $user = User::factory()->create(['avatar' => $path]);

        $user->update(['first_name' => 'Awa']);

        Storage::disk('public')->assertExists($path);
    }
}
