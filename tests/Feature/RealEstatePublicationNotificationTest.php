<?php

namespace Tests\Feature;

use App\Models\RealEstate\Cabinet;
use App\Models\RealEstate\Listing;
use App\Models\User;
use App\Services\FirebaseMessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RealEstatePublicationNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_admin_publication_announces_cabinet_land_once_without_an_owner(): void
    {
        $this->mock(FirebaseMessagingService::class)->shouldNotReceive('sendToTopic');
        $cabinet = Cabinet::create(['name' => 'GWODA', 'user_id' => User::factory()->create()->id]);
        $listing = Listing::create([
            'type' => 'land', 'title' => 'Terrain', 'city' => 'Yaoundé',
            'cabinet_id' => $cabinet->id, 'status' => 'draft',
        ]);
        $listing->units()->create(['label' => 'Lot', 'net_price' => 40000000]);
        $listing->media()->create(['path' => 'real_estate/photo.jpg', 'type' => 'image']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->post(route('admin.real-estate.listings.approve', $listing))->assertRedirect()->assertSessionHas('success');
        $this->assertNotNull($listing->fresh()->announced_at);
        $this->post(route('admin.real-estate.listings.approve', $listing))->assertRedirect();
        $listing->refresh()->update(['title' => 'Titre corrigé']);
    }

    public function test_publication_inside_transaction_waits_for_commit_and_rollback_sends_nothing(): void
    {
        $this->mock(FirebaseMessagingService::class)->shouldNotReceive('sendToTopic');
        DB::beginTransaction();
        $rolledBack = Listing::create(['type' => 'rent', 'title' => 'Logement', 'city' => 'Douala', 'status' => 'published']);
        $this->assertNull($rolledBack->fresh()->announced_at);
        DB::rollBack();
        $this->assertDatabaseMissing('real_estate_listings', ['id' => $rolledBack->id]);
        $published = DB::transaction(function () {
            $listing = Listing::create(['type' => 'rent', 'title' => 'Logement', 'city' => 'Douala', 'status' => 'published']);
            $this->assertNull($listing->fresh()->announced_at);

            return $listing;
        });
        $this->assertNotNull($published->fresh()->announced_at);
        $this->assertSame([], $published->fresh()->announced_topics);
    }

    public function test_publication_is_independent_of_firebase_availability(): void
    {
        $this->mock(FirebaseMessagingService::class)->shouldNotReceive('sendToTopic');
        $listing = Listing::create(['type' => 'land', 'title' => 'Terrain', 'city' => 'Yaoundé', 'status' => 'published']);
        $this->assertSame('published', $listing->fresh()->status);
        $this->artisan('immo:notify-publications')->assertSuccessful();
        $this->assertNotNull($listing->fresh()->announced_at);
        $this->assertSame([], $listing->fresh()->announced_topics);
    }
}
