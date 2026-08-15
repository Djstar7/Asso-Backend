<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Reprise des tables Diaspo legacy (diaspo:reconcile-legacy). On recrée l'ancien schéma
 * 14/04 sous le nom *_legacy_preunify (comme le ferait la migration de bascule sur une
 * base prod), on y met des lignes avec les anciens enums, et on vérifie le mapping,
 * le remap des FK et l'archivage.
 */
class ReconcileLegacyDiaspoTest extends TestCase
{
    use RefreshDatabase;

    /** Recrée l'ancien schéma Diaspo (14/04) sous les noms *_legacy_preunify. */
    private function createLegacyTables(): void
    {
        Schema::create('diaspo_offers_legacy_preunify', function ($table) {
            $table->id();
            $table->foreignId('user_id');
            $table->enum('status', ['active', 'full', 'closed', 'cancelled'])->default('active');
            $table->enum('verification_status', ['unverified', 'pending', 'verified', 'rejected'])->default('pending');
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('departure_country');
            $table->string('departure_city');
            $table->timestamp('departure_datetime');
            $table->string('arrival_country');
            $table->string('arrival_city');
            $table->timestamp('arrival_datetime');
            $table->decimal('price_per_kg', 15, 2);
            $table->decimal('available_kg', 10, 2);
            $table->decimal('remaining_kg', 10, 2);
            $table->string('currency', 3)->default('XAF');
            $table->unsignedInteger('views_count')->default(0);
            $table->unsignedInteger('bookings_count')->default(0);
            $table->timestamps();
        });

        Schema::create('diaspo_bookings_legacy_preunify', function ($table) {
            $table->id();
            $table->foreignId('diaspo_offer_id');
            $table->foreignId('buyer_user_id');
            $table->foreignId('seller_user_id');
            $table->decimal('kg_booked', 10, 2);
            $table->decimal('price_per_kg', 15, 2);
            $table->decimal('subtotal', 15, 2);
            $table->decimal('commission_amount', 15, 2)->default(0);
            $table->decimal('total_price', 15, 2);
            $table->string('currency', 3)->default('XAF');
            $table->enum('status', ['pending', 'confirmed', 'in_transit', 'completed', 'cancelled'])->default('pending');
            $table->string('confirmation_code', 6);
            $table->timestamp('confirmed_by_buyer_at')->nullable();
            $table->enum('payment_status', ['pending', 'paid', 'failed', 'refunded'])->default('pending');
            $table->string('payment_reference')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->foreignId('conversation_id')->nullable();
            $table->text('notes')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });
    }

