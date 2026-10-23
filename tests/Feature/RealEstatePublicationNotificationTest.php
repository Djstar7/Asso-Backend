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
        $this->mock(FirebaseMessagingService::class)->shouldReceive('sendToTopic')->times(3)->andReturn(['success' => true]);
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
        $calls = 0;
        $this->mock(FirebaseMessagingService::class)->shouldReceive('sendToTopic')->times(3)
            ->andReturnUsing(function () use (&$calls) {
                $calls++;

                return ['success' => true];
            });
        DB::beginTransaction();
        Listing::create(['type' => 'rent', 'title' => 'Logement', 'city' => 'Douala', 'status' => 'published']);
        $this->assertSame(0, $calls);
        DB::rollBack();
        $this->assertSame(0, $calls);
        DB::transaction(function () use (&$calls) {
            Listing::create(['type' => 'rent', 'title' => 'Logement', 'city' => 'Douala', 'status' => 'published']);
            $this->assertSame(0, $calls);
        });
        $this->assertSame(3, $calls);
    }

    public function test_firebase_outage_does_not_block_publication_and_scheduler_can_retry(): void
    {
        $this->mock(FirebaseMessagingService::class)->shouldReceive('sendToTopic')->once()
            ->andThrow(new \RuntimeException('FCM indisponible'));
        $listing = Listing::create(['type' => 'land', 'title' => 'Terrain', 'city' => 'Yaoundé', 'status' => 'published']);
        $this->assertSame('published', $listing->fresh()->status);
        $this->assertNull($listing->fresh()->announced_at);
        $this->mock(FirebaseMessagingService::class)->shouldReceive('sendToTopic')->times(3)
            ->andReturn(['success' => true]);
        $this->artisan('immo:notify-publications')->assertSuccessful();
        $this->assertNotNull($listing->fresh()->announced_at);
    }
}
