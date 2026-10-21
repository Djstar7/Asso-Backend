<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class LandlordUploadValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_storage_failure_identifies_the_document_instead_of_returning_a_server_error(): void
    {
        $user = User::factory()->create();
        $front = \Mockery::mock(UploadedFile::class);
        $front->shouldReceive('store')->once()->andReturn(false);
        try {
            app(\App\Services\RealEstate\LandlordVerificationService::class)->submit($user, 'passport', 'other', [
                'id_front' => $front,
            ]);
            $this->fail('Le refus de stockage doit être signalé sur le fichier concerné.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('id_front', $e->errors());
            $this->assertDatabaseCount('landlord_verifications', 0);
        }
    }

    public function test_missing_documents_have_explicit_field_errors_and_cni_requires_a_back(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->postJson('/api/v1/real-estate/landlord/verification', [
            'id_document_type' => 'cni', 'ownership_proof_type' => 'other',
        ], ['Accept-Language' => 'fr'])->assertUnprocessable()
            ->assertJsonValidationErrors(['id_front', 'id_back', 'ownership_proof', 'video'])
            ->assertJsonPath('errors.id_back.0', __('landlord_upload.id_back'));

        $this->postJson('/api/v1/real-estate/landlord/verification', [
            'id_document_type' => 'passport', 'ownership_proof_type' => 'other',
        ])->assertUnprocessable()->assertJsonMissingValidationErrors(['id_back']);
    }

    public function test_oversized_video_is_reported_under_the_video_field(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->post('/api/v1/real-estate/landlord/verification', [
            'id_document_type' => 'passport',
            'id_front' => UploadedFile::fake()->image('passport.jpg'),
            'ownership_proof_type' => 'other',
            'ownership_proof' => UploadedFile::fake()->create('proof.pdf', 100, 'application/pdf'),
            'video' => UploadedFile::fake()->create('verification.mp4', 102401, 'video/mp4'),
        ], ['Accept' => 'application/json', 'Accept-Language' => 'fr'])
            ->assertUnprocessable()->assertJsonValidationErrors(['video'])
            ->assertJsonPath('errors.video.0', __('landlord_upload.video_size'));
    }
}