    private function seedLegacyOffer(int $userId, array $overrides = []): int
    {
        return DB::table('diaspo_offers_legacy_preunify')->insertGetId(array_merge([
            'user_id' => $userId,
            'status' => 'active',
            'verification_status' => 'unverified',
            'departure_country' => 'FR', 'departure_city' => 'Paris', 'departure_datetime' => now()->addDays(5),
            'arrival_country' => 'CM', 'arrival_city' => 'Douala', 'arrival_datetime' => now()->addDays(6),
            'price_per_kg' => 12.50, 'available_kg' => 20, 'remaining_kg' => 15, 'currency' => 'XAF',
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    public function test_report_mode_is_read_only(): void
    {
        $this->createLegacyTables();
        $user = User::factory()->create();
        $this->seedLegacyOffer($user->id);

        $this->artisan('diaspo:reconcile-legacy')
            ->assertSuccessful();

        // Rien n'a été repris ni supprimé
        $this->assertSame(1, DB::table('diaspo_offers_legacy_preunify')->count());
        $this->assertSame(0, DB::table('diaspo_offers')->count());
    }

    public function test_migrate_maps_enums_and_remaps_offer_fk(): void
    {
        $this->createLegacyTables();
        $seller = User::factory()->create();
        $buyer = User::factory()->create();

        // Offre "full" → doit devenir "approved" ; verification "unverified" → "pending"
        $legacyOfferId = $this->seedLegacyOffer($seller->id, ['status' => 'full', 'verification_status' => 'unverified']);

        // Réservation "in_transit"/"paid" → "confirmed"/"completed", FK offre remappée
        DB::table('diaspo_bookings_legacy_preunify')->insert([
            'diaspo_offer_id' => $legacyOfferId,
            'buyer_user_id' => $buyer->id, 'seller_user_id' => $seller->id,
            'kg_booked' => 5, 'price_per_kg' => 12.50, 'subtotal' => 62.50,
            'commission_amount' => 6.25, 'total_price' => 68.75, 'currency' => 'XAF',
            'status' => 'in_transit', 'confirmation_code' => '123456',
            'payment_status' => 'paid', 'payment_reference' => 'pay_legacy',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('diaspo:reconcile-legacy', ['--migrate' => true, '--force' => true])
            ->assertSuccessful();

        // Offre reprise avec enums mappés
        $offer = DB::table('diaspo_offers')->first();
        $this->assertNotNull($offer);
        $this->assertSame('approved', $offer->status);
        $this->assertSame('pending', $offer->verification_status);
        $this->assertEquals(12.50, (float) $offer->price_per_kg);

        // Réservation reprise, FK remappée vers le NOUVEL id d'offre, enums mappés, currency ignorée
        $booking = DB::table('diaspo_bookings')->first();
        $this->assertNotNull($booking);
        $this->assertEquals($offer->id, $booking->diaspo_offer_id);
        $this->assertSame('confirmed', $booking->status);
        $this->assertSame('completed', $booking->payment_status);

        // Tables legacy archivées → nouvelle exécution = no-op
        $this->assertFalse(Schema::hasTable('diaspo_offers_legacy_preunify'));
        $this->assertTrue(Schema::hasTable('diaspo_offers_legacy_migrated'));
    }

    public function test_migrate_is_idempotent_second_run_noop(): void
    {
        $this->createLegacyTables();
        $user = User::factory()->create();
        $this->seedLegacyOffer($user->id);

        $this->artisan('diaspo:reconcile-legacy', ['--migrate' => true, '--force' => true])->assertSuccessful();
        $this->artisan('diaspo:reconcile-legacy', ['--migrate' => true, '--force' => true])->assertSuccessful();

        // Une seule offre reprise malgré deux exécutions
        $this->assertSame(1, DB::table('diaspo_offers')->count());
    }

    public function test_orphan_booking_is_skipped(): void
    {
        $this->createLegacyTables();
        $buyer = User::factory()->create();
        $seller = User::factory()->create();

        // Réservation pointant une offre legacy inexistante (id 999)
        DB::table('diaspo_bookings_legacy_preunify')->insert([
            'diaspo_offer_id' => 999,
            'buyer_user_id' => $buyer->id, 'seller_user_id' => $seller->id,
            'kg_booked' => 5, 'price_per_kg' => 10, 'subtotal' => 50,
            'commission_amount' => 5, 'total_price' => 55, 'currency' => 'XAF',
            'status' => 'pending', 'confirmation_code' => '654321',
            'payment_status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('diaspo:reconcile-legacy', ['--migrate' => true, '--force' => true])->assertSuccessful();

        // Orpheline non reprise
        $this->assertSame(0, DB::table('diaspo_bookings')->count());
    }

    public function test_drop_removes_legacy_tables(): void
    {
        $this->createLegacyTables();

        $this->artisan('diaspo:reconcile-legacy', ['--drop' => true, '--force' => true])->assertSuccessful();

        $this->assertFalse(Schema::hasTable('diaspo_offers_legacy_preunify'));
        $this->assertFalse(Schema::hasTable('diaspo_bookings_legacy_preunify'));
    }

    public function test_noop_when_no_legacy_tables(): void
    {
        // Aucune table legacy créée
        $this->artisan('diaspo:reconcile-legacy')->assertSuccessful();
    }
}
