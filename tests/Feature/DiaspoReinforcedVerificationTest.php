<?php

namespace Tests\Feature;

use App\Models\DiaspoIdentityDocument;
use App\Models\User;
use App\Services\DiaspoVerificationService;
use App\Services\FirebaseMessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Vérification renforcée du voyageur avion : pièces exigées selon nationalité
 * et résidence, selfie, justificatif de voyage par offre ; l'ancien format
 * (CNI/passeport recto-verso) reste accepté pour les anciennes versions.
 */
class DiaspoReinforcedVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->mock(FirebaseMessagingService::class, fn ($m) => $m->shouldReceive('sendToUser', 'sendToTopicsLocalized')->andReturn([]));
    }

    private function img(string $name = 'p.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name);
    }

    public function test_required_documents_depend_on_residence(): void
    {
        $home = DiaspoVerificationService::requiredDocuments('CM', 'CM');
        $this->assertEquals(['cni', 'passport'], $home['identity']);
        $this->assertEquals([], $home['residence']);

        $abroad = DiaspoVerificationService::requiredDocuments('CM', 'FR');
        $this->assertEquals(['passport'], $abroad['identity']);
        $this->assertEquals(['residence_permit', 'visa'], $abroad['residence']);
        $this->assertTrue($abroad['selfie']);
    }

    public function test_resident_abroad_must_send_passport_residence_document_and_selfie(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // CNI refusée pour un résident à l'étranger, titre de séjour et selfie manquants.
        $this->postJson('/api/v1/diaspo/upload-verification', [
            'nationality' => 'CM', 'residence_country' => 'FR',
            'document_type' => 'cni', 'document_front' => $this->img(), 'document_back' => $this->img(),
        ])->assertStatus(422)->assertJsonValidationErrors(['document_type', 'residence_document', 'selfie']);

        $this->postJson('/api/v1/diaspo/upload-verification', [
            'nationality' => 'CM', 'residence_country' => 'FR',
            'document_type' => 'passport', 'document_front' => $this->img(),
            'residence_document_type' => 'residence_permit', 'residence_document' => $this->img(),
            'selfie' => $this->img('selfie.jpg'),
        ])->assertOk();

        $user->refresh();
        $this->assertEquals('pending', $user->diaspo_verification_status);
        $this->assertEquals('FR', $user->residence_country);
        $this->assertEqualsCanonicalizing(
            ['passport', 'residence_permit', 'selfie'],
            DiaspoIdentityDocument::where('user_id', $user->id)->pluck('type')->all()
        );

        $this->getJson('/api/v1/diaspo/verification-status')
            ->assertJsonPath('data.nationality', 'CM')
            ->assertJsonPath('data.required_documents.identity', ['passport'])
            ->assertJsonCount(3, 'data.documents');
    }

    public function test_legacy_upload_still_accepted(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/diaspo/upload-verification', [
            'document_type' => 'cni', 'document_front' => $this->img(), 'document_back' => $this->img(),
        ])->assertOk();

        $this->assertEquals('pending', $user->fresh()->diaspo_verification_status);
        $this->assertEquals(2, DiaspoIdentityDocument::where('user_id', $user->id)->count());
    }

    public function test_offer_keeps_travel_proof_and_admin_sees_documents(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/diaspo/upload-verification', [
            'nationality' => 'CM', 'residence_country' => 'CM',
            'document_type' => 'cni', 'document_front' => $this->img(), 'document_back' => $this->img(),
            'selfie' => $this->img('selfie.jpg'),
        ])->assertOk();

        $offerId = $this->post('/api/v1/diaspo/offers', [
            'departure_country' => 'CM', 'departure_city' => 'Douala', 'departure_datetime' => now()->addDays(5)->toDateTimeString(),
            'arrival_country' => 'FR', 'arrival_city' => 'Paris', 'arrival_datetime' => now()->addDays(6)->toDateTimeString(),
            'price_per_kg' => 10, 'available_kg' => 20, 'currency' => 'EUR',
            'travel_proof' => UploadedFile::fake()->create('billet.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(201)->json('data.id');

        $this->assertDatabaseHas('diaspo_identity_documents', ['user_id' => $user->id, 'type' => 'travel_proof', 'diaspo_offer_id' => $offerId]);

        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get(route('admin.diaspo.verifications.show', $user->id))
            ->assertOk()
            ->assertSee('Pièces complémentaires')
            ->assertSee('Selfie')
            ->assertSee('Justificatif de voyage');

        app(DiaspoVerificationService::class)->approveIdentity($user, null);
        $this->assertEquals(0, DiaspoIdentityDocument::where('user_id', $user->id)->where('type', '!=', 'travel_proof')->where('status', 'pending')->count());
    }
}
