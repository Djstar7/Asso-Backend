<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Currency;
use App\Models\DelivererCompany;
use App\Models\ExchangeRate;
use App\Models\MaritimeBooking;
use App\Models\MaritimeDeparture;
use App\Models\MaritimeHub;
use App\Models\MaritimeIndemnityClaim;
use App\Models\ParcelNature;
use App\Models\ServiceConfiguration;
use App\Models\Setting;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\FirebaseMessagingService;
use App\Services\MaritimeInsuranceLegacyBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MaritimeInsuranceClaimsTest extends TestCase
{
    use RefreshDatabase;

    private array $pushes = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        ServiceConfiguration::updateOrCreate(
            ['service_name' => 'kpay'],
            ['service_type' => 'payment', 'is_active' => true, 'configuration' => ['api_key' => 'k', 'secret_key' => 's', 'base_url' => 'https://kpay.test']]
        );
        $this->mock(FirebaseMessagingService::class, function ($mock) {
            $mock->shouldReceive('sendToUser')->andReturnUsing(function ($user, $title, $body, $data = []) {
                $this->pushes[] = [
                    'user_id' => $user->id,
                    'title' => $title,
                    'body' => $body,
                    'data' => $data,
                ];

                return [];
            });
        });

        Currency::firstOrCreate(['code' => 'XAF'], ['name' => 'Franc CFA', 'symbol' => 'FCFA', 'is_active' => true]);
        Currency::firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'symbol' => '€', 'is_active' => true]);
        ExchangeRate::create([
            'from_currency' => 'EUR', 'to_currency' => 'XAF', 'rate' => 656,
            'effective_date' => now()->toDateString(), 'is_active' => true, 'source' => 'test',
        ]);
        Setting::set('maritime_commission_rate', 10);
        Setting::set('maritime_booking_cutoff_days', 7);
        Setting::set('maritime_dropoff_days_before', 4);
        $this->platform();
    }

    private function platform(): User
    {
        $platform = User::firstOrCreate(
            ['email' => 'official-platform@asso.test'],
            ['first_name' => 'Official', 'last_name' => 'Platform', 'password' => 'password', 'role' => 'admin']
        );
        Setting::set('platform_account_email', $platform->email);

        return $platform;
    }

    private function departure(): MaritimeDeparture
    {
        $partner = DelivererCompany::create([
            'name' => 'Maritime test '.uniqid(),
            'service_type' => DelivererCompany::SERVICE_MARITIME,
            'is_active' => true,
            'user_id' => User::factory()->create()->id,
        ]);
        $from = MaritimeHub::create(['deliverer_company_id' => $partner->id, 'name' => 'Origin', 'country' => 'FR', 'city' => 'Paris', 'address' => 'Quai 1', 'is_active' => true]);
        $to = MaritimeHub::create(['deliverer_company_id' => $partner->id, 'name' => 'Destination', 'country' => 'CM', 'city' => 'Douala', 'address' => 'Port', 'is_active' => true]);

        return MaritimeDeparture::create([
            'deliverer_company_id' => $partner->id,
            'origin_hub_id' => $from->id,
            'destination_hub_id' => $to->id,
            'departure_date' => today()->addDays(20),
            'capacity_kg' => 500,
            'remaining_kg' => 500,
            'price_per_kg' => 4,
            'currency' => 'EUR',
            'status' => 'open',
        ]);
    }

    private function nature(): ParcelNature
    {
        return ParcelNature::create(['name' => 'Fragile assuré', 'insurance_rate' => 5, 'is_active' => true]);
    }

    private function payload(ParcelNature $nature, array $overrides = []): array
    {
        return array_replace([
            'kg_booked' => 10,
            'sender_first_name' => 'Awa',
            'sender_last_name' => 'Client',
            'sender_phone' => '+33600000000',
            'emergency_phone' => '+33611111111',
            'recipient_first_name' => 'Jean',
            'recipient_last_name' => 'Destinataire',
            'recipient_country' => 'CM',
            'recipient_phone' => '+237690000000',
            'parcel_nature_id' => $nature->id,
            'declared_value' => 1000,
            'payment_method' => 'kpay',
            'provider' => 'MTN_MOMO_CMR',
            'phone_number' => '237653456789',
        ], $overrides);
    }

    private function fakeKpay(string $status = 'SUCCESS'): void
    {
        Http::fake([
            '*/api/v1/payments/init' => Http::response(['id' => 'maritime-pay-test', 'status' => 'PENDING'], 200),
            '*/api/v1/payments/*' => Http::response(['status' => $status], 200),
        ]);
    }

    private function book(User $customer, MaritimeDeparture $departure, ParcelNature $nature, bool $optIn = true): MaritimeBooking
    {
        $payload = $this->payload($nature);
        if ($optIn) {
            $payload['insurance_opt_in'] = true;
        }
        Sanctum::actingAs($customer);
        $bookingId = $this->postJson("/api/v1/maritime/departures/{$departure->id}/book", $payload)
            ->assertCreated()
            ->json('booking_id');

        return MaritimeBooking::findOrFail($bookingId);
    }

    private function ship(MaritimeBooking $booking): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $this->post(route('admin.maritime.bookings.advance', $booking))->assertRedirect();
        $this->post(route('admin.maritime.bookings.advance', $booking))->assertRedirect();
        $this->assertSame(MaritimeBooking::STATUS_SHIPPED, $booking->fresh()->status);
    }

    public function test_insurance_is_opt_in_and_no_cover_fee_is_only_an_informational_quote(): void
    {
        $this->fakeKpay();
        $customer = User::factory()->create();
        $departure = $this->departure();
        $nature = $this->nature();
        $payload = $this->payload($nature);
        unset($payload['insurance_opt_in']);
        Sanctum::actingAs($customer);

        $response = $this->postJson("/api/v1/maritime/departures/{$departure->id}/book", $payload)->assertCreated();
        $id = $response->json('booking_id');

        // 50 × 4 € freight subtotal + 10 % ASSO = 44 €; the 50 € quote is not charged.
        $response->assertJsonPath('data.insurance_opt_in', false)
            ->assertJsonPath('data.insurance_rate_applied', 5)
            ->assertJsonPath('data.insurance_quote_amount', 50)
            ->assertJsonPath('data.insurance_amount', 0)
            ->assertJsonPath('data.insurance_status', 'not_opted_in')
            ->assertJsonPath('data.can_report_loss', false)
            ->assertJsonPath('data.claim_summary', null)
            ->assertJsonPath('data.claim_history', []);
        $this->assertDatabaseHas('maritime_bookings', [
            'id' => $id,
            'insurance_opt_in' => false,
            'insurance_quote_amount' => 50,
            'insurance_amount' => 0,
            'total_price' => 44,
        ]);
        $this->getJson("/api/v1/maritime/bookings/{$id}/payment-status")->assertJsonPath('data.payment_status', 'completed');
        $this->assertDatabaseHas('maritime_bookings', ['id' => $id, 'insurance_amount' => 0, 'insurance_status' => 'not_opted_in']);
    }

    public function test_declared_value_is_strictly_positive_and_parcel_nature_is_always_required(): void
    {
        $this->fakeKpay();
        $departure = $this->departure();
        $nature = $this->nature();
        $customer = User::factory()->create();
        Sanctum::actingAs($customer);

        $this->postJson("/api/v1/maritime/departures/{$departure->id}/book", $this->payload($nature, ['declared_value' => 0]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('declared_value');
        $payload = $this->payload($nature);
        unset($payload['parcel_nature_id']);
        $this->postJson("/api/v1/maritime/departures/{$departure->id}/book", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parcel_nature_id');
    }

    public function test_opted_in_cover_activates_only_after_successful_payment_and_loss_report_requires_active_cover(): void
    {
        $this->fakeKpay('FAILED');
        $customer = User::factory()->create();
        $booking = $this->book($customer, $this->departure(), $this->nature());
        $this->assertSame('pending_payment', $booking->insurance_status);
        $this->assertSame(0.0, (float) $booking->insurance_amount);

        Sanctum::actingAs($customer);
        $this->getJson("/api/v1/maritime/bookings/{$booking->id}/payment-status")->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('payment_failed', $booking->fresh()->insurance_status);
        $this->assertSame(0.0, (float) $booking->fresh()->insurance_amount);
        $this->postJson("/api/v1/maritime/bookings/{$booking->id}/loss-reports", ['reason' => 'Parcel never arrived.'])
            ->assertUnprocessable();
    }

    public function test_booking_history_exposes_localized_status_and_event_labels(): void
    {
        $this->fakeKpay();
        $customer = User::factory()->create();
        $booking = $this->book($customer, $this->departure(), $this->nature());

        Sanctum::actingAs($customer);
        $this->getJson("/api/v1/maritime/bookings/{$booking->id}")
            ->assertJsonPath('data.insurance_history.0.status', 'pending_payment')
            ->assertJsonPath('data.insurance_history.0.status_label', 'Paiement en cours')
            ->assertJsonPath('data.insurance_history.0.event_label', 'Assurance souscrite');

        $this->getJson("/api/v1/maritime/bookings/{$booking->id}/payment-status");
        $booking->insuranceHistory()->create([
            'event' => 'booking_status',
            'status' => 'dropped_off',
        ]);
        $response = $this->getJson("/api/v1/maritime/bookings/{$booking->id}");
        $history = collect($response->json('data.insurance_history'));

        $this->assertSame('Assurance active', $history->firstWhere('status', 'active')['status_label']);
        $this->assertSame('Colis déposé au QG', $history->firstWhere('status', 'dropped_off')['status_label']);
        $this->assertSame('Statut de l’envoi mis à jour', $history->firstWhere('event', 'booking_status')['event_label']);

        $englishHistory = collect($this->getJson("/api/v1/maritime/bookings/{$booking->id}", ['Accept-Language' => 'en'])
            ->json('data.insurance_history'));
        $this->assertSame('Payment in progress', $englishHistory->firstWhere('status', 'pending_payment')['status_label']);
        $this->assertSame('Parcel dropped off at the hub', $englishHistory->firstWhere('status', 'dropped_off')['status_label']);
    }

    public function test_claim_workflow_requires_admin_and_cap_and_pays_atomically_once(): void
    {
        $this->fakeKpay();
        $customer = User::factory()->create();
        $booking = $this->book($customer, $this->departure(), $this->nature());
        Sanctum::actingAs($customer);
        $this->getJson("/api/v1/maritime/bookings/{$booking->id}/payment-status")->assertJsonPath('data.payment_status', 'completed');
        $this->getJson("/api/v1/maritime/bookings/{$booking->id}")->assertJsonPath('data.can_report_loss', false);
        $this->ship($booking);
        Sanctum::actingAs($customer);
        $this->getJson("/api/v1/maritime/bookings/{$booking->id}")->assertJsonPath('data.can_report_loss', true);
        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/v1/maritime/bookings/{$booking->id}/loss-reports", ['reason' => 'Not the booking owner.'])->assertNotFound();
        Sanctum::actingAs($customer);

        $report = $this->postJson("/api/v1/maritime/bookings/{$booking->id}/loss-reports", ['reason' => 'The parcel was lost during the sea crossing.'])
            ->assertCreated()
            ->assertJsonPath('data.claim_summary.status', 'reported');
        $claimId = $report->json('data.claim_summary.id');
        $claim = MaritimeIndemnityClaim::findOrFail($claimId);
        $this->getJson("/api/v1/maritime/bookings/{$booking->id}")
            ->assertJsonPath('data.can_report_loss', false)
            ->assertJsonPath('data.claim_summary.status', 'reported')
            ->assertJsonPath('data.claim_history.0.action', 'loss_reported');

        $manager = User::factory()->create(['role' => 'product_manager']);
        $this->actingAs($manager);
        $this->post(route('admin.maritime.claims.confirm-loss', $claim), ['reason' => 'Checked manifest and carrier records.'])->assertForbidden();
        $this->get(route('admin.maritime.claims.payout-proposal', $claim))->assertForbidden();

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $this->get(route('admin.maritime.bookings.index', ['claim_status' => 'reported']))
            ->assertOk()
            ->assertSee('Awa Client')
            ->assertSee('Indemnisation');
        $this->post(route('admin.maritime.claims.confirm-loss', $claim), ['reason' => 'Checked manifest and carrier records.'])->assertRedirect();
        $this->post(route('admin.maritime.claims.review', $claim), ['decision' => 'approve', 'reason' => 'Coverage and loss confirmed.'])->assertRedirect();

        $platform = $this->platform();
        $platform->creditKpay('XAF', 50000);
        $this->get(route('admin.maritime.claims.payout-proposal', $claim))
            ->assertUnprocessable()
            ->assertJsonPath('success', false);
        $this->post(route('admin.maritime.claims.pay', $claim), [
            'reason' => 'Approved claim payout.',
            'confirmed_proposal_xaf' => 100000,
        ])->assertRedirect()->assertSessionHas('error');
        $this->assertEquals(50000, $platform->fresh()->kpayBalanceFor('XAF'));
        $this->assertEquals(0, $customer->fresh()->kpayBalanceFor('XAF'));
        $this->assertDatabaseMissing('wallet_transactions', ['reference_type' => 'maritime_indemnity_claim_payout', 'reference_id' => $claimId]);
        $this->assertSame(MaritimeIndemnityClaim::APPROVED, $claim->fresh()->status);

        Setting::set('maritime_indemnity_cap_xaf', 100000, 'integer', 'maritime');
        $this->get(route('admin.maritime.bookings.show', $booking))
            ->assertOk()
            ->assertSee('Prévisualiser et confirmer le versement')
            ->assertSee('maritime-payout-confirmation')
            ->assertSee('Motif du versement');
        // Declared value is 1,000 EUR => 656,000 XAF, so the global ceiling controls the server quote.
        $proposal = $this->get(route('admin.maritime.claims.payout-proposal', $claim))
            ->assertOk()
            ->assertJsonPath('data.proposal.booking_reference', "MARITIME-{$booking->id}")
            ->assertJsonPath('data.proposal.client', $customer->name)
            ->assertJsonPath('data.proposal.declared_value', 1000)
            ->assertJsonPath('data.proposal.declared_value_currency', 'EUR')
            ->assertJsonPath('data.proposal.amount_xaf', 100000);
        $this->post(route('admin.maritime.claims.pay', $claim), [
            'reason' => 'Approved claim payout.',
            'confirmed_proposal_xaf' => 10,
        ])->assertRedirect()->assertSessionHas('error');
        $platform->creditKpay('XAF', 100000);
        $this->post(route('admin.maritime.claims.pay', $claim), [
            'reason' => 'Approved claim payout.',
            'confirmed_proposal_xaf' => $proposal->json('data.proposal.amount_xaf'),
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame(MaritimeIndemnityClaim::PAID, $claim->fresh()->status);
        $this->assertEquals(100000, (float) $claim->fresh()->payout_amount_xaf);
        $this->assertEquals(50000, $platform->fresh()->kpayBalanceFor('XAF'));
        $this->assertEquals(100000, $customer->fresh()->kpayBalanceFor('XAF'));
        $transactions = WalletTransaction::where('reference_type', 'maritime_indemnity_claim_payout')->where('reference_id', $claimId)->get();
        $this->assertCount(2, $transactions);
        $this->assertSame(['credit', 'debit'], $transactions->pluck('type')->sort()->values()->all());
        $this->assertSame(1, $transactions->pluck('amount')->map(fn ($amount) => (float) $amount)->unique()->count());
        $this->assertSame([$admin->id], $transactions->pluck('admin_id')->unique()->values()->all());
        $this->assertNotEmpty($claim->fresh()->payout_operation_reference);
        $this->assertSame(['loss_reported', 'loss_confirmed', 'approved', 'paid'], $claim->fresh()->histories->pluck('action')->all());
        $payoutNotification = collect($this->pushes)->firstWhere('data.type', 'maritime_indemnity_paid');
        $this->assertNotNull($payoutNotification);
        $this->assertSame($customer->id, $payoutNotification['user_id']);
        $this->assertStringContainsString('100 000', $payoutNotification['body']);
        $this->assertStringContainsString($claim->fresh()->payout_operation_reference, $payoutNotification['body']);
        $this->assertEquals(100000, (float) $payoutNotification['data']['amount_xaf']);
        $this->assertSame($claim->fresh()->payout_operation_reference, $payoutNotification['data']['operation_reference']);

        $this->post(route('admin.maritime.claims.pay', $claim), [
            'reason' => 'Retry payout.',
            'confirmed_proposal_xaf' => 100000,
        ])->assertRedirect()->assertSessionHas('error');
        $this->assertCount(2, WalletTransaction::where('reference_type', 'maritime_indemnity_claim_payout')->where('reference_id', $claimId)->get());
    }

    public function test_wallet_transfer_rolls_back_both_balance_rows_if_second_ledger_write_fails(): void
    {
        $platform = $this->platform();
        $customer = User::factory()->create();
        $platform->creditKpay('XAF', 10000);
        $beforePlatform = $platform->fresh()->kpayBalanceFor('XAF');
        $admin = User::factory()->create(['role' => 'admin']);

        WalletTransaction::creating(function (WalletTransaction $transaction) {
            if (($transaction->metadata['transfer_operation_reference'] ?? null) === 'TEST-ROLLBACK'
                && ($transaction->metadata['transfer_direction'] ?? null) === 'credit') {
                throw new \RuntimeException('Simulated credit-ledger failure.');
            }
        });

        try {
            app(\App\Services\WalletService::class)->transferBetweenUsers(
                $platform,
                $customer,
                5000,
                'maritime_indemnity_claim_payout',
                999,
                'TEST-ROLLBACK',
                'Rollback test reason.',
                $admin->id
            );
            $this->fail('Expected the simulated second ledger write to fail.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated credit-ledger failure.', $e->getMessage());
        }

        $this->assertEquals($beforePlatform, $platform->fresh()->kpayBalanceFor('XAF'));
        $this->assertEquals(0, $customer->fresh()->kpayBalanceFor('XAF'));
        $this->assertDatabaseMissing('wallet_transactions', ['reference_type' => 'maritime_indemnity_claim_payout', 'reference_id' => 999]);
    }

    public function test_contact_platform_reuses_official_chat_links_multiple_shipped_bookings_and_is_owner_only(): void
    {
        $this->fakeKpay();
        $customer = User::factory()->create();
        $otherCustomer = User::factory()->create();
        $nature = $this->nature();
        $booking = $this->book($customer, $this->departure(), $nature, false);
        Sanctum::actingAs($customer);
        $this->getJson("/api/v1/maritime/bookings/{$booking->id}/payment-status")->assertJsonPath('data.payment_status', 'completed');
        $this->ship($booking);
        Sanctum::actingAs($customer);
        $this->postJson("/api/v1/maritime/bookings/{$booking->id}/loss-reports", ['reason' => 'This parcel was lost.'])->assertUnprocessable();

        $first = $this->postJson("/api/v1/maritime/bookings/{$booking->id}/contact-platform")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.conversation.maritime_booking_ids.0', $booking->id)
            ->assertJsonPath('data.conversation.other_user.id', $this->platform()->id)
            ->assertJsonPath('data.conversation.other_user.name', $this->platform()->name)
            ->assertJsonPath('data.prefill_message', "Bonjour, je souhaite obtenir des informations concernant mon colis maritime #{$booking->id}, actuellement en transit.");
        $conversationId = $first->json('data.conversation.id');

        $secondBooking = $this->book($customer, $this->departure(), $nature, false);
        Sanctum::actingAs($customer);
        $this->getJson("/api/v1/maritime/bookings/{$secondBooking->id}/payment-status")->assertJsonPath('data.payment_status', 'completed');
        $this->ship($secondBooking);
        Sanctum::actingAs($customer);
        $second = $this->postJson("/api/v1/maritime/bookings/{$secondBooking->id}/contact-platform")
            ->assertOk()
            ->assertJsonPath('data.conversation.id', $conversationId);

        $platform = $this->platform();
        $conversation = Conversation::findOrFail($conversationId);
        $this->assertEqualsCanonicalizing([$customer->id, $platform->id], [$conversation->user1_id, $conversation->user2_id]);
        $this->assertEqualsCanonicalizing([$booking->id, $secondBooking->id], $conversation->maritimeBookings()->pluck('maritime_booking_id')->all());

        Sanctum::actingAs($otherCustomer);
        $this->postJson("/api/v1/maritime/bookings/{$booking->id}/contact-platform")->assertNotFound();
    }

    public function test_admin_can_refuse_only_after_confirming_the_loss(): void
    {
        $this->fakeKpay();
        $customer = User::factory()->create();
        $booking = $this->book($customer, $this->departure(), $this->nature());
        Sanctum::actingAs($customer);
        $this->getJson("/api/v1/maritime/bookings/{$booking->id}/payment-status")->assertJsonPath('data.payment_status', 'completed');
        $this->ship($booking);
        Sanctum::actingAs($customer);
        $claimId = $this->postJson("/api/v1/maritime/bookings/{$booking->id}/loss-reports", ['reason' => 'The parcel was damaged and missing.'])
            ->assertCreated()
            ->json('data.claim_summary.id');
        $claim = MaritimeIndemnityClaim::findOrFail($claimId);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $this->post(route('admin.maritime.claims.review', $claim), ['decision' => 'refuse', 'reason' => 'Claim needs confirmed loss first.'])
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->post(route('admin.maritime.claims.confirm-loss', $claim), ['reason' => 'Inspection completed.'])->assertRedirect();
        $this->post(route('admin.maritime.claims.review', $claim), ['decision' => 'refuse', 'reason' => 'The loss is excluded by the policy.'])
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->assertSame(MaritimeIndemnityClaim::REFUSED, $claim->fresh()->status);
        $this->assertSame(['loss_reported', 'loss_confirmed', 'refused'], $claim->fresh()->histories->pluck('action')->all());
    }

    public function test_admin_cap_cannot_be_changed_by_non_admin_and_contact_requires_sanctum(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'product_manager']));
        $this->post(route('admin.maritime.indemnity-cap'), ['indemnity_cap_xaf' => 50000])->assertForbidden();
        auth()->logout();
        $this->postJson('/api/v1/maritime/bookings/1/contact-platform')->assertUnauthorized();
    }

    public function test_legacy_insurance_backfill_preserves_paid_freight_and_does_not_activate_unpaid_cover(): void
    {
        $this->fakeKpay();
        $customer = User::factory()->create();
        $nature = $this->nature();

        $paidBooking = $this->book($customer, $this->departure(), $nature, false);
        Sanctum::actingAs($customer);
        $this->getJson("/api/v1/maritime/bookings/{$paidBooking->id}/payment-status")->assertJsonPath('data.payment_status', 'completed');

        $pendingBooking = $this->book($customer, $this->departure(), $nature, false);
        $refundedBooking = $this->book($customer, $this->departure(), $nature, false);
        $refundedBooking->update(['payment_status' => 'refunded']);
        foreach ([$paidBooking, $pendingBooking, $refundedBooking] as $legacyBooking) {
            $legacyBooking->update([
                'insurance_amount' => 20,
                'insurance_quote_amount' => 0,
                'insurance_rate_applied' => 0,
                'insurance_opt_in' => false,
                'insurance_status' => 'not_opted_in',
                'total_price' => 64,
            ]);
        }

        MaritimeInsuranceLegacyBackfill::run();
        MaritimeInsuranceLegacyBackfill::run();

        $paidBooking->refresh();
        $pendingBooking->refresh();
        $refundedBooking->refresh();
        $this->assertTrue($paidBooking->insurance_opt_in);
        $this->assertEquals(2, (float) $paidBooking->insurance_rate_applied);
        $this->assertEquals(20, (float) $paidBooking->insurance_quote_amount);
        $this->assertEquals(20, (float) $paidBooking->insurance_amount);
        $this->assertSame('active', $paidBooking->insurance_status);
        $this->assertEquals(64, (float) $paidBooking->total_price);
        $this->assertEquals(44, $paidBooking->freightTotal());

        $this->assertTrue($pendingBooking->insurance_opt_in);
        $this->assertEquals(2, (float) $pendingBooking->insurance_rate_applied);
        $this->assertEquals(20, (float) $pendingBooking->insurance_quote_amount);
        $this->assertEquals(0, (float) $pendingBooking->insurance_amount);
        $this->assertSame('pending_payment', $pendingBooking->insurance_status);
        $this->assertEquals(64, (float) $pendingBooking->total_price);
        $this->assertEquals(44, $pendingBooking->freightTotal());

        $this->assertTrue($refundedBooking->insurance_opt_in);
        $this->assertEquals(2, (float) $refundedBooking->insurance_rate_applied);
        $this->assertEquals(20, (float) $refundedBooking->insurance_quote_amount);
        $this->assertEquals(20, (float) $refundedBooking->insurance_amount);
        $this->assertSame('refunded', $refundedBooking->insurance_status);
        $this->assertEquals(64, (float) $refundedBooking->total_price);
        $this->assertEquals(44, $refundedBooking->freightTotal());
    }
}
