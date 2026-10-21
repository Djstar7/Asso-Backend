<?php

namespace Tests\Feature;

use App\Models\RealEstate\Cabinet;
use App\Models\RealEstate\Listing;
use App\Models\RealEstate\Reservation;
use App\Models\Setting;
use App\Models\User;
use App\Services\RealEstate\RealEstateReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RealEstatePartnerSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_partner_is_configured_without_rewriting_historical_payments(): void
    {
        Cache::flush();
        $cabinet = Cabinet::create(['name' => 'Cabinet Gwoda et Associés']);
        $owner = User::factory()->create();
        $listing = Listing::create([
            'type' => 'rent', 'owner_id' => $owner->id, 'title' => 'Maison bailleur',
            'city' => 'Douala', 'net_price' => 100000,
        ]);
        $reservation = Reservation::create([
            'reference' => 'IMMO-OLD', 'listing_id' => $listing->id,
            'tenant_id' => User::factory()->create()->id, 'payee_id' => $owner->id,
            'kind' => 'rent', 'status' => 'paid', 'owner_amount' => 100000,
            'commission_amount' => 11000, 'platform_amount' => 21000, 'total_amount' => 121000,
        ]);
        $before = $reservation->fresh()->getRawOriginal();

        $migration = require database_path('migrations/2026_10_21_100000_configure_default_real_estate_partner.php');
        $migration->down();
        $migration->up();

        $this->assertNotNull($cabinet->fresh()->user_id);
        $this->assertSame($cabinet->id, (int) Setting::get('immo_default_cabinet_id'));
        $this->assertSame(5, (int) Setting::get('immo_partner_commission_rate'));
        $this->assertSame($cabinet->id, $listing->fresh()->cabinet_id);
        $this->assertSame($owner->id, app(RealEstateReservationService::class)->payee($listing->fresh())->id);
        $this->assertSame($before, $reservation->fresh()->getRawOriginal());
    }
}
