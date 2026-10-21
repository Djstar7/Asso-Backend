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

    private User $partner;

    private Cabinet $cabinet;

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
        $this->partner = User::factory()->create();
        $this->cabinet = Cabinet::create(['name' => 'Cabinet partenaire', 'user_id' => $this->partner->id]);
        Setting::set('immo_default_cabinet_id', $this->cabinet->id, 'integer');
        Setting::set('immo_asso_markup_rate', '10', 'string');
        Setting::set('immo_partner_commission_rate', '5', 'string');
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

    public function test_wallet_payment_credits_asso_and_partner_and_holds_owner_share(): void
    {
        $this->wallet()->credit($this->tenant, 760000);
        $listing = $this->house();

        $res = $this->book($listing)->assertCreated();
        $res->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.total_amount', 750000)
            ->assertJsonPath('data.commission_amount', 30000)
            ->assertJsonPath('data.partner_commission_amount', 30000)
            ->assertJsonPath('data.platform_amount', 60000)
            ->assertJsonPath('data.contact.phone', '690000111')
            ->assertJsonPath('data.contact.address', 'Rue 5');

        $this->assertSame([10000.0, 10000.0], $this->balance($this->tenant));
        // Bailleur : 660 000 crédités mais bloqués.
        $this->assertSame([660000.0, 0.0], $this->balance($this->owner));
        // ASSO : sa majoration est acquise tout de suite.
        $this->assertSame([60000.0, 60000.0], $this->balance($this->platform));
        // Le cabinet par défaut reçoit les 5 % même si l'annonce vient du bailleur.
        $this->assertSame([30000.0, 30000.0], $this->balance($this->partner));

        $r = Reservation::firstOrFail();
        $this->assertTrue($r->visit_deadline_at->between(now()->addDays(6), now()->addDays(8)));
        Storage::disk('local')->assertExists($r->contract_path);
        Storage::disk('local')->assertExists($r->invoice_path);

        // La fiche lève le masquage pour ce locataire.
        $this->getJson("/api/v1/real-estate/listings/{$listing->id}")->assertJsonPath('data.unlocked', true);

        // Le locataire confirme la conformité : la part du bailleur est débloquée.
        $this->postJson("/api/v1/real-estate/reservations/{$r->id}/confirm")->assertOk()
            ->assertJsonPath('data.status', 'released');
        $this->assertSame([660000.0, 660000.0], $this->balance($this->owner));
        $this->assertSame([60000.0, 60000.0], $this->balance($this->platform));
        $this->assertSame([30000.0, 30000.0], $this->balance($this->partner));

        $this->get("/api/v1/real-estate/reservations/{$r->id}/documents/contract")->assertOk();
    }

    public function test_displayed_49500_rent_pays_148500_to_owner_plus_asso_and_partner_fees(): void
    {
        $listing = $this->house([[
            'label' => 'Chambre simple', 'net_price' => 45000,
            'units_total' => 2, 'units_available' => 2,
        ]]);
        $listing->update(['advance_months' => 3]);
        Sanctum::actingAs($this->tenant);
        $this->getJson("/api/v1/real-estate/listings/{$listing->id}/quote")->assertOk()
            ->assertJsonPath('data.unit_price', 49500)
            ->assertJsonPath('data.base_amount', 148500)
            ->assertJsonPath('data.net_amount', 148500)
            ->assertJsonPath('data.platform_amount', 13500)
            ->assertJsonPath('data.partner_commission_amount', 6750)
            ->assertJsonPath('data.total', 168750);
        $this->wallet()->credit($this->tenant, 200000);
        $this->book($listing, ['expected_total' => 168750])->assertCreated()->assertJsonPath('data.total_amount', 168750);
        $r = Reservation::with('listing')->firstOrFail();
        $this->assertSame([31250.0, 31250.0], $this->balance($this->tenant));
        $this->assertSame([148500.0, 0.0], $this->balance($this->owner));
        $this->assertSame([13500.0, 13500.0], $this->balance($this->platform));
        $this->assertSame([6750.0, 6750.0], $this->balance($this->partner));
        foreach (['real_estate_invoice', 'real_estate_contract'] as $template) {
            $html = view('pdf.'.$template, ['r' => $r, 'listing' => $r->listing, 'days' => 7])->render();
            foreach (['148 500 FCFA', '13 500 FCFA', '6 750 FCFA', '168 750 FCFA'] as $amount) {
                $this->assertStringContainsString($amount, $html);
            }
            $this->assertStringNotContainsString('135 000 FCFA', $html);
            $this->assertStringNotContainsString('Dont part ASSO', $html);
            $this->assertStringNotContainsString('part ASSO incluse', $html);
        }
        $this->postJson("/api/v1/real-estate/reservations/{$r->id}/confirm")->assertOk();
        $this->assertSame([148500.0, 148500.0], $this->balance($this->owner));
    }

    public function test_previous_payment_snapshot_keeps_the_amount_actually_collected(): void
    {
        $listing = $this->house();
        $service = app(\App\Services\RealEstate\RealEstateReservationService::class);
        $r = $service->create($this->tenant, $listing, null, [], 'wallet');
        // Réservation acceptée avant la correction : aucun supplément implicite.
        $r->update(['displayed_unit_price' => 49500, 'advance_months' => 3,
            'base_amount' => 148500, 'owner_amount' => 135000,
            'platform_amount' => 13500, 'commission_amount' => 6750,
            'partner_commission_amount' => 6750, 'total_amount' => 155250]);
        $this->wallet()->credit($this->tenant, 155250);
        $service->payWithWallet($r);
        $this->assertSame([0.0, 0.0], $this->balance($this->tenant));
        $this->assertSame([135000.0, 0.0], $this->balance($this->owner));
        $this->assertSame([13500.0, 13500.0], $this->balance($this->platform));
        $this->assertSame([6750.0, 6750.0], $this->balance($this->partner));
        foreach (['real_estate_invoice', 'real_estate_contract'] as $template) {
            $html = view('pdf.'.$template, ['r' => $r->fresh(), 'listing' => $listing, 'days' => 7])->render();
            $this->assertStringContainsString('Dont part ASSO', $html);
            $this->assertStringContainsString('155 250 FCFA', $html);
        }
    }

    public function test_changed_quote_is_rejected_before_any_debit_or_payment_initialization(): void
    {
        $listing = $this->house();
        $this->wallet()->credit($this->tenant, 760000);
        $this->mock(MobileMoneyGateway::class)->shouldNotReceive('initializePayment');
        foreach (['wallet', 'kpay_direct', 'stripe_direct'] as $mode) {
            $this->book($listing, ['expected_total' => 690000, 'payment_mode' => $mode,
                'provider' => 'MTN_CM', 'phone_number' => '677000000'])
                ->assertStatus(422)->assertJsonPath('code', 'quote_changed');
        }
        $this->assertSame(0, Reservation::count());
        $this->assertSame([760000.0, 760000.0], $this->balance($this->tenant));
    }

    public function test_generated_rental_contract_contains_logo_move_in_date_and_handwritten_signature_fields(): void
    {
        $this->wallet()->credit($this->tenant, 760000);
        $listing = $this->house();
        $moveInDate = '2026-11-03';

        $this->book($listing, ['move_in_date' => $moveInDate])->assertCreated();

        $reservation = Reservation::with(['listing', 'tenant', 'payee'])->firstOrFail();
        Storage::disk('local')->assertExists($reservation->contract_path);

        $html = view('pdf.real_estate_contract', [
            'r' => $reservation,
            'listing' => $reservation->listing,
            'commissionRate' => 10,
            'days' => 7,
        ])->render();

        $logoData = 'data:image/png;base64,'.base64_encode(file_get_contents(public_path('logo/Asso.png')));
        $tenantName = trim($reservation->tenant->first_name.' '.$reservation->tenant->last_name);
        $landlordName = trim($reservation->payee->first_name.' '.$reservation->payee->last_name);

        $this->assertStringContainsString($logoData, $html);
        $this->assertStringContainsString("Date d'emménagement", $html);
        $this->assertStringContainsString('03/11/2026', $html);
        $this->assertStringContainsString('Signature manuscrite du bailleur', $html);
        $this->assertStringContainsString('Signature manuscrite du locataire', $html);
        $this->assertStringContainsString('Date : ____ / ____ / ______', $html);
        $this->assertStringContainsString($tenantName, $html);
        $this->assertStringContainsString($landlordName, $html);
        $this->assertStringContainsString("ASSO n'est ni signataire ni partie", $html);
        $this->assertStringContainsString('<td>Avance au bailleur (6 mois)</td><td class="right">660 000 FCFA</td>', $html);
        $this->assertStringContainsString('<td>Part ASSO (non remboursable)</td><td class="right">60 000 FCFA</td>', $html);

        $this->get("/api/v1/real-estate/reservations/{$reservation->id}/documents/contract")->assertOk();
    }

    public function test_documents_are_available_before_conformity_and_regenerated_if_missing(): void
    {
        $this->wallet()->credit($this->tenant, 760000);
        $this->book($this->house())->assertCreated()->assertJsonPath('data.has_documents', true);
        $r = Reservation::firstOrFail();
        Storage::disk('local')->delete([$r->contract_path, $r->invoice_path]);
        $r->update(['contract_path' => null, 'invoice_path' => null]);

        $this->getJson("/api/v1/real-estate/reservations/{$r->id}")->assertOk()
            ->assertJsonPath('data.has_documents', true)
            ->assertJsonPath('data.can_confirm', true)
            ->assertJsonPath('data.can_dispute', true);
        foreach (['contract', 'invoice'] as $kind) {
            $this->get("/api/v1/real-estate/reservations/{$r->id}/documents/{$kind}")->assertOk()
                ->assertHeader('content-type', 'application/pdf');
        }
        $this->assertSame('paid', $r->fresh()->status);
        $this->assertSame([660000.0, 0.0], $this->balance($this->owner));

        // Un tiers ne peut pas télécharger les documents de ce dossier.
        Sanctum::actingAs(User::factory()->create());
        foreach (['contract', 'invoice'] as $kind) {
            $this->get("/api/v1/real-estate/reservations/{$r->id}/documents/{$kind}")->assertNotFound();
        }
    }

    public function test_unpaid_reservation_cannot_download_contract_or_invoice(): void
    {
        $r = Reservation::create([
            'reference' => 'IMMO-UNPAID', 'listing_id' => $this->house()->id,
            'tenant_id' => $this->tenant->id, 'payee_id' => $this->owner->id,
            'kind' => 'rent', 'status' => 'pending_payment',
        ]);
        Sanctum::actingAs($this->tenant);
        $this->getJson("/api/v1/real-estate/reservations/{$r->id}")->assertOk()
            ->assertJsonPath('data.has_documents', false);
        foreach (['contract', 'invoice'] as $kind) {
            $this->get("/api/v1/real-estate/reservations/{$r->id}/documents/{$kind}")->assertNotFound();
        }
        $this->assertNull($r->fresh()->invoice_path);
    }

    public function test_existing_invoice_is_upgraded_to_the_version_with_asso_logo(): void
    {
        $this->wallet()->credit($this->tenant, 760000);
        $this->book($this->house())->assertCreated();
        $r = Reservation::firstOrFail();
        $oldPath = "real_estate/reservations/{$r->id}/facture.pdf";
        Storage::disk('local')->put($oldPath, 'ancienne facture');
        $r->update(['invoice_path' => $oldPath]);

        $this->get("/api/v1/real-estate/reservations/{$r->id}/documents/invoice")->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->assertStringEndsWith('/facture-v3.pdf', $r->fresh()->invoice_path);

        $html = view('pdf.real_estate_invoice', [
            'r' => $r->fresh(), 'listing' => $r->listing,
        ])->render();
        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringContainsString('alt="ASSO"', $html);
        $this->assertStringContainsString('Part ASSO (non remboursable)', $html);
    }

    public function test_existing_contract_is_upgraded_to_show_the_asso_share_without_changing_the_payment(): void
    {
        $this->wallet()->credit($this->tenant, 760000);
        $this->book($this->house())->assertCreated();
        $r = Reservation::firstOrFail();
        $oldPath = "real_estate/reservations/{$r->id}/contrat.pdf";
        Storage::disk('local')->put($oldPath, 'ancien contrat');
        $r->update(['contract_path' => $oldPath]);

        $this->get("/api/v1/real-estate/reservations/{$r->id}/documents/contract")->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('cache-control', 'no-store, private');
        $r->refresh();
        $this->assertStringEndsWith('/contrat-v3.pdf', $r->contract_path);
        Storage::disk('local')->assertExists($r->contract_path);
        $this->assertEquals(60000, $r->platform_amount);
        $this->assertEquals(30000, $r->partner_commission_amount);
        $this->assertEquals(750000, $r->total_amount);
    }

    public function test_funds_are_released_automatically_after_the_visit_window(): void
    {
        $this->wallet()->credit($this->tenant, 760000);
        $this->book($this->house())->assertCreated();
        $r = Reservation::firstOrFail();

        $this->artisan('immo:auto-release')->assertSuccessful();
        $this->assertSame('paid', $r->fresh()->status);

        $this->travel(8)->days();
        $this->artisan('immo:auto-release')->assertSuccessful();
        $this->assertSame('released', $r->fresh()->status);
        $this->assertSame([660000.0, 660000.0], $this->balance($this->owner));
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
        $gateway->shouldReceive('initializePayment')->withArgs(fn ($data) => $data['amount'] === 750000.0)->andReturn(['success' => true, 'id' => 'KPAY-123']);
        $gateway->shouldReceive('checkPaymentStatus')->with('KPAY-123')->andReturn(['status' => 'COMPLETED']);

        $listing = $this->house();
        $res = $this->book($listing, ['payment_mode' => 'kpay_direct', 'provider' => 'MTN_CM', 'phone_number' => '677000000'])
            ->assertCreated()->assertJsonPath('data.status', 'pending_payment');
        $reference = $res->json('data.reference');
        $this->assertStringStartsWith('IMMO-', $reference);

        $this->getJson('/api/v1/real-estate/reservations/'.$res->json('data.id').'/payment-status')
            ->assertJsonPath('data.status', 'paid');
        $this->assertSame([660000.0, 0.0], $this->balance($this->owner));
    }

    public function test_land_deposit_goes_blocked_to_the_cabinet_account(): void
    {
        Setting::set('immo_asso_markup_rate', '10', 'string');
        Setting::set('immo_partner_commission_rate', '5', 'string');
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
            ->assertJsonPath('data.total_amount', 625000)
            ->assertJsonPath('data.contact.phone', '698884810');

        $this->assertSame([575000.0, 25000.0], $this->balance($cabinetUser));
        $this->assertSame([50000.0, 50000.0], $this->balance($this->platform));
        $reservation = Reservation::firstOrFail();
        $this->assertSame($cabinet->id, $reservation->partner_cabinet_id);
        $this->assertSame(25000, $reservation->partner_commission_amount);
    }

    public function test_platform_revenue_lists_real_estate_commissions(): void
    {
        $this->wallet()->credit($this->tenant, 760000);
        $this->book($this->house())->assertCreated();

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/admin/revenue')->assertOk()->assertSee('Immobilier — locations');
    }

    public function test_cabinet_house_receives_net_rent_and_commission_without_double_wallet_debit(): void
    {
        $listing = $this->house();
        $listing->update(['owner_id' => null, 'cabinet_id' => $this->cabinet->id]);
        $this->wallet()->credit($this->tenant, 760000);
        $this->book($listing)->assertCreated()
            ->assertJsonPath('data.net_payee_kind', 'cabinet')
            ->assertJsonPath('data.net_amount', 660000)
            ->assertJsonPath('data.contact.name', $this->cabinet->name);

        $reservation = Reservation::firstOrFail();
        $service = app(\App\Services\RealEstate\RealEstateReservationService::class);
        $service->confirm($reservation);
        $service->payWithWallet($reservation);

        $this->assertSame([690000.0, 30000.0], $this->balance($this->partner));
        $this->assertSame([10000.0, 10000.0], $this->balance($this->tenant));
        $this->assertSame([60000.0, 60000.0], $this->balance($this->platform));
        $this->assertSame([0.0, 0.0], $this->balance($this->owner));
    }

    public function test_landlord_house_keeps_net_rent_when_attached_to_a_cabinet(): void
    {
        $listing = $this->house();
        $listing->update(['cabinet_id' => $this->cabinet->id]);
        $this->wallet()->credit($this->tenant, 760000);
        $this->book($listing)->assertCreated()
            ->assertJsonPath('data.net_payee_kind', 'landlord');

        $this->assertSame([660000.0, 0.0], $this->balance($this->owner));
        $this->assertSame([30000.0, 30000.0], $this->balance($this->partner));
    }

    public function test_pending_payment_uses_snapshots_after_listing_and_partner_changes(): void
    {
        $listing = $this->house();
        $service = app(\App\Services\RealEstate\RealEstateReservationService::class);
        $reservation = $service->create($this->tenant, $listing, null, [], 'wallet');
        $newPartner = User::factory()->create();
        $this->cabinet->update(['user_id' => $newPartner->id, 'name' => 'Nouveau nom']);
        $listing->update(['owner_id' => $newPartner->id, 'net_price' => 200000]);
        Setting::set('immo_partner_commission_rate', 15, 'string');
        $this->wallet()->credit($this->tenant, 760000);
        $service->payWithWallet($reservation);

        $this->assertSame([660000.0, 0.0], $this->balance($this->owner));
        $this->assertSame([30000.0, 30000.0], $this->balance($this->partner));
        $this->assertSame([0.0, 0.0], $this->balance($newPartner));
        Sanctum::actingAs($this->tenant);
        $this->getJson("/api/v1/real-estate/reservations/{$reservation->id}")
            ->assertOk()->assertJsonPath('data.commission_cabinet.name', 'Cabinet partenaire');
    }

    public function test_missing_or_inactive_partner_prevents_payment(): void
    {
        $this->cabinet->update(['is_active' => false]);
        $this->wallet()->credit($this->tenant, 760000);
        $this->book($this->house())->assertStatus(422);
        $this->assertSame(0, Reservation::count());
        $this->assertSame([760000.0, 760000.0], $this->balance($this->tenant));
    }
}
