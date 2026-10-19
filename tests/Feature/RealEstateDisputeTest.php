<?php

namespace Tests\Feature;

use App\Models\RealEstate\Alert;
use App\Models\RealEstate\Dispute;
use App\Models\RealEstate\DisputeReason;
use App\Models\RealEstate\Listing;
use App\Models\RealEstate\Reservation;
use App\Models\Setting;
use App\Models\User;
use App\Services\FirebaseMessagingService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Rubrique Immobilier, lot 4 : litiges à motifs configurables, décision
 * d'ASSO, commission jamais remboursée ; alertes sur critères.
 */
class RealEstateDisputeTest extends TestCase
{
    use RefreshDatabase;

    private User $platform;
    private User $owner;
    private User $tenant;
    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('local');
        Storage::fake('public');
        $this->mock(FirebaseMessagingService::class, fn ($m) => $m->shouldReceive('sendToUser')->andReturn([]));
        $this->platform = User::factory()->create(['email' => 'admin@asso.com']);
        $this->owner = User::factory()->create();
        $this->owner->forceFill(['landlord_verification_status' => 'verified'])->save();
        $this->tenant = User::factory()->create();
        Setting::set('immo_rent_markup_rate', '0', 'string');
        Setting::set('immo_rent_commission_rate', '10', 'string');
        $this->listing = Listing::create([
            'type' => 'rent', 'owner_id' => $this->owner->id, 'title' => 'Studio à Odza', 'city' => 'Yaoundé',
            'net_price' => 50000, 'advance_months' => 2, 'status' => 'published', 'published_at' => now(),
        ]);
        $this->listing->units()->create(['label' => 'Studio', 'net_price' => 50000, 'units_total' => 3, 'units_available' => 3]);
        app(WalletService::class)->credit($this->tenant, 200000);
    }

    private function book(): Reservation
    {
        Sanctum::actingAs($this->tenant);
        $this->postJson("/api/v1/real-estate/listings/{$this->listing->id}/reservations", [
            'unit_id' => $this->listing->units->first()->id, 'contract_accepted' => true, 'payment_mode' => 'wallet',
        ])->assertCreated();

        return Reservation::firstOrFail();
    }

    private function balance(User $u): array
    {
        $u->refresh();

        return [(float) $u->kpayBalanceFor('XAF'), (float) $u->kpayAvailableFor('XAF')];
    }

    private function open(Reservation $r)
    {
        $reason = DisputeReason::for('rent')->firstOrFail();

        return $this->post("/api/v1/real-estate/reservations/{$r->id}/dispute", [
            'reason_id' => $reason->id,
            'description' => 'Les photos ne montrent pas les fissures du plafond.',
            'photos' => [UploadedFile::fake()->image('plafond.jpg')],
        ], ['Accept' => 'application/json']);
    }

    public function test_reasons_are_filtered_by_kind_and_translated(): void
    {
        Sanctum::actingAs($this->tenant);
        $rent = $this->getJson('/api/v1/real-estate/dispute-reasons?kind=rent', ['Accept-Language' => 'en'])->assertOk();
        $this->assertContains('The landlord cannot be reached', array_column($rent->json('data'), 'label'));
        $this->assertNotContains('The firm cannot be reached', array_column($rent->json('data'), 'label'));
    }

    public function test_owner_sees_tenant_file_and_can_answer_open_dispute(): void
    {
        $r = $this->book();
        $this->open($r)->assertCreated();

        Sanctum::actingAs($this->owner);
        $data = $this->getJson("/api/v1/real-estate/reservations/{$r->id}")->assertOk()->json('data');
        $this->assertTrue($data['is_payee']);
        $this->assertTrue($data['can_respond']);
        $this->assertFalse($data['can_dispute']);
        $this->assertSame($this->tenant->phone, $data['tenant']['phone']);
        $this->assertNull($data['contact']);

        $this->post("/api/v1/real-estate/reservations/{$r->id}/dispute/evidence", [
            'message' => 'Les fissures sont superficielles, réparées hier.',
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.dispute.payee_response', 'Les fissures sont superficielles, réparées hier.');

        Sanctum::actingAs($this->tenant);
        $tenantView = $this->getJson("/api/v1/real-estate/reservations/{$r->id}")->assertOk()->json('data');
        $this->assertFalse($tenantView['is_payee']);
        $this->assertNull($tenantView['tenant']);
        $this->assertSame($this->owner->phone, $tenantView['contact']['phone']);
    }

    public function test_refund_returns_held_share_but_keeps_asso_commission(): void
    {
        $r = $this->book();
        $this->assertSame([90000.0, 90000.0], $this->balance($this->tenant));

        $this->open($r)->assertCreated()->assertJsonPath('data.status', 'disputed')
            ->assertJsonPath('data.dispute.attachments.0.author', 'tenant');

        // Litige ouvert : pas de déblocage automatique.
        $this->travel(10)->days();
        $this->artisan('immo:auto-release');
        $this->assertSame('disputed', $r->fresh()->status);

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/real-estate/reservations/{$r->id}/dispute/evidence", ['message' => 'Le plafond a été refait.'])->assertOk();

        $dispute = Dispute::firstOrFail();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get("/admin/real-estate/disputes/{$dispute->id}")->assertOk()->assertSee('Le plafond a été refait.');
        $this->actingAs($admin)->post("/admin/real-estate/disputes/{$dispute->id}/decide", ['decision' => 'refund', 'note' => 'Photos trompeuses.'])->assertRedirect();

        $this->assertSame('refunded', $r->fresh()->status);
        $this->assertSame([190000.0, 190000.0], $this->balance($this->tenant));
        $this->assertSame([0.0, 0.0], $this->balance($this->owner));
        $this->assertSame([10000.0, 10000.0], $this->balance($this->platform));
        $this->assertSame(3, $this->listing->units()->first()->units_available);
    }

    public function test_release_pays_the_owner(): void
    {
        $r = $this->book();
        $this->open($r)->assertCreated();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('/admin/real-estate/disputes/'.Dispute::first()->id.'/decide', ['decision' => 'release'])->assertRedirect();

        $this->assertSame('released', $r->fresh()->status);
        $this->assertSame([100000.0, 100000.0], $this->balance($this->owner));
    }

    public function test_dispute_cannot_be_opened_after_the_window(): void
    {
        $r = $this->book();
        $this->travel(8)->days();
        $this->open($r)->assertStatus(422);
    }

    public function test_admin_manages_reasons(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/admin/real-estate/disputes')->assertOk();
        $this->actingAs($admin)->post('/admin/real-estate/dispute-reasons', [
            'kind' => 'land', 'label' => 'Bornage contesté', 'label_en' => 'Disputed boundaries',
        ])->assertRedirect();

        $this->getJson('/api/v1/real-estate/dispute-reasons?kind=land')->assertJsonFragment(['label' => 'Bornage contesté']);
    }

    public function test_alert_notifies_new_matching_listings_once(): void
    {
        $fcm = $this->mock(FirebaseMessagingService::class);
        $fcm->shouldReceive('sendToUser')->once()->andReturn([]);
        Alert::create(['user_id' => $this->tenant->id, 'type' => 'rent', 'city' => 'Yaoundé', 'budget_max' => 60000, 'is_active' => true]);
        $this->travel(1)->minutes();
        Listing::create([
            'type' => 'rent', 'owner_id' => $this->owner->id, 'title' => 'Chambre à Ngoa', 'city' => 'Yaoundé',
            'net_price' => 40000, 'status' => 'published', 'published_at' => now(),
        ]);

        $this->artisan('immo:notify-alerts')->assertSuccessful();
        $this->artisan('immo:notify-alerts')->assertSuccessful();
    }
}
