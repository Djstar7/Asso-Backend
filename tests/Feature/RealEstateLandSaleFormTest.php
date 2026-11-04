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

/** Prix au m² saisi, case morcelable, surface totale et maison en vente. */
class RealEstateLandSaleFormTest extends TestCase
{
    use RefreshDatabase;

    private Cabinet $cabinet;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->cabinet = Cabinet::create(['name' => 'Cabinet', 'user_id' => User::factory()->create()->id]);
    }

    private function save(array $data, ?Listing $listing = null): Listing
    {
        return app(ListingEditor::class)->save($listing ?? new Listing(['type' => 'land', 'status' => 'published']), $data + [
            'cabinet_id' => $this->cabinet->id, 'property_kind' => 'building_land', 'title' => 'Terrain', 'city' => 'Douala',
        ]);
    }

    private function lot(int $surface, int $price, array $extra = []): array
    {
        return $extra + ['label' => "$surface m²", 'surface_min' => $surface, 'surface_max' => $surface, 'net_price' => $price, 'units_total' => 1];
    }

    public function test_entered_rate_fixes_lot_price_and_a_lot_can_override_it(): void
    {
        $land = $this->save([
            'is_divisible' => true, 'surface_m2' => 1000, 'price_per_m2' => 25000,
            'units' => [$this->lot(333, 1), $this->lot(667, 1, ['price_per_m2' => 30000])],
        ]);

        $this->assertSame(8325000, $land->units[0]->net_price);
        $this->assertSame(20010000, $land->units[1]->net_price);
        $this->getJson("/api/v1/real-estate/listings/{$land->id}")->assertOk()
            ->assertJsonPath('data.units.0.price_per_m2', 25000)
            ->assertJsonPath('data.units.1.price_per_m2', 30000)
            ->assertJsonPath('data.price_per_m2', 25000);
    }

    public function test_without_entered_rate_the_lot_keeps_its_price_and_computed_rate(): void
    {
        $land = $this->save(['is_divisible' => true, 'units' => [$this->lot(333, 10000000), $this->lot(500, 5000000)]]);

        $this->assertSame(10000000, $land->units[0]->net_price);
        $this->assertSame(30030.03, $land->landUnitRate($land->units[0]));
    }

    public function test_range_surface_keeps_manual_price_but_shows_entered_rate(): void
    {
        $land = $this->save([
            'is_divisible' => true, 'price_per_m2' => 20000,
            'units' => [['label' => 'Variable', 'surface_min' => 300, 'surface_max' => 500, 'net_price' => 7000000, 'units_total' => 2]],
        ]);

        $this->assertSame(7000000, $land->units[0]->net_price);
        $this->assertSame(20000.0, $land->landUnitRate($land->units[0]));
    }

    public function test_sort_and_budget_use_the_entered_rate(): void
    {
        $cheap = $this->save(['price_per_m2' => 1000, 'is_divisible' => true, 'units' => [['label' => 'Variable', 'surface_min' => 300, 'surface_max' => 500, 'net_price' => 90000000, 'units_total' => 2]]]);
        $dear = $this->save(['is_divisible' => true, 'units' => [$this->lot(100, 1000000), $this->lot(100, 1000000)]]);

        $this->getJson('/api/v1/real-estate/listings?type=land&price_basis=per_m2&sort=price_asc')->assertOk()
            ->assertJsonPath('data.0.id', $cheap->id)->assertJsonPath('data.1.id', $dear->id);
        $this->getJson('/api/v1/real-estate/listings?type=land&price_basis=per_m2&price_max=1000')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $cheap->id);
    }

    public function test_lots_cannot_exceed_total_surface(): void
    {
        $this->expectException(ValidationException::class);
        $this->save(['is_divisible' => true, 'surface_m2' => 500, 'units' => [$this->lot(300, 1000), $this->lot(300, 1000)]]);
    }

    public function test_non_divisible_land_becomes_a_single_lot_from_surface_and_rate(): void
    {
        $land = $this->save(['is_divisible' => true, 'surface_m2' => 1000, 'units' => [$this->lot(500, 1000), $this->lot(500, 1000)]]);
        $land = $this->save(['is_divisible' => false, 'surface_m2' => 1000, 'price_per_m2' => 10000, 'units' => []], $land);

        $this->assertCount(1, $land->units);
        $this->assertSame(10000000, $land->units[0]->net_price);
        $this->assertSame(10000000, $land->net_price);
        $this->getJson("/api/v1/real-estate/listings/{$land->id}")->assertOk()
            ->assertJsonPath('data.subdivision.is_divisible', false)
            ->assertJsonPath('data.subdivision.sale_mode', 'whole')
            ->assertJsonPath('data.subdivision.total_surface_m2', 1000);
    }

    public function test_reserved_pieces_keep_the_land_divisible(): void
    {
        $land = $this->save(['is_divisible' => true, 'surface_m2' => 1000, 'units' => [$this->lot(500, 1000), $this->lot(500, 1000)]]);
        Reservation::create([
            'reference' => 'IMMO-WHOLE', 'listing_id' => $land->id, 'unit_id' => $land->units[0]->id,
            'tenant_id' => User::factory()->create()->id, 'kind' => 'land', 'status' => 'paid',
        ]);

        $this->expectException(ValidationException::class);
        $this->save(['is_divisible' => false, 'surface_m2' => 1000, 'units' => []], $land);
    }

    public function test_divisible_land_needs_two_pieces_to_publish(): void
    {
        $land = $this->save(['is_divisible' => true, 'units' => [$this->lot(500, 1000)]]);
        $this->assertContains('lots', app(ListingEditor::class)->missingForSubmission($land));

        $whole = $this->save(['is_divisible' => false, 'surface_m2' => 500, 'net_price' => 1000, 'units' => []]);
        $this->assertNotContains('lots', app(ListingEditor::class)->missingForSubmission($whole));
    }

    public function test_legacy_land_is_divisible_when_it_has_several_lots(): void
    {
        $land = Listing::create(['type' => 'land', 'title' => 'Ancien', 'city' => 'Douala', 'status' => 'published', 'surface_m2' => 900]);
        $land->units()->create(['label' => '300 m²', 'surface_min' => 300, 'net_price' => 300000, 'units_total' => 2, 'units_available' => 2]);

        $this->getJson("/api/v1/real-estate/listings/{$land->id}")->assertOk()
            ->assertJsonPath('data.subdivision.is_divisible', true)
            ->assertJsonPath('data.subdivision.total_surface_m2', 900)
            ->assertJsonPath('data.units.0.price', 300000);
    }

    public function test_house_for_sale_drops_rental_data(): void
    {
        $house = new Listing(['type' => 'sale', 'status' => 'published']);
        $house = app(ListingEditor::class)->save($house, [
            'cabinet_id' => $this->cabinet->id, 'property_kind' => 'house', 'title' => 'Maison', 'city' => 'Douala',
            'net_price' => 50000000, 'furnished' => true, 'min_lease_months' => 12, 'advance_months' => 3,
            'units' => [['label' => 'Studio', 'net_price' => 1000, 'units_total' => 1]],
        ]);

        $this->assertFalse($house->furnished);
        $this->assertNull($house->min_lease_months);
        $this->assertNull($house->advance_months);
        $this->assertCount(0, $house->units);
        $this->getJson("/api/v1/real-estate/listings/{$house->id}")->assertOk()
            ->assertJsonPath('data.price', 50000000)
            ->assertJsonPath('data.price_from', false)
            ->assertJsonPath('data.furnished', false)
            ->assertJsonPath('data.units', []);
    }

    public function test_admin_form_saves_divisible_box_and_clears_lot_rate(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $land = $this->save(['is_divisible' => true, 'units' => [$this->lot(300, 3000000, ['price_per_m2' => 10000]), $this->lot(300, 3000000)]]);

        $body = ['type' => 'land', 'cabinet_id' => $this->cabinet->id, 'property_kind' => 'building_land', 'title' => 'Terrain', 'city' => 'Douala', 'is_divisible' => 1,
            'units' => [
                ['id' => $land->units[0]->id, 'label' => '300 m²', 'surface_min' => 300, 'surface_max' => 300, 'net_price' => 4000000, 'price_per_m2' => '', 'units_total' => 1],
                ['id' => $land->units[1]->id, 'label' => '300 m²', 'surface_min' => 300, 'surface_max' => 300, 'net_price' => 3000000, 'units_total' => 1],
            ]];
        $this->actingAs($admin)->put(route('admin.real-estate.listings.update', $land), $body)->assertSessionHasNoErrors();

        $land->refresh()->load('units');
        $this->assertTrue($land->is_divisible);
        $this->assertNull($land->units[0]->price_per_m2);
        $this->assertSame(4000000, $land->units[0]->net_price);

        $this->actingAs($admin)->get(route('admin.real-estate.listings.edit', $land))->assertOk()->assertSee('Terrain morcelable');
    }
}
