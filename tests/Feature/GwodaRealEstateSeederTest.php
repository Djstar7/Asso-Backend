<?php

namespace Tests\Feature;

use App\Models\RealEstate\Cabinet;
use App\Models\RealEstate\Listing;
use App\Models\User;
use App\Services\RealEstate\ListingEditor;
use Database\Seeders\GwodaRealEstateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class GwodaRealEstateSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_creates_seven_sales_with_total_prices_and_only_photos_missing(): void
    {
        $this->seed(GwodaRealEstateSeeder::class);

        $cabinet = Cabinet::where('name', 'GWODA')->sole();
        $this->assertNotNull($cabinet->user);
        $this->assertFalse($cabinet->is_verified);
        $this->assertDatabaseCount('real_estate_media', 0);
        $this->assertDatabaseCount('real_estate_listings', 7);
        $this->assertDatabaseCount('real_estate_units', 7);
        $listings = $cabinet->listings()->orderBy('reference')->get();
        $this->assertSame([40000000, 60000000, 48000000, 175000000, 45000000, 45000000, 3600000], $listings->pluck('net_price')->all());

        foreach ($listings as $listing) {
            $this->assertSame(Listing::TYPE_LAND, $listing->type);
            $this->assertSame(Listing::STATUS_DRAFT, $listing->status);
            $this->assertNull($listing->published_at);
            $this->assertSame($listing->net_price, $listing->units()->sole()->net_price);
            $this->assertSame(['photos'], app(ListingEditor::class)->missingForSubmission($listing));
        }

        $this->assertSame('Dans le titre mère', $listings[0]->land_title_status);
        $this->assertNull($listings[6]->land_title_status);
        $this->assertStringContainsString('pas été communiqué', $listings[6]->owner_description);
        $this->assertNull($listings[0]->distance_to_road_m);
        $this->assertSame(100, $listings[2]->distance_to_road_m);
    }

    public function test_rerun_reuses_existing_cabinet_and_preserves_edits_media_and_deleted_listings(): void
    {
        $user = User::factory()->create();
        $cabinet = Cabinet::create(['name' => 'Cabinet Gwoda et Associés', 'user_id' => $user->id, 'phone' => 'CONTACT_EXISTANT']);
        $this->seed(GwodaRealEstateSeeder::class);

        $listing = Listing::where('reference', 'GWODA-001')->sole();
        $listing->update(['title' => 'Titre corrigé', 'net_price' => 42000000, 'status' => Listing::STATUS_PUBLISHED, 'published_at' => now()]);
        $listing->units()->update(['net_price' => 42000000, 'units_available' => 0]);
        $listing->media()->create(['path' => 'real_estate/existing.jpg', 'type' => 'image', 'is_cover' => true]);
        Listing::where('reference', 'GWODA-007')->sole()->delete();
        $before = $listing->fresh()->getRawOriginal();

        $this->seed(GwodaRealEstateSeeder::class);

        $this->assertDatabaseCount('real_estate_cabinets', 1);
        $this->assertDatabaseCount('real_estate_listings', 7);
        $this->assertDatabaseCount('real_estate_units', 7);
        $this->assertDatabaseCount('real_estate_media', 1);
        $this->assertSame($before, $listing->fresh()->getRawOriginal());
        $this->assertSame(0, $listing->units()->sole()->units_available);
        $this->assertSame($user->id, $cabinet->fresh()->user_id);
        $this->assertSame('CONTACT_EXISTANT', $cabinet->fresh()->phone);
        $this->assertTrue(Listing::withTrashed()->where('reference', 'GWODA-007')->sole()->trashed());
    }

    public function test_reference_collision_rolls_back_entire_import(): void
    {
        $other = Cabinet::create(['name' => 'Autre cabinet']);
        Listing::create([
            'reference' => 'GWODA-004', 'cabinet_id' => $other->id,
            'type' => Listing::TYPE_LAND, 'city' => 'Yaoundé', 'title' => 'Annonce existante',
        ]);

        try {
            $this->seed(GwodaRealEstateSeeder::class);
            $this->fail('Une collision doit annuler cet import.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('autre cabinet', $exception->getMessage());
        }

        $this->assertDatabaseCount('real_estate_cabinets', 1);
        $this->assertDatabaseCount('real_estate_listings', 1);
        $this->assertDatabaseCount('real_estate_units', 0);
        $this->assertDatabaseCount('users', 0);
    }
}
