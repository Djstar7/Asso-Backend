<?php

namespace Tests\Feature;

use App\Models\RealEstate\Cabinet;
use App\Models\RealEstate\Listing;
use App\Models\User;
use App\Services\RealEstate\ListingEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RealEstateVideoUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('public');
    }

    private function land(): Listing
    {
        return Listing::create(['type' => 'land', 'title' => 'Terrain', 'city' => 'Yaoundé', 'status' => 'draft']);
    }

    public function test_admin_can_upload_land_videos_independently_and_latest_is_presented(): void
    {
        $land = $this->land();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $latestId = null;
        foreach (['mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'm4v' => 'video/x-m4v', '3gp' => 'video/3gpp', 'webm' => 'video/webm'] as $extension => $mime) {
            $response = $this->post(route('admin.real-estate.listings.video.store', $land), [
                'video' => UploadedFile::fake()->create('terrain.'.$extension, 10, $mime),
            ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('success', true)->assertJsonPath('media.type', 'video');
            $latestId = $response->json('media.id');
            Storage::disk('public')->assertExists($land->media()->findOrFail($latestId)->path);
        }
        $this->assertDatabaseCount('real_estate_media', 5);
        $this->assertSame($latestId, $land->fresh()->toDetailApi()['video']['id']);
        $this->assertSame('Terrain', $land->fresh()->title);
    }

    public function test_invalid_or_oversized_video_never_creates_a_media_record(): void
    {
        $land = $this->land();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach ([UploadedFile::fake()->create('document.pdf', 10, 'application/pdf'), UploadedFile::fake()->create('big.mp4', 102401, 'video/mp4')] as $file) {
            $this->post(route('admin.real-estate.listings.video.store', $land), ['video' => $file], ['Accept' => 'application/json'])
                ->assertUnprocessable()->assertJsonValidationErrors('video');
        }
        $this->assertDatabaseCount('real_estate_media', 0);
    }

    public function test_invalid_video_in_full_form_is_rejected_before_changing_the_land(): void
    {
        $land = $this->land();
        $cabinet = Cabinet::create(['name' => 'Partenaire']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post(route('admin.real-estate.listings.update', $land), [
                '_method' => 'PUT', 'title' => 'Titre à ne pas enregistrer', 'property_kind' => 'building_land',
                'city' => 'Yaoundé', 'cabinet_id' => $cabinet->id,
                'video' => UploadedFile::fake()->create('document.pdf', 10, 'application/pdf'),
            ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('video');
        $this->assertSame('Terrain', $land->fresh()->title);
        $this->assertDatabaseCount('real_estate_media', 0);
    }

    public function test_storage_failure_is_reported_instead_of_creating_an_empty_video(): void
    {
        $land = $this->land();
        $file = \Mockery::mock(UploadedFile::class);
        $file->shouldReceive('getMimeType')->andReturn('video/mp4');
        $file->shouldReceive('store')->once()->andReturn(false);
        try {
            app(ListingEditor::class)->addMedia($land, $file, field: 'video');
            $this->fail('Un refus de stockage doit signaler une erreur.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('video', $exception->errors());
        }
        $this->assertDatabaseCount('real_estate_media', 0);
    }

    public function test_customer_cannot_upload_video_through_admin_route(): void
    {
        $land = $this->land();
        $this->actingAs(User::factory()->create(['role' => 'customer']))
            ->post(route('admin.real-estate.listings.video.store', $land), [
                'video' => UploadedFile::fake()->create('terrain.mp4', 10, 'video/mp4'),
            ])->assertRedirect(route('admin.login'));
        $this->assertDatabaseCount('real_estate_media', 0);
    }
}
