<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DelivererCompany;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Shop;
use App\Models\User;
use App\Models\WalletBalance;
use App\Services\CommissionService;
use App\Services\FcmService;
use App\Services\FirebaseMessagingService;
use App\Services\KPayService;
use App\Services\OrderTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Commande avec acompte : acompte (+ livraison) à la commande, solde payé seulement
 * après la livraison et la vérification conjointe client + employé ASSO. Le vendeur
 * n'est réglé qu'au paiement du solde ; la remise exige le solde payé.
 */
class DepositOrderTest extends TestCase
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
        // Vente +5 % ; livraison 400 HT + 25 % = 500 affichés.
        Setting::set('default_sale_commission_rate', '5', 'string', 'commissions');
        Setting::set('delivery_commission_rate', '25', 'string', 'commissions');
    }

    private function setBalance(User $u, float $balance): void
    {
        WalletBalance::updateOrCreate(
            ['user_id' => $u->id, 'currency' => 'XAF'],
            ['balance' => $balance, 'locked_balance' => 0]
        );
    }

    private function bal(User $u): float
    {
        return $u->fresh()->kpayBalanceFor('XAF');
    }

    private function locked(User $u): float
    {
        return $u->fresh()->kpayBalanceFor('XAF') - $u->fresh()->kpayAvailableFor('XAF');
    }

    /** Produit à 10 000 (vendeur), acompte 20 %, livraison locale à 500. */
    private function catalog(bool $deposit = true): array
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
            'is_active' => true,
        ]);
        $category = Category::create(['name' => 'Auto', 'slug' => 'auto-' . uniqid()]);
        $shop = Shop::create([
            'user_id' => $seller->id,
            'name' => 'Boutique ' . $seller->id,
            'slug' => 'boutique-' . $seller->id,
            'status' => 'active',
        ]);
        $product = Product::create([
            'user_id' => $seller->id,
            'shop_id' => $shop->id,
            'category_id' => $category->id,
            'name' => 'Voiture',
            'slug' => 'voiture-' . uniqid(),
            'price' => 10000,
            'currency' => 'XAF',
            'stock' => 10,
            'status' => 'active',
            'weight' => '1.5',
            'deposit_enabled' => $deposit,
            'deposit_rate' => $deposit ? 20 : null,
        ]);

        return compact('seller', 'delivererUser', 'company', 'zone', 'shop', 'product', 'category');
    }

    private function placeOrder(array $c, array $items = null): \Illuminate\Testing\TestResponse
    {
        $client = User::factory()->create();
        $this->setBalance($client, 50000);

        return $this->actingAs($client, 'sanctum')->postJson('/api/v1/orders', [
            'items' => $items ?? [['product_id' => $c['product']->id, 'quantity' => 2]],
            'delivery_company_id' => $c['company']->id,
            'delivery_zone_id' => $c['zone']->id,
            'payment_mode' => 'wallet',
            'wallet_provider' => 'kpay',
            'customer_phone' => '237670000001',
        ]);
    }

    /** Commande validée par le vendeur puis présentée au client par le livreur. */
    private function presentedOrder(array $c): Order
    {
        $orderId = $this->placeOrder($c)->assertCreated()->json('order_id');
        $order = Order::with('user')->findOrFail($orderId);

        $this->actingAs($c['seller'], 'sanctum')
            ->postJson("/api/v1/vendor/orders/{$order->id}/validate")
            ->assertOk()
            ->assertJsonPath('order.payment_plan', 'deposit')
            ->assertJsonPath('order.settled', false)
            ->assertJsonPath('order.deposit.balance_status', 'locked');

        $order->update(['status' => 'shipped', 'shipped_at' => now(), 'delivery_person_id' => $c['delivererUser']->id]);
        app(OrderTrackingService::class)->record($order, 'out_for_delivery', null, null, 'deliverer', $c['delivererUser']->id);

        return $order->fresh('user');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'roles' => ['admin']]);
    }

    public function test_order_charges_only_the_deposit_and_delivery(): void
    {
        $c = $this->catalog();
        $order = Order::with('user')->findOrFail($this->placeOrder($c)->assertCreated()->json('order_id'));

        // 2 × 10 500 = 21 000 ; acompte 20 % = 4 200 + livraison 500.
        $this->assertTrue($order->isDepositOrder());
        $this->assertEquals(21500, (float) $order->total);
        $this->assertEquals(4700, (float) $order->deposit_amount);
        $this->assertEquals(16800, (float) $order->balance_amount);
        $this->assertSame(Order::BALANCE_LOCKED, $order->balance_status);
        $this->assertSame(Order::VERIFICATION_PENDING, $order->verification_status);
        $this->assertEquals(4700, $this->locked($order->user));

        $this->actingAs($order->user, 'sanctum')->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('order.payment_plan', 'deposit')
            ->assertJsonPath('order.deposit.deposit_amount', 4700)
            ->assertJsonPath('order.deposit.balance_payable', false);
    }

    public function test_full_flow_settles_only_after_verification_and_balance(): void
    {
        $c = $this->catalog();
        $asso = User::factory()->create(['email' => 'admin@asso.com']);
        $order = $this->presentedOrder($c);
        $client = $order->user;

        // Validation vendeur : aucun règlement tant que le solde n'est pas payé.
        $this->assertEquals(0, $this->bal($c['seller']));
        $this->assertSame(Order::VERIFICATION_TO_CONTACT, $order->verification_status);

        // Solde bloqué avant la vérification ; code non remis ; remise refusée.
        $this->actingAs($client, 'sanctum')->postJson("/api/v1/orders/{$order->id}/pay-balance", ['payment_mode' => 'wallet'])
            ->assertStatus(422);
        $this->actingAs($client, 'sanctum')->getJson("/api/v1/orders/{$order->id}")
            ->assertJsonMissingPath('order.confirmation_code');
        $this->actingAs($c['delivererUser'], 'sanctum')
            ->postJson("/api/v1/delivery/{$order->id}/complete", ['confirmation_code' => $order->confirmation_code])
            ->assertStatus(422);

        // Vérification conjointe validée par l'employé ASSO.
        $admin = $this->admin();
        $this->actingAs($admin)->get('/admin/deposit-orders')->assertOk()->assertSee($order->order_number);
        $this->actingAs($admin)->get("/admin/deposit-orders/{$order->id}")->assertOk()->assertSee('VALIDER LA VÉRIFICATION');
        $this->actingAs($admin)->post("/admin/deposit-orders/{$order->id}/contact", ['note' => 'Appel client'])->assertRedirect();
        $this->actingAs($admin)->post("/admin/deposit-orders/{$order->id}/validate")->assertRedirect()->assertSessionHas('success');
        $order->refresh();
        $this->assertSame(Order::VERIFICATION_VERIFIED, $order->verification_status);
        $this->assertSame(Order::BALANCE_UNLOCKED, $order->balance_status);
        $this->assertSame($admin->id, $order->verified_by);
        $this->assertNotNull($order->verified_at);

        // Le client paie le solde depuis son Wallet : commande réglée.
        $this->actingAs($client, 'sanctum')->postJson("/api/v1/orders/{$order->id}/pay-balance", ['payment_mode' => 'wallet'])
            ->assertOk()
            ->assertJsonPath('order.deposit.balance_status', 'paid');

        $order->refresh();
        $this->assertNotNull($order->settled_at);
        $this->assertEquals(20000, $this->bal($c['seller']));
        $this->assertEquals(400, $this->bal($c['delivererUser']));
        $this->assertEquals(1000 + 100, $this->bal($asso));
        $this->assertEquals(50000 - 21500, $this->bal($client));
        $this->assertEquals(0, $this->locked($client));

        // Remise : le code est désormais accepté.
        $this->actingAs($c['delivererUser'], 'sanctum')
            ->postJson("/api/v1/delivery/{$order->id}/complete", ['confirmation_code' => $order->confirmation_code])
            ->assertOk();
        $this->assertSame('delivered', $order->fresh()->status);
    }

    public function test_balance_paid_by_mobile_money_through_webhook(): void
    {
        $c = $this->catalog();
        User::factory()->create(['email' => 'admin@asso.com']);
        $order = $this->presentedOrder($c);
        $this->actingAs($this->admin())->post("/admin/deposit-orders/{$order->id}/validate")->assertRedirect();

        $this->mock(\App\Services\MobileMoneyGateway::class, function ($mock) {
            $mock->shouldReceive('initializePayment')->once()
                ->withArgs(fn ($p) => $p['external_reference'] === Order::first()->order_number . '-SOLDE' && (float) $p['amount'] === 16800.0)
                ->andReturn(['success' => true, 'id' => 'kp-balance-1']);
        });

        $this->actingAs($order->user, 'sanctum')->postJson("/api/v1/orders/{$order->id}/pay-balance", [
            'payment_mode' => 'kpay_direct',
            'provider' => 'MTN_CM',
            'phone_number' => '237670000001',
        ])->assertOk()->assertJsonPath('payment_reference', 'kp-balance-1');
        $this->assertSame(Order::BALANCE_UNLOCKED, $order->fresh()->balance_status);
        $this->assertEquals(0, $this->bal($c['seller']));

        \App\Models\ServiceConfiguration::updateOrCreate(['service_name' => 'kpay'], [
            'display_name' => 'KPay',
            'is_active' => false,
            'configuration' => ['webhook_secret' => 'secret'],
        ]);
        $body = json_encode(['externalId' => $order->order_number . '-SOLDE', 'status' => 'COMPLETED']);
        $this->call('POST', '/api/v1/payments/webhook/kpay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_KPAY_SIGNATURE' => hash_hmac('sha256', $body, 'secret'),
        ], $body)->assertOk();

        $order->refresh();
        $this->assertSame(Order::BALANCE_PAID, $order->balance_status);
        $this->assertEquals(20000, $this->bal($c['seller']));
        // Wallet : seul l'acompte était bloqué, il est prélevé au règlement.
        $this->assertEquals(50000 - 4700, $this->bal($order->user));
        $this->assertEquals(0, $this->locked($order->user));
    }

    /** ElgioPay actif : le solde passe par ElgioPay et son webhook (sans notre référence) le règle. */
    public function test_balance_paid_through_elgiopay_webhook(): void
    {
        \App\Models\ServiceConfiguration::setConfig('elgiopay', [
            'mode' => 'live', 'public_key' => 'pk_live_test', 'secret_key' => 'sk_live_test',
        ], true);
        $remoteStatus = 'pending';
        \Illuminate\Support\Facades\Http::fake(function ($request) use (&$remoteStatus) {
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/api/v1/payments')) {
                return \Illuminate\Support\Facades\Http::response(['success' => true, 'transaction_id' => 'TXBAL', 'status' => 'pending']);
            }
            if (preg_match('#/payments/TXBAL(/verify)?$#', $request->url())) {
                return \Illuminate\Support\Facades\Http::response(['transaction_id' => 'TXBAL', 'status' => $remoteStatus]);
            }
            return \Illuminate\Support\Facades\Http::response([], 500);
        });

        $c = $this->catalog();
        User::factory()->create(['email' => 'admin@asso.com']);
        $order = $this->presentedOrder($c);
        $this->actingAs($this->admin())->post("/admin/deposit-orders/{$order->id}/validate")->assertRedirect();

        $this->actingAs($order->user, 'sanctum')->postJson("/api/v1/orders/{$order->id}/pay-balance", [
            'payment_mode' => 'kpay_direct',
            'provider' => 'MTN_MOMO_CMR',
            'phone_number' => '237670000001',
        ])->assertOk()->assertJsonPath('payment_reference', 'elgiopay:TXBAL');
        $this->assertSame(Order::BALANCE_UNLOCKED, $order->fresh()->balance_status);

        $remoteStatus = 'completed';
        $raw = json_encode(['id' => 'evt_bal', 'event' => 'payment.completed', 'data' => ['transaction_id' => 'TXBAL', 'status' => 'completed']]);
        $t = time();
        $this->call('POST', '/api/v1/elgiopay/callback', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_ELGIOPAY_SIGNATURE' => 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $raw, 'sk_live_test'),
        ], $raw)->assertOk();

        $order->refresh();
        $this->assertSame(Order::BALANCE_PAID, $order->balance_status);
        $this->assertEquals(20000, $this->bal($c['seller']));
    }

    public function test_employee_closes_order_with_case_by_case_deposit_split(): void
    {
        $c = $this->catalog();
        $asso = User::factory()->create(['email' => 'admin@asso.com']);
        $order = $this->presentedOrder($c);
        $admin = $this->admin();

        $this->actingAs($admin)->post("/admin/deposit-orders/{$order->id}/issue", ['note' => 'Couleur non conforme'])->assertRedirect();
        $this->assertSame(Order::VERIFICATION_ISSUE, $order->fresh()->verification_status);

        // Répartition supérieure à l'acompte : refusée.
        $this->actingAs($admin)->post("/admin/deposit-orders/{$order->id}/close", [
            'refund_amount' => 4000, 'vendor_amount' => 1000, 'delivery_amount' => 0, 'note' => 'x',
        ])->assertSessionHas('error');

        $this->actingAs($admin)->post("/admin/deposit-orders/{$order->id}/close", [
            'refund_amount' => 3000, 'vendor_amount' => 800, 'delivery_amount' => 400, 'note' => 'Client renonce',
        ])->assertSessionHas('success');

        $order->refresh();
        $this->assertSame('cancelled', $order->status);
        $this->assertSame(Order::BALANCE_CANCELLED, $order->balance_status);
        $this->assertEquals(50000 - 4700 + 3000, $this->bal($order->user));
        $this->assertEquals(0, $this->locked($order->user));
        $this->assertEquals(800, $this->bal($c['seller']));
        $this->assertEquals(400, $this->bal($c['delivererUser']));
        $this->assertEquals(500, $this->bal($asso));
        $this->assertEquals(10, $c['product']->fresh()->stock);
    }

    public function test_deposit_and_regular_products_cannot_be_mixed(): void
    {
        $c = $this->catalog();
        $regular = Product::create([
            'user_id' => $c['seller']->id,
            'shop_id' => $c['shop']->id,
            'category_id' => $c['category']->id,
            'name' => 'Tapis',
            'slug' => 'tapis-' . uniqid(),
            'price' => 5000,
            'currency' => 'XAF',
            'stock' => 10,
            'status' => 'active',
            'weight' => '1',
        ]);

        $this->placeOrder($c, [
            ['product_id' => $c['product']->id, 'quantity' => 1],
            ['product_id' => $regular->id, 'quantity' => 1],
        ])->assertStatus(422);
        $this->assertSame(0, Order::count());
    }

    public function test_vendor_toggles_deposit_and_product_exposes_it(): void
    {
        $c = $this->catalog(deposit: false);

        $this->actingAs($c['seller'], 'sanctum')
            ->putJson("/api/v1/vendor/products/{$c['product']->id}/deposit", ['deposit_enabled' => true])
            ->assertStatus(422);
        $this->actingAs($c['seller'], 'sanctum')
            ->putJson("/api/v1/vendor/products/{$c['product']->id}/deposit", ['deposit_enabled' => true, 'deposit_rate' => 30])
            ->assertOk();

        $this->assertTrue($c['product']->fresh()->requiresDeposit());
        $this->getJson("/api/v1/products/{$c['product']->id}")
            ->assertOk()
            ->assertJsonPath('product.deposit_enabled', true)
            ->assertJsonPath('product.deposit_rate', 30);
    }

    public function test_admin_sets_deposit_on_product(): void
    {
        $c = $this->catalog(deposit: false);
        $admin = $this->admin();

        $this->actingAs($admin)->put("/admin/products/{$c['product']->id}", [
            'shop_id' => $c['shop']->id,
            'category_id' => $c['category']->id,
            'name' => 'Voiture',
            'price_type' => 'fixed',
            'price' => 10000,
            'type' => 'article',
            'weight' => 1.5,
            'stock' => 10,
            'status' => 'active',
            'deposit_enabled' => '1',
            'deposit_rate' => 25,
        ])->assertRedirect(route('admin.products.index'));

        $product = $c['product']->fresh();
        $this->assertTrue($product->deposit_enabled);
        $this->assertEquals(25, $product->deposit_rate);
    }
}
