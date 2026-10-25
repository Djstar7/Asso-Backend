<?php

namespace Tests\Feature;

use App\Models\RealEstate\Cabinet;
use App\Models\RealEstate\Listing;
use App\Models\RealEstate\Reservation;
use App\Models\User;
use App\Services\RealEstate\ListingEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RealEstateCategoriesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function listing(string $type, array $extra = []): Listing
    {
        return Listing::create($extra + ['type' => $type, 'property_kind' => $type === 'land' ? 'building_land' : 'house',
            'title' => 'Offre '.$type, 'city' => 'Douala', 'status' => 'published', 'published_at' => now(), 'net_price' => 3000000]);
    }

    public function test_houses_include_rentals_and_sales_with_separate_filters(): void
    {
        $rent = $this->listing('rent');
        $sale = $this->listing('sale');
        $this->listing('land');
        $this->listing('sale', ['status' => 'draft']);
        $this->getJson('/api/v1/real-estate/listings?type=house')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/real-estate/listings?type=rent')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $rent->id);
        $this->getJson('/api/v1/real-estate/listings?type=sale')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $sale->id);
        $this->getJson('/api/v1/real-estate/listings/'.$sale->id.'/quote')->assertStatus(422);
    }

    public function test_land_rates_use_the_correct_lot_and_leave_payment_totals_intact(): void
    {
        $land = $this->listing('land');
        $land->units()->create(['label' => '300 m²', 'surface_min' => 300, 'surface_max' => 300, 'net_price' => 3000000]);
        $land->units()->create(['label' => '1000 m²', 'surface_min' => 1000, 'net_price' => 5000000, 'old_net_price' => 6000000]);
        $this->getJson('/api/v1/real-estate/listings/'.$land->id)->assertOk()
            ->assertJsonPath('data.price', 3000000)->assertJsonPath('data.price_per_m2', 5000)
            ->assertJsonPath('data.old_price_per_m2', 6000)
            ->assertJsonPath('data.units.0.price_per_m2', 10000)->assertJsonPath('data.units.1.price_per_m2', 5000);
        $this->getJson('/api/v1/real-estate/listings/'.$land->id.'/quote')->assertOk()->assertJsonPath('data.unit_price', 3000000);
    }

    public function test_land_sort_and_budget_match_the_rate_shown_to_clients(): void
    {
        $expensive = $this->listing('land', ['surface_m2' => 100, 'net_price' => 2000000]);
        $cheap = $this->listing('land');
        $cheap->units()->create(['label' => '1000 m²', 'surface_min' => 1000, 'net_price' => 5000000]);
        $this->getJson('/api/v1/real-estate/listings?type=land&price_basis=per_m2&sort=price_asc')->assertOk()
            ->assertJsonPath('data.0.id', $cheap->id)->assertJsonPath('data.1.id', $expensive->id);
        $this->getJson('/api/v1/real-estate/listings?type=land&price_basis=per_m2&price_min=5000&price_max=5000')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $cheap->id);
    }

    public function test_unknown_or_variable_surfaces_do_not_invent_a_unit_price(): void
    {
        $land = $this->listing('land', ['surface_m2' => 200]);
        $land->units()->create(['label' => 'Variable', 'surface_min' => 100, 'surface_max' => 500, 'net_price' => 3000000]);
        $this->getJson('/api/v1/real-estate/listings/'.$land->id)->assertOk()->assertJsonPath('data.price_per_m2', null)
            ->assertJsonPath('data.units.0.price_per_m2', null);
    }

    public function test_admin_can_reclassify_land_as_a_house_without_losing_photos(): void
    {
        $cabinet = Cabinet::create(['name' => 'Cabinet test', 'user_id' => User::factory()->create()->id]);
        $land = $this->listing('land', ['cabinet_id' => $cabinet->id]);
        $reference = $land->reference;
        $photo = $land->media()->create(['type' => 'image', 'path' => 'existing.jpg']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get(route('admin.real-estate.listings.edit', $land))->assertOk()->assertSee('Rubrique de l’annonce');
        $this->put(route('admin.real-estate.listings.update', $land), ['type' => 'sale', 'property_kind' => 'house',
            'cabinet_id' => $cabinet->id, 'net_price' => 35000000])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('sale', $land->fresh()->type);
        $this->assertSame($reference, $land->fresh()->reference);
        $this->assertModelExists($photo);
        $this->getJson('/api/v1/real-estate/listings?type=house')->assertOk()->assertJsonPath('data.0.id', $land->id);
        $this->getJson('/api/v1/real-estate/listings?type=land')->assertOk()->assertJsonCount(0, 'data');
        $this->get(route('admin.real-estate.listings.edit', $land))->assertOk()->assertSee('Prix de vente (FCFA)');
    }

    public function test_reclassification_keeps_existing_reservation_terms(): void
    {
        $land = $this->listing('land');
        Reservation::create(['reference' => 'IMMO-CATEGORY', 'listing_id' => $land->id,
            'tenant_id' => User::factory()->create()->id, 'kind' => 'land', 'status' => 'paid']);
        try {
            app(ListingEditor::class)->save($land, ['type' => 'sale', 'property_kind' => 'house']);
            $this->fail('Expected category validation');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('type', $e->errors());
            $this->assertSame('land', $land->fresh()->type);
        }
    }
}
