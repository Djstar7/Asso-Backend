<?php

namespace Tests\Feature;

use App\Models\RealEstate\Cabinet;
use App\Models\RealEstate\Listing;
use App\Models\RealEstate\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RealEstateListingDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('public');
    }

    public function test_admin_can_delete_published_land_while_preserving_paid_reservation_and_media(): void
    {
        $account = User::factory()->create();
        $cabinet = Cabinet::create(['name' => 'Cabinet test', 'user_id' => $account->id]);
        $listing = Listing::create([
            'type' => 'land', 'property_kind' => 'building_land', 'title' => 'Terrain à supprimer',
            'city' => 'Yaoundé', 'cabinet_id' => $cabinet->id, 'net_price' => 40000000,
            'status' => Listing::STATUS_PUBLISHED,
        ]);
        $other = Listing::create(['type' => 'land', 'title' => 'Autre terrain', 'city' => 'Yaoundé']);
        $unit = $listing->units()->create(['label' => 'Lot', 'net_price' => 40000000]);
        $listing->setTranslation('title', 'en', 'Land for sale');
        $media = $listing->media()->create(['type' => 'image', 'path' => 'real_estate/existing.jpg']);
        Storage::disk('public')->put($media->path, 'existing-image');
        $reservation = Reservation::create([
            'reference' => 'IMMO-DELETE-LISTING', 'listing_id' => $listing->id, 'unit_id' => $unit->id,
            'tenant_id' => User::factory()->create()->id, 'payee_id' => $account->id,
            'partner_cabinet_id' => $cabinet->id, 'partner_payee_id' => $account->id,
            'kind' => 'land', 'status' => 'paid', 'total_amount' => 4000000,
        ]);
        $before = $reservation->fresh()->getRawOriginal();
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->get(route('admin.real-estate.listings.index'))
            ->assertOk()->assertSee('Supprimer '.$listing->title);
        $this->get(route('admin.real-estate.listings.edit', $listing))
            ->assertOk()->assertSee('Supprimer '.$listing->title);
        $this->delete(route('admin.real-estate.listings.destroy', $listing))
            ->assertRedirect(route('admin.real-estate.listings.index'))->assertSessionHas('success');

        $this->assertSoftDeleted($listing);
        $this->assertNull(Listing::find($listing->id));
        $this->assertSame(Listing::STATUS_ARCHIVED, $listing->fresh()->status);
        $this->assertSame($before, $reservation->fresh()->getRawOriginal());
        $this->assertSame($listing->id, $reservation->fresh()->listing->id);
        $this->assertSame($account->id, $reservation->fresh()->payee->id);
        $this->assertSame('Land for sale', $reservation->fresh()->listing->getTranslation('title', 'en'));
        $this->assertModelExists($unit);
        $this->assertModelExists($media);
        $this->assertModelExists($cabinet);
        $this->assertModelExists($other);
        Storage::disk('public')->assertExists($media->path);
        $this->get(route('admin.real-estate.listings.index'))->assertOk()->assertDontSee($listing->title);
        $this->get(route('admin.real-estate.listings.edit', $listing))->assertNotFound();
        $this->delete(route('admin.real-estate.listings.destroy', $listing))->assertNotFound();

        auth()->logout();
        $this->getJson('/api/v1/real-estate/listings/'.$listing->id)->assertNotFound();
        $this->getJson('/api/v1/real-estate/listings/'.$listing->id.'/quote')->assertNotFound();
    }

    public function test_admin_can_delete_a_draft_rental(): void
    {
        $listing = Listing::create([
            'type' => 'rent', 'title' => 'Appartement brouillon', 'city' => 'Douala',
            'owner_id' => User::factory()->create()->id, 'status' => Listing::STATUS_DRAFT,
        ]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->delete(route('admin.real-estate.listings.destroy', $listing))->assertRedirect();
        $this->assertSoftDeleted($listing);
        $this->assertModelExists($listing->owner);
    }

    public function test_customer_cannot_delete_a_listing_through_the_backoffice(): void
    {
        $listing = Listing::create(['type' => 'land', 'title' => 'Terrain', 'city' => 'Yaoundé']);
        $this->actingAs(User::factory()->create(['role' => 'customer']))
            ->delete(route('admin.real-estate.listings.destroy', $listing))
            ->assertRedirect(route('admin.login'))->assertSessionHasErrors('email');
        $this->assertNull($listing->fresh()->deleted_at);
    }

    public function test_admin_can_delete_selected_listings_together_without_changing_paid_reservations(): void
    {
        $first = Listing::create(['type' => 'land', 'title' => 'Premier terrain', 'city' => 'Yaoundé']);
        $second = Listing::create(['type' => 'rent', 'title' => 'Appartement', 'city' => 'Douala']);
        $other = Listing::create(['type' => 'land', 'title' => 'Terrain conservé', 'city' => 'Yaoundé']);
        $reservation = Reservation::create([
            'reference' => 'IMMO-BULK', 'listing_id' => $first->id,
            'tenant_id' => User::factory()->create()->id, 'kind' => 'land', 'status' => 'paid', 'total_amount' => 1000000,
        ]);
        $before = $reservation->fresh()->getRawOriginal();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get(route('admin.real-estate.listings.index'))
            ->assertOk()->assertSee('Supprimer la sélection')->assertSee('form="bulk-delete-listings"', false);
        $this->delete(route('admin.real-estate.listings.bulk-destroy'), ['ids' => [$first->id, $second->id]])
            ->assertRedirect(route('admin.real-estate.listings.index'))->assertSessionHas('success');
        $this->assertSoftDeleted($first);
        $this->assertSoftDeleted($second);
        $this->assertNull($other->fresh()->deleted_at);
        $this->assertSame(Listing::STATUS_ARCHIVED, $first->fresh()->status);
        $this->assertSame($before, $reservation->fresh()->getRawOriginal());
        $this->assertSame($first->id, $reservation->fresh()->listing->id);
    }

    public function test_bulk_deletion_rejects_empty_duplicate_and_missing_ids_without_partial_deletion(): void
    {
        $listing = Listing::create(['type' => 'land', 'title' => 'Terrain', 'city' => 'Yaoundé']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach ([[], [$listing->id, $listing->id], [$listing->id, 999999], range(1, 101)] as $ids) {
            $this->deleteJson(route('admin.real-estate.listings.bulk-destroy'), ['ids' => $ids])->assertUnprocessable();
            $this->assertNull($listing->fresh()->deleted_at);
        }
    }

    public function test_customer_cannot_delete_listings_in_bulk(): void
    {
        $listing = Listing::create(['type' => 'land', 'title' => 'Terrain', 'city' => 'Yaoundé']);
        $this->actingAs(User::factory()->create(['role' => 'customer']))
            ->delete(route('admin.real-estate.listings.bulk-destroy'), ['ids' => [$listing->id]])
            ->assertRedirect(route('admin.login'));
        $this->assertNull($listing->fresh()->deleted_at);
    }
}
