<?php

namespace Tests\Feature;

use App\Models\RealEstate\Cabinet;
use App\Models\RealEstate\Listing;
use App\Models\RealEstate\Reservation;
use App\Models\Setting;
use App\Models\User;
use App\Services\RealEstate\RealEstatePricingService;
use Database\Seeders\GwodaRealEstateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RealEstateCabinetDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_admin_can_delete_partner_while_preserving_reservations_and_beneficiary(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $account = User::factory()->create();
        $cabinet = Cabinet::create(['name' => 'Partenaire immobilier', 'user_id' => $account->id, 'phone' => '690000000']);
        $other = Cabinet::create(['name' => 'Autre partenaire']);
        Setting::set('immo_default_cabinet_id', $cabinet->id, 'integer', 'real_estate');
        $listing = Listing::create([
            'cabinet_id' => $cabinet->id, 'type' => 'land', 'title' => 'Terrain',
            'city' => 'Yaoundé', 'status' => Listing::STATUS_PUBLISHED, 'net_price' => 40000000,
        ]);
        $unit = $listing->units()->create(['label' => 'Lot', 'net_price' => 40000000]);
        $media = $listing->media()->create(['type' => 'image', 'path' => 'real_estate/existing.jpg']);
        $reservation = Reservation::create([
            'reference' => 'IMMO-DELETE', 'listing_id' => $listing->id, 'unit_id' => $unit->id,
            'tenant_id' => User::factory()->create()->id, 'payee_id' => $account->id,
            'partner_cabinet_id' => $cabinet->id, 'partner_payee_id' => $account->id,
            'kind' => 'land', 'status' => 'paid', 'total_amount' => 4000000,
        ]);
        $before = $reservation->fresh()->getRawOriginal();

        $this->actingAs($admin)->get(route('admin.real-estate.cabinets.index'))
            ->assertOk()->assertSee('Supprimer '.$cabinet->name);
        $this->get(route('admin.real-estate.cabinets.edit', $cabinet))
            ->assertOk()->assertSee('Supprimer '.$cabinet->name);
        $this->delete(route('admin.real-estate.cabinets.destroy', $cabinet))
            ->assertRedirect(route('admin.real-estate.cabinets.index'))
            ->assertSessionHas('success');

        $this->assertSoftDeleted($cabinet);
        $this->assertFalse($cabinet->fresh()->is_active);
        $this->assertNull(Cabinet::find($cabinet->id));
        $this->assertSame(Listing::STATUS_ARCHIVED, $listing->fresh()->status);
        $this->assertSame($before, $reservation->fresh()->getRawOriginal());
        $this->assertSame($account->id, $reservation->fresh()->payee->id);
        $this->assertSame($cabinet->id, $reservation->fresh()->partnerCabinet->id);
        $this->assertSame('690000000', $reservation->fresh()->listing->cabinet->phone);
        $this->assertModelExists($account);
        $this->assertModelExists($media);
        $this->assertModelExists($unit);
        $this->assertModelExists($other);
        $this->assertNull(Cabinet::defaultPartner());
        $this->assertNull(app(RealEstatePricingService::class)->commissionCabinet($listing->fresh()));
        $this->getJson('/api/v1/real-estate/cabinets/'.$cabinet->id)->assertNotFound();
        $this->get(route('admin.real-estate.cabinets.edit', $cabinet))->assertNotFound();
        $this->delete(route('admin.real-estate.cabinets.destroy', $cabinet))->assertNotFound();
        auth()->logout();
        $this->getJson('/api/v1/real-estate/listings/'.$listing->id)->assertNotFound();
    }

    public function test_deleting_implicit_default_does_not_redirect_commissions_to_another_partner(): void
    {
        $cabinet = Cabinet::create(['name' => 'Premier cabinet']);
        Cabinet::create(['name' => 'Deuxième cabinet']);
        $this->assertSame($cabinet->id, Cabinet::defaultPartner()->id);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->delete(route('admin.real-estate.cabinets.destroy', $cabinet))->assertRedirect();

        $this->assertNull(Cabinet::defaultPartner());
        $this->assertSame($cabinet->id, (int) Setting::get('immo_default_cabinet_id'));
    }

    public function test_deleting_non_default_partner_preserves_default_and_other_listings(): void
    {
        $default = Cabinet::create(['name' => 'Défaut']);
        $cabinet = Cabinet::create(['name' => 'À supprimer']);
        $listing = Listing::create([
            'cabinet_id' => $default->id, 'type' => 'land', 'title' => 'Autre terrain',
            'city' => 'Yaoundé', 'status' => Listing::STATUS_PUBLISHED,
        ]);
        Setting::set('immo_default_cabinet_id', $default->id, 'integer', 'real_estate');

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->delete(route('admin.real-estate.cabinets.destroy', $cabinet))->assertRedirect();

        $this->assertSame($default->id, Cabinet::defaultPartner()->id);
        $this->assertSame(Listing::STATUS_PUBLISHED, $listing->fresh()->status);
    }

    public function test_customer_cannot_delete_partner(): void
    {
        $cabinet = Cabinet::create(['name' => 'Cabinet']);
        $this->actingAs(User::factory()->create(['role' => 'customer']))
            ->delete(route('admin.real-estate.cabinets.destroy', $cabinet))
            ->assertRedirect(route('admin.login'))->assertSessionHasErrors('email');
        $this->assertModelExists($cabinet);
        $this->assertNull($cabinet->fresh()->deleted_at);
    }

    public function test_gwoda_seeder_does_not_recreate_deleted_partner(): void
    {
        $cabinet = Cabinet::create(['name' => 'GWODA', 'is_active' => false]);
        $cabinet->delete();

        try {
            $this->seed(GwodaRealEstateSeeder::class);
            $this->fail('Le partenaire supprimé ne doit pas être recréé.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('inactif', $exception->getMessage());
        }

        $this->assertDatabaseCount('real_estate_cabinets', 1);
        $this->assertDatabaseCount('real_estate_listings', 0);
        $this->assertSoftDeleted($cabinet);
    }
}
