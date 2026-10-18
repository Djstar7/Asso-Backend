<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\CommissionRange;
use App\Models\DelivererCompany;
use App\Models\Order;
use App\Models\Package;
use App\Models\PackageSubscription;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Models\VendorPackage;
use App\Models\WalletBalance;
use App\Services\FcmService;
use App\Services\FirebaseMessagingService;
use App\Services\OrderService;
use App\Services\PackageSubscriptionService;
use App\Services\CommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P5 — Paiements, Wallet et monétisation.
 *
 * Forfaits (stockage / certification) payés par Wallet ou rail direct, commission
 * ASSO sur les ventes figée à la commande, remboursements sur le Wallet acheteur,
 * option Wallet dans les moyens de paiement et coordonnées de versement.
 */
class PaymentsWalletMonetizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CommissionService::flush();
        $this->mock(FirebaseMessagingService::class, function ($mock) {
            $mock->shouldReceive('sendToUser')->andReturn([]);
        });
        $this->mock(FcmService::class, function ($mock) {
            $mock->shouldIgnoreMissing();
        });
    }

    private function setBalance(User $u, float $balance, float $locked = 0): void
    {
        WalletBalance::updateOrCreate(
            ['user_id' => $u->id, 'currency' => 'XAF'],
            ['balance' => $balance, 'locked_balance' => $locked]
        );
    }

    private function bal(User $u): float
    {
        return $u->fresh()->kpayBalanceFor('XAF');
    }

    private function available(User $u): float
    {
        return $u->fresh()->kpayAvailableFor('XAF');
    }

    private function storagePackage(array $attrs = []): Package
    {
        return Package::create(array_merge([
            'type' => 'storage',
            'name' => 'Stockage Business',
            'price' => 3500,
            'duration_days' => 30,
            'storage_size_mb' => 1024,
            'is_active' => true,
        ], $attrs));
    }

    private function certificationPackage(): Package
    {
        return Package::create([
            'type' => 'certification',
            'name' => 'Certification Bronze',
            'price' => 1000,
            'duration_days' => 90,
            'storage_size_mb' => null,
            'is_active' => true,
        ]);
    }

    private function shopFor(User $u): Shop
    {
        return Shop::create([
            'user_id' => $u->id,
            'name' => 'Boutique ' . $u->id,
            'slug' => 'boutique-' . $u->id,
            'status' => 'active',
        ]);
    }

    /** @return array<string,mixed> */
    private function makeOrder(string $paymentMethod, string $paymentStatus = 'paid', array $orderAttrs = []): array
    {
        $subtotal = 10000.0;
        $base = 400.0;
        $deliveryCommission = 100.0;
        $total = $subtotal + $base + $deliveryCommission;

        $client = User::factory()->create();
        $seller = User::factory()->create();
        $delivererUser = User::factory()->create();
        $asso = User::factory()->create(['email' => 'admin@asso.com']);

        if (str_starts_with($paymentMethod, 'wallet_')) {
            $this->setBalance($client, $total, $total);
        }

        $company = DelivererCompany::create(['user_id' => $delivererUser->id, 'name' => 'Livreur', 'is_active' => true]);
        $category = Category::create(['name' => 'Test', 'slug' => 'test-' . uniqid()]);
        $product = Product::create([
            'user_id' => $seller->id,
            'category_id' => $category->id,
            'name' => 'Produit',
            'slug' => 'p-' . uniqid(),
            'price' => 5000,
            'stock' => 10,
            'status' => 'active',
        ]);

        $order = Order::create(array_merge([
            'user_id' => $client->id,
            'status' => 'pending',
            'subtotal' => $subtotal,
            'delivery_fee' => $base + $deliveryCommission,
            'base_delivery_price' => $base,
            'delivery_commission' => $deliveryCommission,
            'total' => $total,
            'delivery_company_id' => $company->id,
            'payment_method' => $paymentMethod,
            'payment_status' => $paymentStatus,
        ], $orderAttrs));
        $order->items()->create([
            'product_id' => $product->id,
            'seller_id' => $seller->id,
            'quantity' => 2,
            'unit_price' => 5000,
            'total_price' => $subtotal,
        ]);

        return compact('client', 'seller', 'delivererUser', 'asso', 'order', 'subtotal', 'base', 'deliveryCommission', 'total');
    }

    // ───────────────────────── Forfaits ─────────────────────────

    public function test_storage_package_paid_with_wallet_is_activated_immediately(): void
    {
        $vendor = User::factory()->create();
        $this->setBalance($vendor, 5000);
        $package = $this->storagePackage();

        $this->actingAs($vendor, 'sanctum')
            ->postJson('/api/v1/packages/subscribe', ['package_id' => $package->id, 'payment_mode' => 'wallet'])
            ->assertCreated()
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('data.package_type', 'storage');

        $this->assertEquals(1500, $this->bal($vendor));
        $vp = VendorPackage::where('user_id', $vendor->id)->firstOrFail();
        $this->assertEquals(1024, (float) $vp->storage_total_mb);
        $this->assertDatabaseHas('package_subscriptions', [
            'user_id' => $vendor->id, 'payment_method' => 'wallet', 'status' => 'paid', 'vendor_package_id' => $vp->id,
        ]);
    }

    public function test_wallet_subscription_with_insufficient_balance_is_refused_without_side_effects(): void
    {
        $vendor = User::factory()->create();
        $this->setBalance($vendor, 1000);
        $package = $this->storagePackage();

        $this->actingAs($vendor, 'sanctum')
            ->postJson('/api/v1/packages/subscribe', ['package_id' => $package->id, 'payment_mode' => 'wallet'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'insufficient_balance')
            ->assertJsonPath('data.missing_amount', 2500);

        $this->assertEquals(1000, $this->bal($vendor));
        $this->assertDatabaseCount('vendor_packages', 0);
        $this->assertDatabaseCount('package_subscriptions', 0);
    }

    public function test_certification_certifies_shop_and_never_touches_storage_package(): void
    {
        $vendor = User::factory()->create();
        $shop = $this->shopFor($vendor);
        $this->setBalance($vendor, 5000);

        $storage = VendorPackage::create([
            'user_id' => $vendor->id,
            'package_id' => $this->storagePackage()->id,
            'storage_total_mb' => 300,
            'storage_used_mb' => 0,
            'storage_remaining_mb' => 300,
            'purchased_at' => now(),
            'expires_at' => now()->addDays(10),
            'status' => 'active',
        ]);
        $storageExpiry = $storage->expires_at->toIso8601String();

        $this->actingAs($vendor, 'sanctum')
            ->postJson('/api/v1/packages/subscribe', ['package_id' => $this->certificationPackage()->id, 'payment_mode' => 'wallet'])
            ->assertCreated()
            ->assertJsonPath('data.package_type', 'certification')
            ->assertJsonPath('data.vendor_package', null);

        $shop->refresh();
        $this->assertTrue((bool) $shop->is_certified);
        $this->assertTrue($shop->certification_expires_at->between(now()->addDays(89), now()->addDays(91)));

        $storage->refresh();
        $this->assertEquals(300, (float) $storage->storage_total_mb);
        $this->assertNotNull($storage->package_id);
        $this->assertSame($storageExpiry, $storage->expires_at->toIso8601String());
    }

    public function test_certification_renewal_extends_from_current_expiry(): void
    {
        $vendor = User::factory()->create();
        $shop = $this->shopFor($vendor);
        $shop->update(['is_certified' => true, 'certified_at' => now()->subDays(10), 'certification_expires_at' => now()->addDays(20)]);

        app(PackageSubscriptionService::class)->applyPackage($vendor, $this->certificationPackage());

        $this->assertTrue($shop->fresh()->certification_expires_at->between(now()->addDays(109), now()->addDays(111)));
    }

    public function test_certification_without_shop_is_refused_before_payment(): void
    {
        $vendor = User::factory()->create();
        $this->setBalance($vendor, 5000);

        $this->actingAs($vendor, 'sanctum')
            ->postJson('/api/v1/packages/subscribe', ['package_id' => $this->certificationPackage()->id, 'payment_mode' => 'kpay_direct', 'provider' => 'MTN_MOMO_CMR', 'phone_number' => '237670000001'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Créez votre boutique avant de souscrire à une certification.');

        $this->assertEquals(5000, $this->bal($vendor));
        $this->assertDatabaseCount('package_subscriptions', 0);
    }

    public function test_direct_certification_confirmation_activates_shop(): void
    {
        $vendor = User::factory()->create();
        $shop = $this->shopFor($vendor);
        $package = $this->certificationPackage();
        $sub = PackageSubscription::create([
            'user_id' => $vendor->id,
            'package_id' => $package->id,
            'payment_method' => 'kpay_direct',
            'status' => 'pending',
            'payment_reference' => 'pay_123',
            'amount_xaf' => 1000,
            'metadata' => ['package_name' => $package->name],
        ]);

        $service = app(PackageSubscriptionService::class);
        $service->confirm($sub);
        $service->confirm($sub); // idempotent

        $this->assertSame('paid', $sub->fresh()->status);
        $this->assertTrue((bool) $shop->fresh()->is_certified);
        $this->assertDatabaseCount('vendor_packages', 0);
        $this->assertSame(1, \App\Models\WalletTransaction::where('reference_type', 'package_subscription')->count());
    }

    // ───────────────────────── Commission ASSO (majoration) ─────────────────────────

    /** Produit publié par un vendeur + zone de livraison à prix fixe (+100 F ASSO). */
    private function catalog(float $sellerPrice = 10000): array
    {
        $seller = User::factory()->create();
        $delivererUser = User::factory()->create();
        $company = DelivererCompany::create(['user_id' => $delivererUser->id, 'name' => 'Livreur', 'is_active' => true]);
        $zone = \App\Models\DeliveryZone::create([
            'deliverer_company_id' => $company->id,
            'name' => 'Centre',
            'city' => 'Douala',
            'zone_data' => [],
            'is_active' => true,
        ]);
        \App\Models\DeliveryPricelist::create([
            'delivery_zone_id' => $zone->id,
            'pricing_type' => 'fixed',
            'pricing_data' => ['price' => 400],
            'asso_commission' => 100,
            'is_active' => true,
        ]);
        $category = Category::create(['name' => 'Mode', 'slug' => 'mode-' . uniqid()]);
        $shop = $this->shopFor($seller);
        $product = Product::create([
            'user_id' => $seller->id,
            'shop_id' => $shop->id,
            'category_id' => $category->id,
            'name' => 'Sac',
            'slug' => 'sac-' . uniqid(),
            'price' => $sellerPrice,
            'currency' => 'XAF',
            'stock' => 10,
            'status' => 'active',
            'weight' => '1.5', // P4 : sans poids, la livraison est bloquée
        ]);

        return compact('seller', 'delivererUser', 'company', 'zone', 'product');
    }

    private function placeOrder(User $client, array $c): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($client, 'sanctum')->postJson('/api/v1/orders', [
            'items' => [['product_id' => $c['product']->id, 'quantity' => 2]],
            'delivery_company_id' => $c['company']->id,
            'delivery_zone_id' => $c['zone']->id,
            'payment_mode' => 'wallet',
            'wallet_provider' => 'kpay',
            'customer_phone' => '237670000001',
        ]);
    }

    public function test_buyer_sees_seller_price_increased_by_commission(): void
    {
        CommissionRange::create(['min_amount' => 0, 'max_amount' => 1000000, 'percentage' => 10, 'is_active' => true]);
        $c = $this->catalog(10000);

        $this->getJson("/api/v1/products/{$c['product']->id}")
            ->assertOk()
            ->assertJsonPath('product.price', 11000)
            ->assertJsonMissingPath('product.seller_price');

        // Le vendeur voit en plus SON prix et le taux appliqué.
        $this->actingAs($c['seller'], 'sanctum')
            ->getJson("/api/v1/products/{$c['product']->id}")
            ->assertJsonPath('product.price', 11000)
            ->assertJsonPath('product.seller_price', 10000);
    }

    public function test_order_charges_marked_up_price_and_seller_receives_exactly_his_price(): void
    {
        \App\Models\Setting::set('default_sale_commission_rate', '5', 'string', 'commissions');
        // Commission livraison : 25 % du prix HT du livreur (400) = 100.
        \App\Models\Setting::set('delivery_commission_rate', '25', 'string', 'commissions');
        $c = $this->catalog(10000);
        $client = User::factory()->create();
        $asso = User::factory()->create(['email' => 'admin@asso.com']);
        $this->setBalance($client, 50000);

        $orderId = $this->placeOrder($client, $c)->assertCreated()->json('order_id');
        $order = Order::with('items')->findOrFail($orderId);

        // Client : 2 × 10 500 + livraison (400 + 100) = 21 500.
        $this->assertEquals(21000, (float) $order->subtotal);
        $this->assertEquals(21500, (float) $order->total);
        $this->assertEquals(10500, (float) $order->items[0]->unit_price);
        $this->assertEquals(10000, (float) $order->items[0]->seller_unit_price);
        $this->assertEquals(1000, (float) $order->sale_commission);
        $this->assertEquals(20000, (float) $order->vendor_net_amount);

        // Le taux change APRÈS la commande : sans effet sur celle-ci.
        \App\Models\Setting::set('default_sale_commission_rate', '50', 'string', 'commissions');
        CommissionService::flush();

        $this->actingAs($c['seller'], 'sanctum')
            ->postJson("/api/v1/vendor/orders/{$orderId}/validate")
            ->assertOk()
            ->assertJsonPath('order.vendor_amount', 20000)
            ->assertJsonPath('order.items.0.unit_price', 10000);

        $this->assertEquals(20000, $this->bal($c['seller']));
        // Course due au partenaire (versée depuis l'admin), plus de crédit Wallet.
        $this->assertEquals(0, $this->bal($c['delivererUser']));
        $this->assertEquals(400, \App\Services\PartnerLedgerService::balance((int) \App\Models\DelivererCompany::where('user_id', $c['delivererUser']->id)->value('id'))['due']);
        $this->assertEquals(1000 + 100, $this->bal($asso));
        $this->assertEquals(50000 - 21500, $this->bal($client));

        // Double validation : refusée, aucun second crédit.
        $this->actingAs($c['seller'], 'sanctum')
            ->postJson("/api/v1/vendor/orders/{$orderId}/validate")
            ->assertStatus(422);
        $this->assertEquals(20000, $this->bal($c['seller']));
    }

    public function test_seller_previews_price_shown_to_buyers(): void
    {
        CommissionRange::create(['min_amount' => 0, 'max_amount' => 1000000, 'percentage' => 8, 'is_active' => true]);
        $seller = User::factory()->create();

        $this->actingAs($seller, 'sanctum')
            ->getJson('/api/v1/pricing/preview?price=5000&currency=XAF')
            ->assertOk()
            ->assertJsonPath('data.buyer_price', 5400)
            ->assertJsonPath('data.seller_price', 5000);
    }

    public function test_legacy_order_without_frozen_split_pays_everything_to_seller(): void
    {
        CommissionRange::create(['min_amount' => 0, 'max_amount' => 1000000, 'percentage' => 5, 'is_active' => true]);
        ['seller' => $seller, 'asso' => $asso, 'order' => $order, 'deliveryCommission' => $dc] = $this->makeOrder('wallet_kpay');

        app(OrderService::class)->settleOrder($order, $seller);

        $this->assertEquals(10000, $this->bal($seller));
        $this->assertEquals($dc, $this->bal($asso));
    }

    public function test_admin_configures_all_commissions(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->saveQuietly();

        $this->actingAs($admin)->get('/admin/settings?tab=commissions')
            ->assertOk()
            ->assertSee('Vente de produits')
            ->assertSee('Diaspo');

        $this->actingAs($admin)->put('/admin/settings/commissions/update', [
            'default_sale_commission_rate' => 7,
            'diaspo_commission_rate' => 4,
            'ranges' => [
                ['min_amount' => 0, 'max_amount' => 5000, 'percentage' => 12, 'is_active' => 1],
                ['min_amount' => 5001, 'max_amount' => 50000, 'percentage' => 8, 'is_active' => 1],
            ],
        ])->assertRedirect();

        $this->assertEquals(7, (float) \App\Models\Setting::get('default_sale_commission_rate'));
        $this->assertEquals(4, (float) \App\Models\Setting::get('diaspo_commission_rate'));
        $this->assertEquals(12, CommissionService::rateFor(3000));
        $this->assertEquals(8, CommissionService::rateFor(20000));
        $this->assertEquals(7, CommissionService::rateFor(90000));

        // Plages qui se chevauchent : refusées, rien n'est modifié.
        $this->actingAs($admin)->put('/admin/settings/commissions/update', [
            'default_sale_commission_rate' => 7,
            'diaspo_commission_rate' => 4,
            'ranges' => [
                ['min_amount' => 0, 'max_amount' => 5000, 'percentage' => 1, 'is_active' => 1],
                ['min_amount' => 4000, 'max_amount' => 9000, 'percentage' => 2, 'is_active' => 1],
            ],
        ])->assertSessionHas('error');
        $this->assertSame(2, CommissionRange::count());
    }

    // ───────────────────────── Remboursements ─────────────────────────

    public function test_rejected_card_order_is_refunded_to_buyer_wallet(): void
    {
        ['client' => $client, 'seller' => $seller, 'order' => $order, 'total' => $total] = $this->makeOrder('stripe_direct');

        $this->actingAs($seller, 'sanctum')
            ->postJson("/api/v1/vendor/orders/{$order->id}/reject", ['reason' => 'Rupture'])
            ->assertOk();

        $this->assertEquals($total, $this->available($client));
        $order->refresh();
        $this->assertSame('refunded', $order->payment_status);
        $this->assertNotNull($order->refunded_at);
    }

    public function test_buyer_cancel_of_wallet_order_unlocks_funds(): void
    {
        ['client' => $client, 'order' => $order, 'total' => $total] = $this->makeOrder('wallet_kpay');
        $this->assertEquals(0, $this->available($client));

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/v1/orders/{$order->id}/cancel")
            ->assertOk()
            ->assertJsonPath('refunded_amount', (int) $total);

        $this->assertEquals($total, $this->available($client));
        $this->assertEquals($total, $this->bal($client));
    }

    public function test_unpaid_direct_order_cancel_refunds_nothing(): void
    {
        ['client' => $client, 'order' => $order] = $this->makeOrder('kpay_direct', 'pending');

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/v1/orders/{$order->id}/cancel")
            ->assertOk()
            ->assertJsonPath('refunded_amount', 0);

        $this->assertEquals(0, $this->bal($client));
        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_payment_received_after_cancellation_is_credited_to_wallet(): void
    {
        ['client' => $client, 'order' => $order, 'total' => $total] = $this->makeOrder('kpay_direct', 'failed', ['status' => 'cancelled']);

        $service = app(OrderService::class);
        $service->confirmKpayOrderPayment($order);
        $service->confirmKpayOrderPayment($order); // idempotent

        $this->assertEquals($total, $this->available($client));
        $this->assertSame('refunded', $order->fresh()->payment_status);
    }

    // ───────────────────────── Moyens de paiement / versement ─────────────────────────

    public function test_payment_methods_include_wallet_only_on_request(): void
    {
        $user = User::factory()->create();
        $this->setBalance($user, 2000, 500);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/payments/methods?amount=1000&currency=XAF')
            ->assertOk()
            ->assertJsonMissing(['code' => 'wallet']);

        $res = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/payments/methods?amount=3000&currency=XAF&include_wallet=1')
            ->assertOk();

        $wallet = $res->json('data.methods.0');
        $this->assertSame('wallet', $wallet['code']);
        $this->assertEquals(1500, $wallet['balance']);
        $this->assertFalse($wallet['available']);
        $this->assertSame('insufficient_balance', $wallet['unavailable_reason']);
        $this->assertEquals(1500, $wallet['missing_amount']);
    }

    public function test_payout_account_can_be_saved_updated_and_deleted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/wallet/payout-account')
            ->assertOk()->assertJsonPath('data', null);

        $this->actingAs($user, 'sanctum')->putJson('/api/v1/wallet/payout-account', [
            'provider' => 'NOPE', 'phone_number' => '237670000001',
        ])->assertStatus(422);

        $this->actingAs($user, 'sanctum')->putJson('/api/v1/wallet/payout-account', [
            'provider' => 'ORANGE_CMR', 'phone_number' => '237690000001', 'account_holder' => 'Jean Test',
        ])->assertOk()->assertJsonPath('data.currency', 'XAF');

        $this->actingAs($user, 'sanctum')->putJson('/api/v1/wallet/payout-account', [
            'provider' => 'ORANGE_SEN', 'phone_number' => '221770000001',
        ])->assertOk()->assertJsonPath('data.currency', 'XOF');

        $this->assertDatabaseCount('payout_accounts', 1);

        $this->actingAs($user, 'sanctum')->deleteJson('/api/v1/wallet/payout-account')->assertOk();
        $this->assertDatabaseCount('payout_accounts', 0);
    }
}
