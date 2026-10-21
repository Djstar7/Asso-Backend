<?php

namespace Tests\Feature;

use App\Models\RealEstate\Cabinet;
use App\Models\RealEstate\LandlordVerification;
use App\Models\RealEstate\Listing;
use App\Models\User;
use App\Services\FirebaseMessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Rubrique Immobilier, lot 2 : vérification du bailleur, publication de ses
 * maisons, relecture et saisie des terrains dans le back-office.
 */
class RealEstateLandlordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('public');
        Storage::fake('local');
        $this->mock(FirebaseMessagingService::class, fn ($m) => $m->shouldReceive('sendToUser')->andReturn([]));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function sendDocuments(User $user): void
    {
        Sanctum::actingAs($user);
        $this->post('/api/v1/real-estate/landlord/verification', [
            'id_document_type' => 'cni',
            'id_front' => UploadedFile::fake()->image('recto.jpg'),
            'id_back' => UploadedFile::fake()->image('verso.jpg'),
            'ownership_proof_type' => 'land_title',
            'ownership_proof' => UploadedFile::fake()->create('titre.pdf', 200, 'application/pdf'),
            'video' => UploadedFile::fake()->create('video.mp4', 3000, 'video/mp4'),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.status', 'pending');
    }

    private function housePayload(): array
    {
        return [
            'property_kind' => 'house', 'title' => 'Maison à Odza', 'city' => 'Yaoundé', 'district' => 'Odza',
            'rooms' => 3, 'bedrooms' => 2, 'net_price' => 80000, 'advance_months' => 3, 'min_lease_months' => 12,
            'road_access' => 'near_road', 'distance_to_road_m' => 150, 'water_source' => 'borehole',
            'rooms_tour' => [['kind' => 'entrance', 'label' => 'Entrée'], ['kind' => 'living']],
        ];
    }

    public function test_listing_requires_documents_first(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/real-estate/landlord/listings', $this->housePayload())->assertForbidden();
    }

    public function test_landlord_publishes_after_review(): void
    {
        Cabinet::create(['name' => 'Cabinet partenaire', 'user_id' => User::factory()->create()->id]);
        $landlord = User::factory()->create();
        $this->sendDocuments($landlord);
        $verification = LandlordVerification::where('user_id', $landlord->id)->firstOrFail();
        Storage::disk('local')->assertExists($verification->video_path);

        $res = $this->postJson('/api/v1/real-estate/landlord/listings', $this->housePayload())->assertCreated();
        $id = $res->json('data.id');
        $this->assertSame('draft', $res->json('data.status'));
        $this->assertContains('entrance_photo', $res->json('data.missing'));

        // Envoi refusé tant que la visite n'est pas complète.
        $this->postJson("/api/v1/real-estate/landlord/listings/$id/submit")->assertStatus(422)
            ->assertJsonFragment(['missing' => ['photos', 'entrance_photo', 'room_photos', 'video']]);

        $rooms = Listing::find($id)->tourRooms;
        foreach ($rooms as $room) {
            $this->post("/api/v1/real-estate/landlord/listings/$id/media", [
                'file' => UploadedFile::fake()->image("{$room->kind}.jpg"), 'room_id' => $room->id,
            ], ['Accept' => 'application/json'])->assertCreated();
        }
        $this->post("/api/v1/real-estate/landlord/listings/$id/media", [
            'file' => UploadedFile::fake()->create('visite.mp4', 2000, 'video/mp4'),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.type', 'video');

        $this->postJson("/api/v1/real-estate/landlord/listings/$id/submit")->assertOk()
            ->assertJsonPath('data.status', 'pending_review');

        // Le bailleur n'est pas encore vérifié : l'admin ne peut pas publier.
        $admin = $this->admin();
        $this->actingAs($admin)->post("/admin/real-estate/listings/$id/approve")->assertRedirect();
        $this->assertSame('pending_review', Listing::find($id)->status);

        $this->actingAs($admin)->post("/admin/real-estate/landlords/{$verification->id}/approve")->assertRedirect();
        $this->assertSame('verified', $landlord->fresh()->landlord_verification_status);

        $this->actingAs($admin)->post("/admin/real-estate/listings/$id/approve")->assertRedirect();
        $this->assertSame('published', Listing::find($id)->status);
        $this->getJson('/api/v1/real-estate/listings')->assertJsonPath('data.0.id', $id);

        // Une modification renvoie l'annonce en relecture.
        Sanctum::actingAs($landlord);
        $this->putJson("/api/v1/real-estate/landlord/listings/$id", ['net_price' => 90000])->assertOk()
            ->assertJsonPath('data.status', 'pending_review');
    }

    public function test_admin_rejects_documents_with_reason(): void
    {
        $landlord = User::factory()->create();
        $this->sendDocuments($landlord);
        $verification = LandlordVerification::where('user_id', $landlord->id)->firstOrFail();

        $this->actingAs($this->admin())->get("/admin/real-estate/landlords/{$verification->id}")->assertOk();
        $this->actingAs($this->admin())->get("/admin/real-estate/landlords/{$verification->id}/documents/video")->assertOk();
        $this->actingAs($this->admin())->post("/admin/real-estate/landlords/{$verification->id}/reject", ['reason' => 'CNI illisible'])->assertRedirect();

        Sanctum::actingAs($landlord->fresh());
        $this->getJson('/api/v1/real-estate/landlord/verification')
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'CNI illisible')
            ->assertJsonPath('data.can_publish', false);
    }

    public function test_admin_creates_cabinet_and_bulk_lands(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/admin/real-estate/cabinets', [
            'name' => 'Cabinet Gwoda et Associés', 'email' => 'cabinetgwoda@example.com', 'phone' => '698884810',
            'why_choose_us' => "Sélection de terrains disponibles\nAccompagnement dans les démarches foncières",
        ])->assertRedirect();
        $cabinet = Cabinet::firstOrFail();
        $this->assertNotNull($cabinet->user_id);
        $this->assertCount(2, $cabinet->why_choose_us);

        $this->actingAs($admin)->post('/admin/real-estate/listings/bulk-lands', [
            'cabinet_id' => $cabinet->id, 'city' => 'Yaoundé', 'deposit_percent' => 10,
            'rows' => [
                ['district' => 'Nkoufoulou', 'surfaces' => '300, 400, 800, 1 200', 'price' => 10000, 'price_mode' => 'per_m2', 'titled' => 1],
                ['district' => 'Soa', 'surfaces' => '400, 500', 'price' => 5000000, 'price_mode' => 'per_lot'],
                ['district' => '', 'surfaces' => '', 'price' => null],
            ],
        ])->assertRedirect();

        $lands = Listing::where('cabinet_id', $cabinet->id)->with('units')->get();
        $this->assertCount(2, $lands);
        $nkoufoulou = $lands->firstWhere('district', 'Nkoufoulou');
        $this->assertTrue($nkoufoulou->land_titled);
        $this->assertSame([300, 400, 800, 1200], $nkoufoulou->units->pluck('surface_min')->all());
        $this->assertSame(3000000, $nkoufoulou->units->first()->net_price);
        $this->assertSame(3000000, $nkoufoulou->net_price);

        // Sans photo, le terrain ne peut pas être publié.
        $this->actingAs($admin)->post("/admin/real-estate/listings/{$nkoufoulou->id}/approve");
        $this->assertSame('draft', $nkoufoulou->fresh()->status);

        $this->actingAs($admin)->put("/admin/real-estate/listings/{$nkoufoulou->id}", [
            'cabinet_id' => $cabinet->id, 'property_kind' => 'building_land', 'title' => 'Terrains à Nkoufoulou',
            'city' => 'Yaoundé', 'land_titled' => 1,
            'units' => $nkoufoulou->units->map->only(['id', 'label', 'surface_min', 'net_price'])->all(),
            'photos' => [UploadedFile::fake()->image('terrain.jpg')],
        ])->assertRedirect();
        $this->actingAs($admin)->post("/admin/real-estate/listings/{$nkoufoulou->id}/approve");
        $this->assertSame('published', $nkoufoulou->fresh()->status);
    }

    public function test_admin_pages_render(): void
    {
        $admin = $this->admin();
        $cabinet = Cabinet::create(['name' => 'Cabinet test']);
        $land = Listing::create(['type' => 'land', 'cabinet_id' => $cabinet->id, 'title' => 'Odza', 'city' => 'Yaoundé', 'status' => 'draft']);
        $land->units()->create(['label' => '400 m²', 'surface_min' => 400, 'net_price' => 4000000]);

        foreach ([
            '/admin/real-estate/listings', '/admin/real-estate/listings?type=land&status=draft', '/admin/real-estate/listings/create',
            '/admin/real-estate/listings/create?type=rent',
            "/admin/real-estate/listings/{$land->id}", '/admin/real-estate/cabinets', "/admin/real-estate/cabinets/{$cabinet->id}",
            '/admin/real-estate/landlords', '/admin/real-estate/settings',
        ] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }

        $this->actingAs($admin)->put('/admin/real-estate/settings', [
            'immo_asso_markup_rate' => 10, 'immo_partner_commission_rate' => 5, 'immo_visit_window_days' => 7,
            'immo_land_default_deposit_percent' => 10, 'immo_default_cabinet_id' => $cabinet->id,
            'immo_land_release_days' => 7, 'immo_low_stock_threshold' => 4, 'immo_popular_views' => 100,
        ])->assertRedirect();
        Cache::flush();
        $this->assertEquals(10, \App\Services\RealEstate\RealEstatePricingService::setting('immo_asso_markup_rate'));
        $this->assertSame($cabinet->id, (int) \App\Models\Setting::get('immo_default_cabinet_id'));
    }

    public function test_admin_can_create_a_house_linked_to_a_partner_cabinet(): void
    {
        $admin = $this->admin();
        $account = User::factory()->create();
        $cabinet = Cabinet::create(['name' => 'Cabinet test', 'user_id' => $account->id]);

        $this->actingAs($admin)->post('/admin/real-estate/listings', [
            'type' => 'rent',
            'cabinet_id' => $cabinet->id,
            'property_kind' => 'house',
            'title' => 'Maison partenaire',
            'city' => 'Yaoundé',
            'net_price' => 100000,
            'photo_room_kind' => 'entrance',
            'photos' => [UploadedFile::fake()->image('entree.jpg')],
            'video' => UploadedFile::fake()->create('visite.mp4', 100, 'video/mp4'),
        ])->assertRedirect();

        $this->assertDatabaseHas('real_estate_listings', [
            'title' => 'Maison partenaire',
            'type' => 'rent',
            'cabinet_id' => $cabinet->id,
            'owner_id' => null,
            'status' => 'draft',
        ]);
        $listing = Listing::where('title', 'Maison partenaire')->firstOrFail();
        $this->assertTrue($listing->tourRooms()->where('kind', 'entrance')->whereHas('media')->exists());
        $this->actingAs($admin)->post("/admin/real-estate/listings/{$listing->id}/approve")
            ->assertRedirect()->assertSessionHas('success');
        $this->assertSame('published', $listing->fresh()->status);
    }

    public function test_product_manager_cannot_open_real_estate_section(): void
    {
        $manager = User::factory()->create(['role' => 'product_manager', 'roles' => ['product_manager']]);
        $this->actingAs($manager)->get('/admin/real-estate/listings')->assertForbidden();
    }
}
