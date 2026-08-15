<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DelivererCompany;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\FirebaseMessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Encaissement DIRECT (sans escrow bénéficiaire).
 *
 * À la VALIDATION vendeur : le vendeur, le livreur et ASSO sont crédités et les
 * fonds sont IMMÉDIATEMENT disponibles (aucun blocage). En mode wallet, le client
 * est prélevé au même instant (releaseEscrow) ; en kpay_direct, rien à prélever.
 * À la LIVRAISON : plus AUCUN mouvement de fonds (juste clôture + stock + notifs).
 */
class DirectSettlementOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Neutraliser les notifications FCM (pas d'appel réseau en test)
        $this->mock(FirebaseMessagingService::class, function ($mock) {
            $mock->shouldReceive('sendToUser')->andReturn([]);
        });
    }

    private function setBalances(User $u, float $balance = 0, float $locked = 0): void
    {
        $u->forceFill([
            'kpay_wallet_balance' => $balance,
            'locked_kpay_balance' => $locked,
        ])->saveQuietly();
    }

    /** @return array<string,mixed> */
    private function makeScenario(string $paymentMethod): array
    {
        $subtotal = 2000.0;
        $base = 400.0;
        $commission = 100.0;
        $deliveryFee = $base + $commission;
        $total = $subtotal + $deliveryFee;

        $client = User::factory()->create();
        $seller = User::factory()->create();
        $delivererUser = User::factory()->create();
        $asso = User::factory()->create(['email' => 'admin@asso.com']);

        $this->setBalances($seller, 0, 0);
        $this->setBalances($delivererUser, 0, 0);
        $this->setBalances($asso, 0, 0);

        // Mode wallet : le client a été bloqué à la création (fonds présents mais locked).
        // Mode kpay_direct : rien dans le wallet (payé via Mobile Money, compte marchand).
        if ($paymentMethod !== 'kpay_direct') {
            $this->setBalances($client, $total, $total);
        } else {
            $this->setBalances($client, 0, 0);
        }

        $company = DelivererCompany::create([
            'user_id' => $delivererUser->id,
            'name' => 'Livreur SARL',
            'is_active' => true,
        ]);

        $category = Category::create(['name' => 'Test', 'slug' => 'test-' . uniqid()]);
        $product = Product::create([
            'user_id' => $seller->id,
            'category_id' => $category->id,
            'name' => 'Produit test',
            'slug' => 'p-' . uniqid(),
            'price' => 1000,
            'stock' => 10,
            'status' => 'active',
        ]);

        $order = Order::create([
            'user_id' => $client->id,
            'status' => 'pending',
            'subtotal' => $subtotal,
            'delivery_fee' => $deliveryFee,
            'base_delivery_price' => $base,
            'delivery_commission' => $commission,
            'total' => $total,
            'delivery_company_id' => $company->id,
            'payment_method' => $paymentMethod,
            'payment_status' => 'paid',
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'seller_id' => $seller->id,
            'quantity' => 2,
            'unit_price' => 1000,
            'total_price' => $subtotal,
        ]);

        return compact('client', 'seller', 'delivererUser', 'asso', 'order', 'subtotal', 'base', 'commission', 'total');
    }

    public function test_wallet_validate_credits_beneficiaries_available_and_debits_client(): void
    {
        ['client' => $client, 'seller' => $seller, 'delivererUser' => $deliverer, 'asso' => $asso, 'order' => $order,
            'subtotal' => $subtotal, 'base' => $base, 'commission' => $commission] = $this->makeScenario('wallet_kpay');

        $this->actingAs($seller, 'sanctum')
            ->postJson("/api/v1/vendor/orders/{$order->id}/validate")
            ->assertOk();

        $order->refresh();
        $this->assertSame('confirmed', $order->status);

        // Client prélevé définitivement (releaseEscrow) : solde ET blocage à 0.
        $this->assertEquals(0, (float) $client->fresh()->kpay_wallet_balance);
        $this->assertEquals(0, (float) $client->fresh()->locked_kpay_balance);

        // Bénéficiaires crédités ET disponibles (locked == 0).
        $this->assertEquals($subtotal, (float) $seller->fresh()->kpay_wallet_balance);
        $this->assertEquals(0, (float) $seller->fresh()->locked_kpay_balance);
        $this->assertEquals($base, (float) $deliverer->fresh()->kpay_wallet_balance);
        $this->assertEquals(0, (float) $deliverer->fresh()->locked_kpay_balance);
        $this->assertEquals($commission, (float) $asso->fresh()->kpay_wallet_balance);
        $this->assertEquals(0, (float) $asso->fresh()->locked_kpay_balance);

        // Plus AUCUN blocage de fonds bénéficiaire (type 'lock' absent pour le vendeur).
        $this->assertDatabaseMissing('wallet_transactions', [
            'user_id' => $seller->id,
            'reference_type' => 'order',
            'reference_id' => $order->id,
            'type' => 'lock',
        ]);
        // Le client a bien été libéré (escrow_release).
        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $client->id,
            'reference_type' => 'order',
            'reference_id' => $order->id,
            'type' => 'escrow_release',
        ]);
    }

    public function test_delivery_complete_moves_no_funds(): void
    {
        ['seller' => $seller, 'delivererUser' => $deliverer, 'asso' => $asso, 'order' => $order] = $this->makeScenario('wallet_kpay');

        // 1. Valider (c'est ici que l'argent est distribué).
        $this->actingAs($seller, 'sanctum')
            ->postJson("/api/v1/vendor/orders/{$order->id}/validate")->assertOk();

        // 2. Préparer l'état "shipped" pour permettre la confirmation de livraison.
        $order->refresh();
        $order->update([
            'status' => 'shipped',
            'delivery_person_id' => $deliverer->id,
            'confirmation_code' => '123456',
        ]);

        $sellerBefore = (float) $seller->fresh()->kpay_wallet_balance;
        $delivererBefore = (float) $deliverer->fresh()->kpay_wallet_balance;
        $assoBefore = (float) $asso->fresh()->kpay_wallet_balance;

        // 3. Confirmer la livraison.
        $this->actingAs($deliverer, 'sanctum')
            ->postJson("/api/v1/delivery/{$order->id}/complete", ['confirmation_code' => '123456'])
            ->assertOk();

        $order->refresh();
        $this->assertSame('delivered', $order->status);

        // Aucun mouvement de fonds à la livraison.
        $this->assertEquals($sellerBefore, (float) $seller->fresh()->kpay_wallet_balance);
        $this->assertEquals($delivererBefore, (float) $deliverer->fresh()->kpay_wallet_balance);
        $this->assertEquals($assoBefore, (float) $asso->fresh()->kpay_wallet_balance);
    }

    public function test_kpay_direct_validate_credits_without_touching_client(): void
    {
        ['client' => $client, 'seller' => $seller, 'delivererUser' => $deliverer, 'asso' => $asso, 'order' => $order,
            'subtotal' => $subtotal, 'base' => $base, 'commission' => $commission] = $this->makeScenario('kpay_direct');

        $this->actingAs($seller, 'sanctum')
            ->postJson("/api/v1/vendor/orders/{$order->id}/validate")
            ->assertOk();

        $order->refresh();
        $this->assertSame('confirmed', $order->status);

        // kpay_direct : pas de releaseEscrow (le client n'a rien de bloqué dans le wallet).
        $this->assertEquals(0, (float) $client->fresh()->kpay_wallet_balance);
        $this->assertDatabaseMissing('wallet_transactions', [
            'user_id' => $client->id,
            'reference_type' => 'order',
            'reference_id' => $order->id,
            'type' => 'escrow_release',
        ]);

        // Bénéficiaires crédités et disponibles.
        $this->assertEquals($subtotal, (float) $seller->fresh()->kpay_wallet_balance);
        $this->assertEquals(0, (float) $seller->fresh()->locked_kpay_balance);
        $this->assertEquals($base, (float) $deliverer->fresh()->kpay_wallet_balance);
        $this->assertEquals($commission, (float) $asso->fresh()->kpay_wallet_balance);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
