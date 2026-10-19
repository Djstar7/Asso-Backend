<?php

namespace Tests\Feature;

use App\Models\RealEstate\Cabinet;
use App\Models\RealEstate\Listing;
use App\Models\RealEstate\Reservation;
use App\Models\Setting;
use App\Models\User;
use App\Services\FirebaseMessagingService;
use App\Services\MobileMoneyGateway;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Rubrique Immobilier, lot 3 : paiement d'une réservation, part ASSO acquise,
 * part du bailleur bloquée puis débloquée, contrat et facture.
 */
class RealEstateReservationTest extends TestCase
{
    use RefreshDatabase;

    private User $platform;
    private User $owner;
    private User $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('local');
        $this->mock(FirebaseMessagingService::class, fn ($m) => $m->shouldReceive('sendToUser')->andReturn([]));
        $this->platform = User::factory()->create(['email' => 'admin@asso.com']);
        $this->owner = User::factory()->create(['phone' => '690000111']);
        $this->owner->forceFill(['landlord_verification_status' => 'verified'])->save();
        $this->tenant = User::factory()->create();
        Setting::set('immo_rent_markup_rate', '0', 'string');
        Setting::set('immo_rent_commission_rate', '10', 'string');
        Setting::set('immo_visit_window_days', '7', 'string');
    }

    private function wallet(): WalletService
    {
        return app(WalletService::class);
    }

    private function balance(User $u): array
    {
        $u->refresh();

        return [(float) $u->kpayBalanceFor('XAF'), (float) $u->kpayAvailableFor('XAF')];
    }

    private function house(array $units = []): Listing
    {
        $l = Listing::create([
            'type' => 'rent', 'owner_id' => $this->owner->id, 'title' => 'Maison à Makepe', 'city' => 'Douala',
            'district' => 'Makepe', 'address' => 'Rue 5', 'net_price' => 100000, 'advance_months' => 6,
            'min_lease_months' => 12, 'status' => 'published', 'published_at' => now(),
        ]);
        foreach ($units as $u) {
            $l->units()->create($u);
        }

        return $l;
    }

    private function book(Listing $listing, array $extra = [])
    {
        Sanctum::actingAs($this->tenant);

        return $this->postJson("/api/v1/real-estate/listings/{$listing->id}/reservations", $extra + [
            'months' => 12, 'move_in_date' => now()->addDays(10)->toDateString(),
            'applicant_status' => 'employee', 'contract_accepted' => true, 'payment_mode' => 'wallet',
        ]);
    }

    public function test_wallet_payment_splits_platform_share_and_holds_owner_share(): void
    {
        $this->wallet()->credit($this->tenant, 700000);
        $listing = $this->house();

        $res = $this->book($listing)->assertCreated();
        $res->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.total_amount', 660000)
            ->assertJsonPath('data.commission_amount', 60000)
            ->assertJsonPath('data.contact.phone', '690000111')
            ->assertJsonPath('data.contact.address', 'Rue 5');

        $this->assertSame([40000.0, 40000.0], $this->balance($this->tenant));
        // Bailleur : 600 000 crédités mais bloqués.
        $this->assertSame([600000.0, 0.0], $this->balance($this->owner));
        // ASSO : commission acquise tout de suite.
        $this->assertSame([60000.0, 60000.0], $this->balance($this->platform));

        $r = Reservation::firstOrFail();
        $this->assertTrue($r->visit_deadline_at->between(now()->addDays(6), now()->addDays(8)));
        Storage::disk('local')->assertExists($r->contract_path);
        Storage::disk('local')->assertExists($r->invoice_path);

        // La fiche lève le masquage pour ce locataire.
        $this->getJson("/api/v1/real-estate/listings/{$listing->id}")->assertJsonPath('data.unlocked', true);

        // Le locataire confirme la conformité : la part du bailleur est débloquée.
        $this->postJson("/api/v1/real-estate/reservations/{$r->id}/confirm")->assertOk()
            ->assertJsonPath('data.status', 'released');
        $this->assertSame([600000.0, 600000.0], $this->balance($this->owner));
        $this->assertSame([60000.0, 60000.0], $this->balance($this->platform));

        $this->get("/api/v1/real-estate/reservations/{$r->id}/documents/contract")->assertOk();
    }

    public function test_funds_are_released_automatically_after_the_visit_window(): void
    {
        $this->wallet()->credit($this->tenant, 700000);
        $this->book($this->house())->assertCreated();
        $r = Reservation::firstOrFail();

        $this->artisan('immo:auto-release')->assertSuccessful();
        $this->assertSame('paid', $r->fresh()->status);

        $this->travel(8)->days();
        $this->artisan('immo:auto-release')->assertSuccessful();
        $this->assertSame('released', $r->fresh()->status);
        $this->assertSame([600000.0, 600000.0], $this->balance($this->owner));
    }

    public function test_reservation_takes_one_place_and_is_refused_when_full(): void
    {
        $this->wallet()->credit($this->tenant, 2000000);
        $listing = $this->house([['label' => 'Chambre', 'net_price' => 50000, 'units_total' => 2, 'units_available' => 1]]);
        $unit = $listing->units->first();

        $this->book($listing, ['unit_id' => $unit->id])->assertCreated();
        $this->assertSame(0, $unit->fresh()->units_available);

        $this->book($listing, ['unit_id' => $unit->id])->assertStatus(422);
    }

    public function test_insufficient_balance_and_rules_are_checked_before_paying(): void
    {
        $listing = $this->house();
        $this->book($listing)->assertStatus(422)->assertJsonPath('code', 'insufficient_balance');
        $this->book($listing, ['months' => 6])->assertStatus(422);
        $this->book($listing, ['contract_accepted' => false])->assertStatus(422);
        $this->assertSame(0, Reservation::count());

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/real-estate/listings/{$listing->id}/reservations", [
            'contract_accepted' => true, 'payment_mode' => 'wallet', 'months' => 12,
        ])->assertStatus(422);
    }

    public function test_mobile_money_payment_is_confirmed_by_webhook_after_recheck(): void
    {
        $gateway = $this->mock(MobileMoneyGateway::class);
        $gateway->shouldReceive('initializePayment')->andReturn(['success' => true, 'id' => 'KPAY-123']);
        $gateway->shouldReceive('checkPaymentStatus')->with('KPAY-123')->andReturn(['status' => 'COMPLETED']);

        $listing = $this->house();
        $res = $this->book($listing, ['payment_mode' => 'kpay_direct', 'provider' => 'MTN_CM', 'phone_number' => '677000000'])
            ->assertCreated()->assertJsonPath('data.status', 'pending_payment');
        $reference = $res->json('data.reference');
        $this->assertStringStartsWith('IMMO-', $reference);

        $this->getJson('/api/v1/real-estate/reservations/'.$res->json('data.id').'/payment-status')
            ->assertJsonPath('data.status', 'paid');
        $this->assertSame([600000.0, 0.0], $this->balance($this->owner));
    }

    public function test_land_deposit_goes_blocked_to_the_cabinet_account(): void
    {
        Setting::set('immo_land_markup_rate', '10', 'string');
        $cabinetUser = User::factory()->create();
        $cabinet = Cabinet::create(['name' => 'Cabinet Gwoda et Associés', 'user_id' => $cabinetUser->id, 'phone' => '698884810']);
        $land = Listing::create([
            'type' => 'land', 'cabinet_id' => $cabinet->id, 'title' => 'Soa', 'city' => 'Soa',
            'deposit_percent' => 10, 'status' => 'published', 'published_at' => now(),
        ]);
        $lot = $land->units()->create(['label' => '500 m²', 'surface_min' => 500, 'net_price' => 5000000]);
        $this->wallet()->credit($this->tenant, 1000000);

        Sanctum::actingAs($this->tenant);
        $this->postJson("/api/v1/real-estate/listings/{$land->id}/reservations", [
            'unit_id' => $lot->id, 'contract_accepted' => true, 'payment_mode' => 'wallet',
        ])->assertCreated()
            ->assertJsonPath('data.total_amount', 550000)
            ->assertJsonPath('data.contact.phone', '698884810');

        $this->assertSame([500000.0, 0.0], $this->balance($cabinetUser));
        $this->assertSame([50000.0, 50000.0], $this->balance($this->platform));
    }

    public function test_platform_revenue_lists_real_estate_commissions(): void
    {
        $this->wallet()->credit($this->tenant, 700000);
        $this->book($this->house())->assertCreated();

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/admin/revenue')->assertOk()->assertSee('Immobilier — locations');
    }
}
