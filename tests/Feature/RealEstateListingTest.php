<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\RealEstate\Cabinet;
use App\Models\RealEstate\Listing;
use App\Models\RealEstate\Reservation;
use App\Models\Setting;
use App\Models\User;
use App\Services\RealEstate\RealEstatePricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Rubrique Immobilier, lot 1 : listes, fiches, masquage avant paiement,
 * calcul des prix, favoris, critères, contact ASSO.
 */
class RealEstateListingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private ?User $landlord = null;

    private function landlord(): User
    {
        if (! $this->landlord) {
            $this->landlord = User::factory()->create(['first_name' => 'Paul', 'last_name' => 'Mbarga', 'phone' => '690000001']);
            $this->landlord->forceFill(['landlord_verification_status' => 'verified'])->save();
        }

        return $this->landlord;
    }

    private function house(array $attrs = [], array $units = []): Listing
    {
        $listing = Listing::create($attrs + [
            'type' => 'rent', 'owner_id' => $this->landlord()->id, 'property_kind' => 'house',
            'title' => 'Maison à louer', 'city' => 'Douala', 'district' => 'Bonamoussadi',
            'address' => 'Rue 12, porte bleue', 'latitude' => 4.09, 'longitude' => 9.74,
            'rooms' => 3, 'bedrooms' => 2, 'net_price' => 100000, 'advance_months' => 6,
            'min_lease_months' => 12, 'road_access' => 'near_road', 'distance_to_road_m' => 150,
            'water_source' => 'borehole', 'electricity' => 'prepaid', 'security_level' => 'medium',
            'status' => 'published', 'published_at' => now(),
        ]);
        foreach ($units as $u) {
            $listing->units()->create($u);
        }

        return $listing;
    }

    public function test_list_returns_published_houses_with_displayed_price(): void
    {
        Setting::set('immo_asso_markup_rate', 5, 'string');
        $this->house();
        $this->house(['status' => 'pending_review', 'title' => 'Brouillon']);

        $res = $this->getJson('/api/v1/real-estate/listings?type=rent')->assertOk();

        $res->assertJsonCount(1, 'data');
        $this->assertSame(105000, $res->json('data.0.price'));
        $this->assertSame('Bonamoussadi', $res->json('data.0.district'));
        $this->assertSame(1, $res->json('meta.total'));
    }

    public function test_filters_by_city_price_and_land_flags(): void
    {
        $this->house(['city' => 'Yaoundé', 'district' => 'Makepe', 'net_price' => 250000]);
        $this->house(['net_price' => 80000]);

        $this->getJson('/api/v1/real-estate/listings?q=Makepe')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/real-estate/listings?price_max=90000')->assertJsonCount(1, 'data');

        $cabinet = Cabinet::create(['name' => 'Cabinet Gwoda et Associés']);
        $land = Listing::create([
            'type' => 'land', 'cabinet_id' => $cabinet->id, 'title' => 'Nkoufoulou', 'city' => 'Yaoundé',
            'district' => 'Nkoufoulou', 'land_titled' => true, 'status' => 'published', 'published_at' => now(),
        ]);
        $land->units()->create(['label' => '300 m²', 'surface_min' => 300, 'surface_max' => 300, 'net_price' => 3000000]);
        $land->units()->create(['label' => '1 200 m²', 'surface_min' => 1200, 'surface_max' => 1200, 'net_price' => 11000000]);

        $res = $this->getJson('/api/v1/real-estate/listings?type=land&titled=1&surface_min=1000')->assertOk();
        $res->assertJsonCount(1, 'data');
        $this->assertSame('300 - 1 200 m²', $res->json('data.0.surface_label'));
        $this->assertSame(3300000, $res->json('data.0.price'));
        $this->assertTrue($res->json('data.0.price_from'));
        $this->assertSame('Cabinet Gwoda et Associés', $res->json('data.0.cabinet.name'));
    }

    public function test_detail_masks_owner_and_address_until_paid(): void
    {
        $listing = $this->house();
        $tenant = User::factory()->create();

        $res = $this->getJson("/api/v1/real-estate/listings/{$listing->id}")->assertOk();
        $this->assertFalse($res->json('data.is_owner'));
        $this->assertFalse($res->json('data.unlocked'));
        $this->assertNull($res->json('data.location.address'));
        $this->assertNull($res->json('data.location.latitude'));
        $this->assertNull($res->json('data.owner.name'));
        $this->assertNull($res->json('data.owner.phone'));
        $this->assertTrue($res->json('data.owner.is_verified'));
        $this->assertStringContainsString('150 m', $res->json('data.description'));

        Reservation::create([
            'reference' => 'IMMO-T1', 'listing_id' => $listing->id, 'tenant_id' => $tenant->id,
            'kind' => 'rent', 'status' => 'paid',
        ]);
        Sanctum::actingAs($tenant);
        $res = $this->getJson("/api/v1/real-estate/listings/{$listing->id}")->assertOk();
        $this->assertFalse($res->json('data.is_owner'));
        $this->assertTrue($res->json('data.unlocked'));
        $this->assertSame('Rue 12, porte bleue', $res->json('data.location.address'));
        $this->assertSame('Paul Mbarga', $res->json('data.owner.name'));
        $this->assertSame('690000001', $res->json('data.owner.phone'));
    }

    public function test_listing_responses_mark_the_authenticated_owner_without_exposing_owner_id(): void
    {
        $listing = $this->house();
        $owner = $this->landlord();

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/real-estate/listings')
            ->assertOk()
            ->assertJsonPath('data.0.is_owner', true)
            ->assertJsonMissingPath('data.0.owner_id');
        $this->getJson("/api/v1/real-estate/listings/{$listing->id}")
            ->assertOk()
            ->assertJsonPath('data.is_owner', true)
            ->assertJsonMissingPath('data.owner_id');
    }

    public function test_unpublished_listing_is_hidden_from_public(): void
    {
        $listing = $this->house(['status' => 'pending_review']);
        $this->getJson("/api/v1/real-estate/listings/{$listing->id}")->assertNotFound();
    }

    public function test_rent_quote_splits_ten_percent_for_asso_and_five_percent_for_partner(): void
    {
        Setting::set('immo_asso_markup_rate', 10, 'string');
        Setting::set('immo_partner_commission_rate', 5, 'string');
        $listing = $this->house();

        $quote = app(RealEstatePricingService::class)->quote($listing->fresh());
        $this->assertSame(660000, $quote['base_amount']);
        $this->assertSame(30000, $quote['commission']);
        $this->assertSame(750000, $quote['total']);
        $this->assertSame(660000, $quote['owner_amount']);
        $this->assertSame(60000, $quote['platform_amount']);

        // L'aperçu public ne révèle pas la part du bailleur.
        $res = $this->getJson("/api/v1/real-estate/listings/{$listing->id}/quote")->assertOk();
        $this->assertSame(750000, $res->json('data.total'));
        $this->assertSame(30000, $res->json('data.partner_commission_amount'));
        $this->assertSame(60000, $res->json('data.platform_amount'));
        $this->assertNull($res->json('data.owner_amount'));
    }

    public function test_landlord_preview_matches_the_tenant_quote_and_full_rent_payout(): void
    {
        Setting::set('immo_asso_markup_rate', 10, 'string');
        Setting::set('immo_partner_commission_rate', 5, 'string');
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/real-estate/landlord/price-preview', [
            'net_price' => 45000, 'advance_months' => 3,
        ])->assertOk()
            ->assertJsonPath('data.displayed_price', 49500)
            ->assertJsonPath('data.owner_receives_monthly', 49500)
            ->assertJsonPath('data.owner_receives_advance', 148500)
            ->assertJsonPath('data.asso_amount', 13500)
            ->assertJsonPath('data.partner_commission_amount', 6750)
            ->assertJsonPath('data.tenant_pays_advance', 168750);
    }

    public function test_rounding_keeps_the_displayed_rent_times_months_and_balances_all_shares(): void
    {
        Setting::set('immo_asso_markup_rate', 10, 'string');
        Setting::set('immo_partner_commission_rate', 5, 'string');
        $listing = $this->house(['net_price' => 7, 'advance_months' => 3]);
        $quote = app(RealEstatePricingService::class)->quote($listing);
        $this->assertSame(8, $quote['unit_price']);
        $this->assertSame(24, $quote['base_amount']);
        $this->assertSame(24, $quote['owner_amount']);
        $this->assertSame(2, $quote['platform_amount']);
        $this->assertSame(1, $quote['commission']);
        $this->assertSame(27, $quote['total']);
    }

    public function test_rent_markup_and_partner_commission_are_split_on_net_amount(): void
    {
        Setting::set('immo_asso_markup_rate', 10, 'string');
        Setting::set('immo_partner_commission_rate', 5, 'string');
        $listing = $this->house();

        $quote = app(RealEstatePricingService::class)->quote($listing->fresh(), null, 2);
        // Le bailleur conserve 110 000 × 2 ; ASSO 20 000 et partenaire 10 000 en plus.
        $this->assertSame(250000, $quote['total']);
        $this->assertSame(10000, $quote['commission']);
        $this->assertSame(220000, $quote['owner_amount']);
        $this->assertSame(20000, $quote['platform_amount']);
    }

    public function test_land_quote_uses_deposit_percent(): void
    {
        Setting::set('immo_asso_markup_rate', 10, 'string');
        Setting::set('immo_partner_commission_rate', 5, 'string');
        $listing = Listing::create([
            'type' => 'land', 'title' => 'Odza', 'city' => 'Yaoundé', 'deposit_percent' => 20,
            'status' => 'published', 'published_at' => now(),
        ]);
        $unit = $listing->units()->create(['label' => '400 m²', 'surface_min' => 400, 'net_price' => 4000000]);

        $quote = app(RealEstatePricingService::class)->quote($listing->fresh(), $unit);
        $this->assertSame(4400000, $quote['unit_price']);
        $this->assertSame(1000000, $quote['total']);
        $this->assertSame(40000, $quote['commission']);
        $this->assertSame(880000, $quote['owner_amount']);
        $this->assertSame(80000, $quote['platform_amount']);
    }

    public function test_low_stock_badge_when_few_places_left(): void
    {
        $this->house([], [['label' => 'Chambre', 'net_price' => 50000, 'units_total' => 5, 'units_available' => 4]]);

        $res = $this->getJson('/api/v1/real-estate/listings')->assertOk();
        $this->assertTrue($res->json('data.0.low_stock'));
        $this->assertSame(4, $res->json('data.0.units_available'));
    }

    public function test_payment_unlocks_only_reserved_property_not_the_cabinet_or_other_properties(): void
    {
        $cabinet = Cabinet::create([
            'name' => 'Cabinet Gwoda et Associés', 'phone' => '698884810', 'email' => 'cabinet@example.com',
            'why_choose_us' => ['Accompagnement dans les démarches foncières'],
        ]);
        $land = Listing::create([
            'type' => 'land', 'cabinet_id' => $cabinet->id, 'title' => 'Soa', 'city' => 'Soa',
            'status' => 'published', 'published_at' => now(),
        ]);
        $otherLand = Listing::create([
            'type' => 'land', 'cabinet_id' => $cabinet->id, 'title' => 'Autre terrain', 'city' => 'Soa',
            'address' => 'Adresse privée du terrain', 'latitude' => 4.1, 'longitude' => 9.7,
            'status' => 'published', 'published_at' => now(),
        ]);
        $house = $this->house(['cabinet_id' => $cabinet->id]);

        $res = $this->getJson("/api/v1/real-estate/cabinets/{$cabinet->id}")->assertOk();
        $this->assertNull($res->json('data.contact'));
        $this->assertSame(['Accompagnement dans les démarches foncières'], $res->json('data.why_choose_us'));
        $res->assertJsonCount(3, 'data.listings');

        $buyer = User::factory()->create();
        $reservation = Reservation::create([
            'reference' => 'IMMO-T2', 'listing_id' => $land->id, 'tenant_id' => $buyer->id,
            'kind' => 'land', 'status' => 'paid',
        ]);
        Sanctum::actingAs($buyer);
        $res = $this->getJson("/api/v1/real-estate/cabinets/{$cabinet->id}")->assertOk();
        $res->assertJsonPath('data.contact_unlocked', false)->assertJsonPath('data.contact', null);
        $this->getJson("/api/v1/real-estate/listings/{$land->id}")->assertOk()
            ->assertJsonPath('data.unlocked', true)->assertJsonPath('data.cabinet.contact', null);
        foreach ([$otherLand, $house] as $other) {
            $this->getJson("/api/v1/real-estate/listings/{$other->id}")->assertOk()
                ->assertJsonPath('data.unlocked', false)
                ->assertJsonPath('data.location.address', null)
                ->assertJsonPath('data.location.latitude', null)
                ->assertJsonPath('data.owner.phone', null)
                ->assertJsonPath('data.cabinet.contact', null);
        }
        // Le contact est disponible dans le seul dossier payé.
        $this->getJson("/api/v1/real-estate/reservations/{$reservation->id}")->assertOk()
            ->assertJsonPath('data.contact.phone', '698884810');
    }

    public function test_favorites_round_trip(): void
    {
        $listing = $this->house();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/real-estate/listings/{$listing->id}/favorite")->assertOk();
        $this->getJson('/api/v1/real-estate/favorites')->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_favorite', true);
        $this->getJson('/api/v1/real-estate/listings')->assertJsonPath('data.0.is_favorite', true);

        $this->deleteJson("/api/v1/real-estate/listings/{$listing->id}/favorite")->assertOk();
        $this->getJson('/api/v1/real-estate/favorites')->assertJsonCount(0, 'data');
    }

    public function test_alert_is_saved_once_per_user(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->putJson('/api/v1/real-estate/alert', [
            'applicant_status' => 'student', 'school' => 'Université de Yaoundé I', 'city' => 'Yaoundé',
            'lease_months' => 12, 'budget_min' => 30000, 'budget_max' => 120000,
        ])->assertOk();
        $this->putJson('/api/v1/real-estate/alert', ['city' => 'Douala'])->assertOk();

        $this->getJson('/api/v1/real-estate/alert')
            ->assertJsonPath('data.city', 'Douala')
            ->assertJsonPath('data.school', 'Université de Yaoundé I');
    }

    public function test_contact_opens_conversation_with_support_about_listing(): void
    {
        $support = User::factory()->create(['email' => 'support@asso.app']);
        $listing = $this->house();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $first = $this->postJson("/api/v1/real-estate/listings/{$listing->id}/contact")->assertOk();
        $second = $this->postJson("/api/v1/real-estate/listings/{$listing->id}/contact")->assertOk();

        $this->assertSame($first->json('conversation.id'), $second->json('conversation.id'));
        $this->assertSame($support->id, $first->json('conversation.other_user.id'));
        $this->assertSame($listing->id, Conversation::find($first->json('conversation.id'))->real_estate_listing_id);

        $this->getJson('/api/v1/conversations')
            ->assertJsonPath('conversations.0.real_estate_listing.reference', $listing->reference);
    }

    public function test_project_request_is_recorded(): void
    {
        Sanctum::actingAs(User::factory()->create(['phone' => '677000000']));

        $this->postJson('/api/v1/real-estate/project-requests', [
            'has_land' => false, 'city' => 'Yaoundé', 'budget' => 5000000,
        ])->assertCreated();

        $this->assertDatabaseHas('real_estate_project_requests', ['has_land' => false, 'phone' => '677000000']);
    }

    public function test_meta_lists_default_amenities(): void
    {
        $res = $this->getJson('/api/v1/real-estate/meta')->assertOk();
        $this->assertNotEmpty($res->json('data.amenities'));
        $this->assertEquals(10, $res->json('data.asso_markup_rate'));
        $this->assertEquals(5, $res->json('data.partner_commission_rate'));
    }

    public function test_base_one_hundred_is_exactly_one_hundred_fifteen(): void
    {
        $listing = $this->house(['net_price' => 100, 'advance_months' => 1]);
        $quote = app(RealEstatePricingService::class)->quote($listing);
        $this->assertSame(110, $quote['owner_amount']);
        $this->assertSame(10, $quote['platform_amount']);
        $this->assertSame(5, $quote['commission']);
        $this->assertSame(125, $quote['total']);
    }
}
