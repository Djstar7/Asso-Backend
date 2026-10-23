<?php

namespace Tests\Feature;

use App\Models\RealEstate\Cabinet;
use App\Models\RealEstate\Listing;
use App\Models\User;
use App\Services\FirebaseMessagingService;
use App\Services\RealEstate\ListingEditor;
use Database\Seeders\AudyHomeRealEstateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AudyHomeRealEstateSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->mock(FirebaseMessagingService::class)->shouldNotReceive('sendToTopic', 'sendToUser');
    }

    public function test_import_creates_one_draft_offer_and_prices_only_the_minimum_plot(): void
    {
        $this->seed(AudyHomeRealEstateSeeder::class);
        $cabinet = Cabinet::where('name', 'AUDY HOME')->sole();
        $listing = $cabinet->listings()->sole();
        $unit = $listing->units()->sole();
        $this->assertDatabaseCount('real_estate_listings', 1);
        $this->assertDatabaseCount('real_estate_units', 1);
        $this->assertDatabaseCount('real_estate_media', 0);
        $this->assertNotNull($cabinet->user);
        $this->assertFalse($cabinet->is_verified);
        $this->assertSame('AUDY-HOME-001', $listing->reference);
        $this->assertSame('Ngolié', $listing->city);
        $this->assertSame('Castongo', $listing->district);
        $this->assertSame(Listing::TYPE_LAND, $listing->type);
        $this->assertSame(Listing::STATUS_DRAFT, $listing->status);
        $this->assertNull($listing->published_at);
        $this->assertNull($listing->latitude);
        $this->assertNull($listing->longitude);
        $this->assertNull($listing->distance_to_road_m);
        $this->assertTrue($listing->land_titled);
        $this->assertSame(25000, $listing->surface_m2);
        $this->assertSame(1000, $unit->surface_min);
        $this->assertSame(1000, $unit->surface_max);
        $this->assertSame(2500000, $unit->net_price);
        $this->assertSame(2500000, $listing->minNetPrice());
        $this->assertSame(1, $unit->units_total);
        $this->assertSame(['photos'], app(ListingEditor::class)->missingForSubmission($listing));
        $this->assertStringNotContainsString('AUDY HOME', $listing->title.' '.$listing->owner_description);
        $this->assertStringNotContainsString('hôpital', $listing->owner_description);
    }

    public function test_rerun_reuses_partner_and_preserves_manual_edits_stock_and_photos(): void
    {
        $account = User::factory()->create();
        $cabinet = Cabinet::create(['name' => 'Cabinet Audy Home', 'user_id' => $account->id, 'phone' => 'CONTACT_EXISTANT']);
        $this->seed(AudyHomeRealEstateSeeder::class);
        $listing = $cabinet->listings()->sole();
        $listing->update(['title' => 'Titre corrigé', 'net_price' => 3000000]);
        $listing->units()->update(['net_price' => 3000000, 'units_available' => 0]);
        $listing->media()->create(['path' => 'real_estate/existing.jpg', 'type' => 'image', 'is_cover' => true]);
        $before = $listing->fresh()->getRawOriginal();
        $this->seed(AudyHomeRealEstateSeeder::class);
        $this->assertDatabaseCount('real_estate_cabinets', 1);
        $this->assertDatabaseCount('real_estate_listings', 1);
        $this->assertDatabaseCount('real_estate_units', 1);
        $this->assertDatabaseCount('real_estate_media', 1);
        $this->assertSame($before, $listing->fresh()->getRawOriginal());
        $this->assertSame(0, $listing->units()->sole()->units_available);
        $this->assertSame($account->id, $cabinet->fresh()->user_id);
        $this->assertSame('CONTACT_EXISTANT', $cabinet->fresh()->phone);
        $listing->delete();
        $this->seed(AudyHomeRealEstateSeeder::class);
        $this->assertTrue($listing->fresh()->trashed());
        $this->assertDatabaseCount('real_estate_listings', 1);
    }

    public function test_reference_collision_rolls_back_partner_and_beneficiary_creation(): void
    {
        $other = Cabinet::create(['name' => 'Autre cabinet']);
        Listing::create([
            'reference' => 'AUDY-HOME-001', 'type' => 'land', 'cabinet_id' => $other->id,
            'title' => 'Autre terrain', 'city' => 'Douala',
        ]);
        try {
            $this->seed(AudyHomeRealEstateSeeder::class);
            $this->fail('Le conflit de référence doit annuler cet import.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('autre cabinet', $exception->getMessage());
        }
        $this->assertDatabaseCount('real_estate_cabinets', 1);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('real_estate_listings', 1);
        $this->assertDatabaseCount('real_estate_units', 0);
    }

    public function test_deleted_partner_is_not_recreated(): void
    {
        $cabinet = Cabinet::create(['name' => 'AUDY HOME']);
        $cabinet->delete();
        try {
            $this->seed(AudyHomeRealEstateSeeder::class);
            $this->fail('Un cabinet supprimé ne doit pas être recréé.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('supprimé ou inactif', $exception->getMessage());
        }
        $this->assertDatabaseCount('real_estate_cabinets', 1);
        $this->assertDatabaseCount('real_estate_listings', 0);
    }

    public function test_ambiguous_partner_names_abort_import(): void
    {
        Cabinet::create(['name' => 'AUDY HOME']);
        Cabinet::create(['name' => 'Cabinet Audy Home']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Plusieurs cabinets AUDY HOME');
        $this->seed(AudyHomeRealEstateSeeder::class);
    }
}
