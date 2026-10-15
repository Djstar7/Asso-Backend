<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Package;
use App\Models\PackageSubscription;
use App\Models\PaymentGatewayFee;
use App\Models\Product;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\KPayService;
use App\Services\PlatformRevenueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Revenus ASSO : CA par service, pertes et charges déduites, net, mouvements
 * détaillés, et frais KPay réellement prélevés.
 */
class PlatformRevenueTest extends TestCase
{
    use RefreshDatabase;

    private User $asso;
    private User $vendor;
    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asso = User::factory()->create(['email' => 'admin@asso.com', 'role' => 'admin', 'roles' => ['admin']]);
        $this->vendor = User::factory()->create(['role' => 'vendeur']);
        $this->buyer = User::factory()->create(['role' => 'client']);
    }

    private function subscription(string $type, float $amount): void
    {
        $package = Package::create(['type' => $type, 'name' => "Forfait {$type}", 'price' => $amount, 'duration_days' => 30, 'reach_users' => 100, 'is_active' => true]);
        PackageSubscription::create([
            'user_id' => $this->vendor->id, 'package_id' => $package->id, 'payment_method' => 'wallet',
            'status' => 'paid', 'amount_xaf' => $amount, 'paid_at' => now()->subDay(),
        ]);
    }

    private function settledOrder(User $seller, float $sellerPrice, float $commission, float $deliveryCommission = 0, bool $freeDelivery = false, string $status = 'delivered'): Order
    {
        $order = Order::create([
            'user_id' => $this->buyer->id, 'status' => $status,
            'subtotal' => $sellerPrice + $commission, 'total' => $sellerPrice + $commission, 'delivery_fee' => 0,
            'delivery_commission' => $deliveryCommission, 'free_delivery' => $freeDelivery,
            'sale_commission' => $commission, 'payment_method' => 'wallet_kpay', 'payment_status' => 'paid',
            'settled_at' => now()->subDay(),
        ]);
        $product = Product::create([
            'user_id' => $seller->id, 'category_id' => Category::firstOrCreate(['slug' => 'mode'], ['name' => 'Mode'])->id,
            'name' => 'Article ' . uniqid(), 'price' => $sellerPrice, 'stock' => 10, 'status' => 'active',
        ]);
        $order->items()->create([
            'product_id' => $product->id, 'seller_id' => $seller->id, 'quantity' => 1,
            'unit_price' => $sellerPrice + $commission, 'total_price' => $sellerPrice + $commission,
            'seller_unit_price' => $sellerPrice, 'seller_total_price' => $sellerPrice,
        ]);

        return $order;
    }

    public function test_revenue_by_service_losses_and_net(): void
    {
        $this->subscription('certification', 15000);
        $this->subscription('boost', 5000);
        $this->subscription('storage', 3500);

        // Commande classique : commission 1 000 + livraison 500 (livraison offerte au client).
        $this->settledOrder($this->vendor, 10000, 1000, 500, true);
        // Produit ASSO : part vendeur 20 000 revient à ASSO, commission 2 000 comptée à part.
        $this->settledOrder($this->asso, 20000, 2000);
        // Clôture par répartition d'acompte : la commission n'est pas acquise.
        $this->settledOrder($this->vendor, 50000, 5000, 0, false, 'cancelled');

        $credit = fn (string $code, float $amount) => WalletTransaction::create([
            'user_id' => $this->asso->id, 'type' => 'credit', 'amount' => $amount, 'balance_before' => 0, 'balance_after' => $amount,
            'description' => WalletTransaction::label($code, ['booking' => 1, 'order_number' => 'X']), 'status' => 'completed', 'provider' => 'kpay',
        ]);
        $credit('asso_commission_diaspo', 700);
        $credit('deposit_asso_share', 4000);

        // Retour sur la commande classique : commission (1 000) rendue.
        $returned = $this->settledOrder($this->vendor, 10000, 1000);
        $item = $returned->items()->first();
        $disputeId = DB::table('disputes')->insertGetId([
            'number' => 'L-0001', 'order_id' => $returned->id, 'order_item_id' => $item->id, 'client_id' => $this->buyer->id,
            'seller_id' => $this->vendor->id, 'reason' => 'damaged', 'description' => 'Abîmé', 'status' => 'refunded',
            'held_amount' => 10000, 'refunded_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('dispute_shipments')->insert([
            'dispute_id' => $disputeId, 'type' => 'return', 'payer' => 'asso', 'payment_status' => 'paid',
            'carrier_amount' => 2500, 'paid_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        PaymentGatewayFee::create([
            'gateway' => 'kpay', 'direction' => 'payin', 'gateway_id' => 'pay_1', 'amount' => 10000,
            'net_amount' => 9800, 'fee_amount' => 200, 'currency' => 'XAF', 'fee_amount_xaf' => 200, 'completed_at' => now(),
        ]);
        // Frais d'un retrait : à la charge du bénéficiaire, jamais déduits.
        PaymentGatewayFee::create([
            'gateway' => 'kpay', 'direction' => 'payout', 'gateway_id' => 'wdr_1', 'amount' => 5000,
            'net_amount' => 4750, 'fee_amount' => 250, 'currency' => 'XAF', 'fee_amount_xaf' => 250, 'completed_at' => now(),
        ]);

        $summary = app(PlatformRevenueService::class)->summary('30d');
        $s = collect($summary['sources'])->map(fn ($r) => $r['amount']);

        $this->assertEquals(15000, $s['package_certification']);
        $this->assertEquals(5000, $s['package_boost']);
        $this->assertEquals(3500, $s['package_storage']);
        $this->assertEquals(4000, $s['sale_commission']);        // 1 000 + 2 000 + 1 000 (commande retournée)
        $this->assertEquals(500, $s['delivery_commission']);     // livraison offerte : ASSO garde sa part
        $this->assertEquals(700, $s['diaspo_commission']);
        $this->assertEquals(4000, $s['deposit_kept']);
        $this->assertEquals(20000, $s['platform_products']);
        $this->assertEquals(1000, $s['dispute_refunds']);
        $this->assertEquals(2500, $s['asso_shipments']);
        $this->assertEquals(200, $s['gateway_fees']);

        $this->assertEquals(52700, $summary['totals']['revenue']);
        $this->assertEquals(3700, $summary['totals']['losses']);
        $this->assertEquals(49000, $summary['totals']['net']);
        $this->assertEquals(49000, collect($summary['series'])->sum('net'));
    }

    public function test_admin_sees_dashboard_movements_and_export(): void
    {
        $this->subscription('certification', 15000);
        $this->settledOrder($this->vendor, 10000, 1000, 500);

        $this->actingAs($this->asso)->get('/admin/revenue?period=30d')
            ->assertOk()->assertSee('Revenus ASSO')->assertSee('Certification')->assertSee('16 500 FCFA');

        $this->actingAs($this->asso)->get('/admin/revenue/sale_commission?period=30d')
            ->assertOk()->assertSee('Commission sur les produits')->assertSee('1 000 FCFA');

        $this->actingAs($this->asso)->get('/admin/revenue/inconnue')->assertNotFound();

        $csv = $this->actingAs($this->asso)->get('/admin/revenue/export?period=30d')->assertOk()->streamedContent();
        $this->assertStringContainsString('Certification', $csv);
        $this->assertStringContainsString('Commission sur les livraisons', $csv);
    }

    public function test_product_manager_without_permission_is_denied(): void
    {
        $manager = User::factory()->create(['role' => 'product_manager', 'roles' => ['product_manager']]);

        $this->actingAs($manager)->get('/admin/revenue')->assertForbidden();
    }

    public function test_kpay_fee_is_captured_from_authenticated_status_read(): void
    {
        Http::fake(['*/api/v1/payments/pay_42' => Http::response([
            'id' => 'pay_42', 'status' => 'COMPLETED', 'amount' => 5000, 'netAmount' => 4900, 'feeAmount' => 100,
            'currency' => 'XAF', 'externalId' => 'ORD-1', 'completedAt' => now()->toIso8601String(),
        ])]);

        app(KPayService::class)->checkPaymentStatus('pay_42');
        app(KPayService::class)->checkPaymentStatus('pay_42');

        $fee = PaymentGatewayFee::sole();
        $this->assertSame('payin', $fee->direction);
        $this->assertEquals(100, $fee->fee_amount_xaf);
        $this->assertSame('ORD-1', $fee->external_id);
    }

    /** Réponse réelle d'ElgioPay (GET /api/v1/payments/{id}) : 30 FCFA de frais sur 1 000. */
    private function elgiopayCompleted(): array
    {
        return [
            'transaction_id' => 'TXN_ABC123DEF456', 'type' => 'CHARGE', 'status' => 'completed',
            'amount' => ['total' => 1000, 'currency' => 'XAF', 'fees' => 30, 'net_amount' => 970],
            'payment' => ['method' => 'mtn_mobile_money'],
            'customer' => ['phone' => '+237600000000', 'name' => 'John Doe', 'email' => 'john@example.com'],
            'metadata' => ['order_id' => 'ORD-123'], 'description' => 'Order ORD-123',
            'created_at' => '2026-05-20T10:30:00+00:00', 'updated_at' => '2026-05-20T10:32:15+00:00',
            'completed_at' => '2026-05-20T10:32:15+00:00',
        ];
    }

    private function enableElgioPay(): void
    {
        \App\Models\ServiceConfiguration::setConfig('elgiopay', ['mode' => 'live', 'public_key' => 'pk_live_x', 'secret_key' => 'sk_live_x'], true);
    }

    public function test_elgiopay_fee_is_captured_and_counted_as_asso_charge(): void
    {
        $this->enableElgioPay();
        Http::fake(['*/api/v1/payments/TXN_ABC123DEF456' => Http::response($this->elgiopayCompleted())]);

        app(\App\Services\MobileMoneyGateway::class)->checkPaymentStatus('elgiopay:TXN_ABC123DEF456');
        app(\App\Services\MobileMoneyGateway::class)->checkPaymentStatus('elgiopay:TXN_ABC123DEF456');

        $fee = PaymentGatewayFee::sole();
        $this->assertSame('elgiopay', $fee->gateway);
        $this->assertSame('elgiopay:TXN_ABC123DEF456', $fee->gateway_id);
        $this->assertSame('payin', $fee->direction);
        $this->assertEquals(1000, $fee->amount);
        $this->assertEquals(970, $fee->net_amount);
        $this->assertEquals(30, $fee->fee_amount_xaf);
        $this->assertSame('ORD-123', $fee->external_id);
        $this->assertSame('mtn_mobile_money', $fee->provider);

        $this->assertEquals(30, app(\App\Services\PlatformRevenueService::class)->summary('all')['sources']['gateway_fees']['amount'] ?? null);
    }

    public function test_elgiopay_pending_payment_records_no_fee(): void
    {
        $this->enableElgioPay();
        Http::fake(['*/api/v1/payments/TX9*' => Http::response(['transaction_id' => 'TX9', 'status' => 'pending', 'amount' => ['total' => 1000, 'fees' => 30]])]);

        app(\App\Services\ElgioPayService::class)->checkPaymentStatus('TX9');

        $this->assertSame(0, PaymentGatewayFee::count());
    }

    public function test_sync_command_reads_missing_elgiopay_fees(): void
    {
        $this->enableElgioPay();
        Http::fake(['*/api/v1/payments/TXN_ABC123DEF456' => Http::response($this->elgiopayCompleted())]);
        WalletTransaction::create([
            'user_id' => $this->buyer->id, 'type' => 'credit', 'amount' => 1000, 'balance_before' => 0, 'balance_after' => 1000,
            'description' => 'Recharge', 'status' => 'completed', 'provider' => 'kpay',
            'metadata' => ['provider_reference' => 'elgiopay:TXN_ABC123DEF456'],
        ]);

        $this->artisan('kpay:sync-fees', ['--days' => 0])->assertSuccessful();

        $this->assertEquals(30, PaymentGatewayFee::where('gateway', 'elgiopay')->sole()->fee_amount_xaf);
    }

    public function test_pending_payment_records_no_fee(): void
    {
        Http::fake(['*/api/v1/payments/pay_7' => Http::response(['id' => 'pay_7', 'status' => 'PENDING', 'amount' => 5000])]);

        app(KPayService::class)->checkPaymentStatus('pay_7');

        $this->assertSame(0, PaymentGatewayFee::count());
    }

    public function test_sync_command_reuses_stored_kpay_response(): void
    {
        Http::fake();
        WalletTransaction::create([
            'user_id' => $this->buyer->id, 'type' => 'credit', 'amount' => 100, 'balance_before' => 0, 'balance_after' => 100,
            'description' => 'Recharge', 'status' => 'completed', 'provider' => 'kpay',
            'metadata' => ['provider_reference' => '10b6b5fb-a381-48ed-a5e5-1f97fe573d44', 'kpay_response' => ['data' => [
                'id' => '10b6b5fb-a381-48ed-a5e5-1f97fe573d44', 'status' => 'COMPLETED', 'amount' => 100,
                'netAmount' => 98, 'feeAmount' => 2, 'currency' => 'XAF', 'externalId' => 'WALLET-65',
            ]]],
        ]);

        $this->artisan('kpay:sync-fees', ['--days' => 0])->assertSuccessful();

        $this->assertEquals(2, PaymentGatewayFee::sole()->fee_amount_xaf);
        Http::assertNothingSent();
    }
}
