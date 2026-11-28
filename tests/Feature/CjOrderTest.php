<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DelivererCompany;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\SupplierOrder;
use App\Models\SupplierProduct;
use App\Models\SupplierVariant;
use App\Models\User;
use App\Models\WalletBalance;
use App\Services\CommissionService;
use App\Services\FirebaseMessagingService;
use App\Services\Suppliers\SupplierManager;
use App\Support\AssoScope;
use App\Support\ImportHub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class CjOrderTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://developers.cjdropshipping.com/api2.0/v1/';

    private Supplier $cj;

    private User $admin;

    private User $client;

    private Product $product;

    private ProductVariant $variant;

    /** Réponses CJ remplaçables par test, indexées par fin de chemin. */
    private array $responses = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['suppliers.cj.base_url' => rtrim(self::BASE, '/'), 'suppliers.cj.requests_per_second' => 0]);
        Sleep::fake();
        CommissionService::flush();
        (new \ReflectionProperty(AssoScope::class, 'ownerId'))->setValue(null, null);
        $this->mock(FirebaseMessagingService::class, fn ($mock) => $mock->shouldReceive('sendToUser')->andReturn([]));

        $platform = User::factory()->create(['email' => 'admin@asso.com']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $hub = ImportHub::ensureShop();
        $hub->update(['address' => 'Rue de l’entrepôt, Bonabéri', 'phone' => '+237600000000']);

        $this->cj = Supplier::forCode(Supplier::CJ);
        $this->cj->forceFill([
            'api_key' => 'CJ1@api@key', 'access_token' => 'tok', 'access_token_expires_at' => now()->addDays(100),
            'connected_at' => now(), 'is_active' => true, 'is_sandbox' => true,
        ])->save();

        $category = Category::create(['name' => 'Mode', 'name_en' => 'Fashion', 'slug' => 'mode']);
        $this->product = Product::create([
            'user_id' => $platform->id, 'shop_id' => $hub->id, 'category_id' => $category->id,
            'name' => 'Cat Ear Hoody', 'slug' => 'cat-ear-hoody', 'price' => 12000, 'stock' => 50, 'status' => 'active',
        ]);
        $this->variant = $this->product->variants()->create(['sku' => 'CJ-BLK-M', 'attributes' => ['Taille' => 'M'], 'price_adjustment' => 0, 'stock' => 50, 'is_active' => true]);
        $row = SupplierProduct::create([
            'supplier_id' => $this->cj->id, 'external_id' => 'PID-1', 'product_id' => $this->product->id, 'name' => 'Cat Ear Hoody',
            'status' => SupplierProduct::IMPORTED, 'cost_usd' => 10, 'freight_usd' => 5, 'logistic_name' => 'CJPacket Ordinary',
        ]);
        SupplierVariant::create(['supplier_product_id' => $row->id, 'external_id' => 'VID-M', 'external_sku' => 'CJ-BLK-M',
            'product_variant_id' => $this->variant->id, 'cost_usd' => 10, 'stock' => 50, 'is_active' => true]);

        $this->client = User::factory()->create();
        foreach ([$platform, $this->admin] as $user) {
            WalletBalance::updateOrCreate(['user_id' => $user->id, 'currency' => 'XAF'], ['balance' => 0, 'locked_balance' => 0]);
        }

        $this->responses = [
            'logistic/freightCalculate' => [['logisticName' => 'DHL', 'logisticPrice' => 30, 'logisticAging' => '5-8'], ['logisticName' => 'CJPacket Ordinary', 'logisticPrice' => 9, 'logisticAging' => '12-20']],
            'shopping/order/createOrderV2' => ['orderId' => 'CJ-100', 'orderStatus' => 'CREATED', 'orderAmount' => 28.5],
            'shopping/order/addCart' => ['successCount' => 1],
            'shopping/order/addCartConfirm' => ['successCount' => 1, 'submitSuccess' => true, 'shipmentsId' => 'SHIP-1', 'result' => 0, 'interceptOrders' => []],
            'shopping/order/saveGenerateParentOrder' => ['payId' => 'PAY-1', 'orderMoney' => 28.5, 'paymentInformation' => ['actualPayment' => 28.5]],
            'shopping/pay/payBalanceV2' => null,
            'shopping/pay/getBalance' => ['amount' => 500, 'freezeAmount' => 0],
            'shopping/order/deleteOrder' => 'CJ-100',
            'shopping/order/getOrderDetail' => ['orderId' => 'CJ-100', 'orderStatus' => 'SHIPPED', 'trackNumber' => 'YT123', 'logisticName' => 'CJPacket Ordinary'],
            'disputes/disputeProducts' => ['orderId' => 'CJ-100', 'productInfoList' => [
                ['lineItemId' => 'L1', 'cjVariantId' => 'VID-M', 'canChoose' => true, 'price' => 10.0, 'quantity' => 2, 'cjProductName' => 'Cat Ear Hoody', 'sku' => 'CJ-BLK-M'],
            ]],
            'disputes/disputeConfirmInfo' => ['maxAmount' => 20.0, 'expectResultOptionList' => ['1', '2'],
                'disputeReasonList' => [['disputeReasonId' => 4, 'reasonName' => 'Damaged item']]],
            'disputes/create' => true,
            'disputes/getDisputeList' => ['total' => 1, 'list' => [['disputeId' => 'SH-1', 'status' => 'Processing', 'refundMoney' => 0, 'finallyDeal' => null, 'createDate' => '1736937000000']]],
        ];
        Http::fake(function (HttpRequest $request) {
            $path = \Illuminate\Support\Str::after(strtok($request->url(), '?'), self::BASE);
            if (! array_key_exists($path, $this->responses)) {
                return Http::response(['code' => 1600100, 'result' => false, 'message' => 'unexpected '.$path, 'data' => null, 'success' => false]);
            }
            $data = $this->responses[$path];
            if (is_array($data) && isset($data['__error'])) {
                return Http::response(['code' => $data['__error'], 'result' => false, 'message' => 'error', 'data' => null, 'success' => false]);
            }

            return Http::response(['code' => 200, 'result' => true, 'message' => 'Success', 'data' => $data, 'requestId' => 'r', 'success' => true]);
        });
    }

    /** Commande payée par le Wallet (fonds bloqués), créée comme au checkout : dans une transaction. */
    private function paidOrder(int $quantity = 2, ?Product $product = null, ?int $variantId = -1): Order
    {
        $product ??= $this->product;
        $variantId = $variantId === -1 ? $this->variant->id : $variantId;
        $company = DelivererCompany::firstOrCreate(['name' => 'Livreur SARL'], ['user_id' => User::factory()->create()->id, 'is_active' => true]);
        $total = 12000 * $quantity + 1500;
        WalletBalance::updateOrCreate(['user_id' => $this->client->id, 'currency' => 'XAF'], ['balance' => $total, 'locked_balance' => $total]);

        return DB::transaction(function () use ($quantity, $product, $variantId, $company, $total) {
            $order = Order::create([
                'user_id' => $this->client->id, 'status' => 'pending', 'subtotal' => 12000 * $quantity,
                'delivery_fee' => 1500, 'base_delivery_price' => 1200, 'delivery_commission' => 300, 'total' => $total,
                'delivery_company_id' => $company->id, 'payment_method' => 'wallet_kpay', 'payment_status' => 'paid',
                'delivery_mode' => Order::DELIVERY_LOCAL,
            ]);
            $order->items()->create([
                'product_id' => $product->id, 'product_name' => $product->name, 'product_variant_id' => $variantId,
                'seller_id' => $product->user_id, 'quantity' => $quantity, 'unit_price' => 12000, 'total_price' => 12000 * $quantity,
            ]);

            return $order;
        });
    }

    private function supplierOrder(Order $order): SupplierOrder
    {
        return SupplierOrder::where('order_id', $order->id)->firstOrFail();
    }

    private function sent(string $path): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn (HttpRequest $r) => str_contains($r->url(), $path))->map(fn (HttpRequest $r) => $r->data())->values()->all();
    }

    public function test_a_paid_order_with_cj_products_waits_for_admin_validation(): void
    {
        $order = $this->paidOrder();

        $supplierOrder = $this->supplierOrder($order);
        $this->assertSame(SupplierOrder::AWAITING_APPROVAL, $supplierOrder->status);
        $this->assertSame('ASSO-'.$order->order_number, $supplierOrder->external_number);
        // (10 + 5) × 2
        $this->assertSame('30.00', $supplierOrder->expected_cost_usd);
        $this->assertSame('VID-M', $supplierOrder->items->first()->external_variant_id);
        Http::assertNothingSent();

        // Confirmation manuelle refusée dans Boutiques ASSO.
        $this->actingAs($this->admin)->post("/admin/asso-orders/{$order->id}/confirm")->assertSessionHas('error');
        $this->assertSame('pending', $order->fresh()->status);

        $this->actingAs($this->admin)->get('/admin/suppliers/cj/orders')->assertOk()->assertSee($order->order_number)->assertSee('À valider');
    }

    public function test_orders_without_cj_products_are_ignored(): void
    {
        $other = Product::create(['user_id' => $this->client->id, 'category_id' => $this->product->category_id,
            'name' => 'Local', 'slug' => 'local', 'price' => 12000, 'stock' => 5, 'status' => 'active']);

        $this->paidOrder(1, $other, null);

        $this->assertSame(0, SupplierOrder::count());
    }

    public function test_admin_validation_places_pays_and_confirms_the_order(): void
    {
        $order = $this->paidOrder();

        $this->actingAs($this->admin)->post('/admin/suppliers/cj/orders/submit', ['ids' => [$this->supplierOrder($order)->id]])
            ->assertSessionHas('success');

        $supplierOrder = $this->supplierOrder($order);
        $this->assertSame(SupplierOrder::PAID, $supplierOrder->status, (string) $supplierOrder->last_error);
        $this->assertSame(['CJ-100', 'SHIP-1', 'PAY-1'], [$supplierOrder->external_order_id, $supplierOrder->shipment_order_id, $supplierOrder->pay_id]);
        $this->assertSame('28.50', $supplierOrder->paid_cost_usd);
        $this->assertSame($this->admin->id, $supplierOrder->approved_by);

        $create = $this->sent('createOrderV2')[0];
        $this->assertSame(3, $create['payType']);
        $this->assertSame(1, $create['isSandbox']);
        $this->assertSame('CJPacket Ordinary', $create['logisticName']);
        $this->assertSame('CM', $create['shippingCountryCode']);
        $this->assertSame('Douala', $create['shippingCity']);
        $this->assertSame(ImportHub::NAME, $create['shippingCustomerName']);
        $this->assertStringContainsString('Bonabéri', $create['shippingAddress']);
        $this->assertSame([['vid' => 'VID-M', 'quantity' => 2, 'storeLineItemId' => (string) $order->items->first()->id]], $create['products']);
        $this->assertSame(['shipmentOrderId' => 'SHIP-1', 'payId' => 'PAY-1'], $this->sent('payBalanceV2')[0]);

        // Commande ASSO confirmée après le paiement CJ, sans demande de course.
        $this->assertSame('confirmed', $order->fresh()->status);
    }

    public function test_a_cost_above_tolerance_is_held_then_paid_on_request(): void
    {
        $order = $this->paidOrder();
        $this->responses['shopping/order/saveGenerateParentOrder'] = ['payId' => 'PAY-1', 'orderMoney' => 40];
        $orders = app(SupplierManager::class)->driver(Supplier::CJ)->orders();

        $orders->queueSubmission($this->supplierOrder($order), $this->admin->id);

        $supplierOrder = $this->supplierOrder($order);
        $this->assertSame(SupplierOrder::NEEDS_REVIEW, $supplierOrder->status);
        $this->assertStringContainsString('40,00 USD au lieu de 30,00', $supplierOrder->last_error);
        $this->assertSame([], $this->sent('payBalanceV2'));
        $this->assertSame('pending', $order->fresh()->status);

        $this->actingAs($this->admin)->get('/admin/suppliers/cj/orders')->assertSee('Payer quand même');
        $this->actingAs($this->admin)->post('/admin/suppliers/cj/orders/submit', ['ids' => [$supplierOrder->id], 'force' => 1]);

        $this->assertSame(SupplierOrder::PAID, $supplierOrder->fresh()->status);
        $this->assertCount(1, $this->sent('createOrderV2'));
        $this->assertCount(1, $this->sent('saveGenerateParentOrder'));
    }

    public function test_insufficient_balance_waits_for_a_top_up(): void
    {
        $this->cj->forceFill(['is_sandbox' => false])->save();
        $this->responses['shopping/pay/getBalance'] = ['amount' => 12, 'freezeAmount' => 0];
        $order = $this->paidOrder();

        app(SupplierManager::class)->driver(Supplier::CJ)->orders()->queueSubmission($this->supplierOrder($order), $this->admin->id);

        $supplierOrder = $this->supplierOrder($order);
        $this->assertSame(SupplierOrder::AWAITING_BALANCE, $supplierOrder->status);
        $this->assertSame(0, $this->sent('createOrderV2')[0]['isSandbox']);
        $this->assertSame([], $this->sent('payBalanceV2'));

        $this->responses['shopping/pay/getBalance'] = ['amount' => 100, 'freezeAmount' => 0];
        app(SupplierManager::class)->driver(Supplier::CJ)->orders()->queueSubmission($supplierOrder, $this->admin->id);
        $this->assertSame(SupplierOrder::PAID, $supplierOrder->fresh()->status);
    }

    public function test_no_order_leaves_for_cj_without_a_hub_phone(): void
    {
        ImportHub::shop()->update(['phone' => null]);
        $order = $this->paidOrder();
        $orders = app(SupplierManager::class)->driver(Supplier::CJ)->orders();

        $orders->queueSubmission($this->supplierOrder($order), $this->admin->id);

        $supplierOrder = $this->supplierOrder($order);
        $this->assertSame(SupplierOrder::NEEDS_REVIEW, $supplierOrder->status);
        $this->assertStringContainsString('téléphone', $supplierOrder->last_error);
        $this->assertSame([], $this->sent('createOrderV2'));
        $this->actingAs($this->admin)->get('/admin/suppliers/cj/orders')
            ->assertSee('Adresse de l’entrepôt de Douala incomplète', false)->assertSee('tél. aucun', false);

        // Téléphone saisi dans les réglages : l'envoi repart.
        $this->cj->putSettings(['ship_to_phone' => '+237699000000']);
        $orders->queueSubmission($supplierOrder, $this->admin->id);
        $this->assertSame(SupplierOrder::PAID, $supplierOrder->fresh()->status);
        $this->assertSame('+237699000000', $this->sent('createOrderV2')[0]['shippingPhone']);
    }

    public function test_the_hub_address_is_located_like_a_shop_address(): void
    {
        // Le formulaire reprend la localisation des boutiques, sur les champs ship_to_*.
        $this->actingAs($this->admin)->get('/admin/suppliers/cj/orders')->assertOk()
            ->assertSee('id="cj-hub-map_address"', false)
            ->assertSee('name="ship_to_address"', false)
            ->assertSee('name="ship_to_latitude"', false)
            ->assertSee('"state":"ship_to_province"', false);

        $this->actingAs($this->admin)->put('/admin/suppliers/cj/orders/settings', [
            'cost_tolerance_percent' => 15, 'ship_to_name' => 'ASSO Import Douala', 'ship_to_phone' => '+237699000000',
            'ship_to_address' => 'Rue Joss, Bonanjo, Douala, Littoral, Cameroun', 'ship_to_city' => 'Douala',
            'ship_to_province' => 'Littoral', 'ship_to_latitude' => '4.043', 'ship_to_longitude' => '9.69',
        ])->assertSessionHas('success');

        $orders = app(SupplierManager::class)->driver(Supplier::CJ)->orders();
        $this->assertNull($orders->shippingAddressProblem());
        $this->assertSame('Rue Joss, Bonanjo, Douala, Littoral, Cameroun', $orders->shippingAddress()['shippingAddress']);
        $this->assertEquals(4.043, (float) $this->cj->fresh()->setting('ship_to_latitude'));

        $this->actingAs($this->admin)->put('/admin/suppliers/cj/orders/settings', [
            'cost_tolerance_percent' => 15, 'ship_to_city' => 'Douala', 'ship_to_province' => 'Littoral', 'ship_to_latitude' => '123',
        ])->assertSessionHasErrors('ship_to_latitude');
    }

    public function test_cj_blocking_the_order_is_explained(): void
    {
        $this->responses['shopping/order/addCartConfirm'] = ['submitSuccess' => false, 'shipmentsId' => null, 'result' => 2,
            'interceptOrders' => [['orderCode' => 'CJ-100', 'interceptReason' => [['interceptReasonCode' => 2, 'simpleTip' => 'Insufficient inventory.']]]]];
        $order = $this->paidOrder();

        app(SupplierManager::class)->driver(Supplier::CJ)->orders()->queueSubmission($this->supplierOrder($order), $this->admin->id);

        $supplierOrder = $this->supplierOrder($order);
        $this->assertSame(SupplierOrder::NEEDS_REVIEW, $supplierOrder->status);
        $this->assertStringContainsString('Insufficient inventory.', $supplierOrder->last_error);
    }

    public function test_cancelling_before_payment_deletes_the_cj_order_and_refunds_the_client(): void
    {
        $this->responses['shopping/order/addCartConfirm'] = ['submitSuccess' => false, 'result' => 2, 'interceptOrders' => []];
        $order = $this->paidOrder();
        $orders = app(SupplierManager::class)->driver(Supplier::CJ)->orders();
        $orders->queueSubmission($this->supplierOrder($order), $this->admin->id);
        $stockBefore = $this->variant->fresh()->stock;

        $this->actingAs($this->admin)->post('/admin/suppliers/cj/orders/'.$this->supplierOrder($order)->id.'/cancel', ['reason' => 'Rupture chez le fournisseur'])
            ->assertSessionHas('success');

        $this->assertSame(SupplierOrder::CANCELLED, $this->supplierOrder($order)->status);
        $this->assertCount(1, $this->sent('deleteOrder'));
        $order->refresh();
        $this->assertSame('cancelled', $order->status);
        $this->assertSame('Rupture chez le fournisseur', $order->cancel_reason);
        $this->assertSame($stockBefore + 2, $this->variant->fresh()->stock);
        // Fonds du client débloqués.
        $this->assertEquals($this->client->fresh()->kpayBalanceFor('XAF'), $this->client->fresh()->kpayAvailableFor('XAF'));
    }

    public function test_a_paid_cj_order_cannot_be_cancelled_from_asso(): void
    {
        $order = $this->paidOrder();
        app(SupplierManager::class)->driver(Supplier::CJ)->orders()->queueSubmission($this->supplierOrder($order), $this->admin->id);

        $this->actingAs($this->admin)->post('/admin/suppliers/cj/orders/'.$this->supplierOrder($order)->id.'/cancel', ['reason' => 'x'])
            ->assertSessionHas('error');
        $this->assertSame(SupplierOrder::PAID, $this->supplierOrder($order)->status);
    }

    public function test_order_alerts_and_sync_update_tracking(): void
    {
        $order = $this->paidOrder();
        $orders = app(SupplierManager::class)->driver(Supplier::CJ)->orders();
        $orders->queueSubmission($this->supplierOrder($order), $this->admin->id);

        [$status] = $orders->onWebhook(['cjOrderId' => 'CJ-100', 'orderNumber' => 'ASSO-'.$order->order_number,
            'orderStatus' => 'SHIPPED', 'trackNumber' => 'YT999', 'logisticName' => 'CJPacket Ordinary'], 'UPDATE');
        $this->assertSame('processed', $status);
        $supplierOrder = $this->supplierOrder($order);
        $this->assertSame([SupplierOrder::SHIPPED, 'YT999'], [$supplierOrder->status, $supplierOrder->tracking_number]);

        $this->responses['shopping/order/getOrderDetail'] = ['orderId' => 'CJ-100', 'orderStatus' => 'DELIVERED', 'trackNumber' => 'YT999'];
        $supplierOrder->forceFill(['last_synced_at' => now()->subHours(2)])->save();
        $this->artisan('cj:sync-orders')->assertSuccessful();
        $this->assertSame(SupplierOrder::DELIVERED, $this->supplierOrder($order)->status);

        [$ignored] = $orders->onWebhook(['cjOrderId' => 'UNKNOWN', 'orderStatus' => 'SHIPPED'], 'UPDATE');
        $this->assertSame('ignored', $ignored);
    }

    public function test_cj_cancelling_a_paid_order_is_flagged_for_the_admin(): void
    {
        $order = $this->paidOrder();
        $orders = app(SupplierManager::class)->driver(Supplier::CJ)->orders();
        $orders->queueSubmission($this->supplierOrder($order), $this->admin->id);

        $orders->onWebhook(['cjOrderId' => 'CJ-100', 'orderStatus' => 'CANCELLED'], 'UPDATE');

        $supplierOrder = $this->supplierOrder($order);
        $this->assertSame(SupplierOrder::SUPPLIER_CANCELLED, $supplierOrder->status);
        $this->assertStringContainsString('remboursez le client', $supplierOrder->last_error);
    }

    public function test_cj_cancelling_an_unpaid_order_refunds_the_client(): void
    {
        $order = $this->paidOrder();
        $orders = app(SupplierManager::class)->driver(Supplier::CJ)->orders();

        $orders->onWebhook(['orderNumber' => 'ASSO-'.$order->order_number, 'orderStatus' => 'CANCELLED'], 'UPDATE');

        $this->assertSame(SupplierOrder::CANCELLED, $this->supplierOrder($order)->status);
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_sync_command_captures_missed_orders_and_closes_cancelled_ones(): void
    {
        $missed = $this->paidOrder();
        SupplierOrder::where('order_id', $missed->id)->delete();
        $cancelled = $this->paidOrder(1);
        Order::withoutGlobalScopes()->whereKey($cancelled->id)->update(['status' => 'cancelled']);

        $this->artisan('cj:sync-orders')->assertSuccessful();

        $this->assertSame(SupplierOrder::AWAITING_APPROVAL, $this->supplierOrder($missed)->status);
        $this->assertSame(SupplierOrder::CANCELLED, $this->supplierOrder($cancelled)->status);
    }

    public function test_auto_submit_sends_paid_orders_without_validation(): void
    {
        $this->cj->putSettings(['auto_submit' => true]);

        $order = $this->paidOrder();

        $supplierOrder = $this->supplierOrder($order);
        $this->assertSame(SupplierOrder::PAID, $supplierOrder->status);
        $this->assertNull($supplierOrder->approved_by);
        $this->assertSame('confirmed', $order->fresh()->status);
    }

    /** Commande payée chez CJ (état « Payée, en préparation »). */
    private function paidAtCj(): array
    {
        $order = $this->paidOrder();
        $orders = app(SupplierManager::class)->driver(Supplier::CJ)->orders();
        $orders->queueSubmission($this->supplierOrder($order), $this->admin->id);
        $this->assertSame(SupplierOrder::PAID, $this->supplierOrder($order)->status);

        return [$order, $orders];
    }

    public function test_no_courier_sees_the_order_before_the_parcel_reaches_douala(): void
    {
        \Illuminate\Support\Facades\Queue::fake([\App\Jobs\CheckDeliveryAcceptanceJob::class]);
        [$order, $orders] = $this->paidAtCj();

        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertFalse(Order::withoutGlobalScopes()->awaitingCourier()->whereKey($order->id)->exists());
        // Date prévue : paiement + 20 jours (CJPacket « 12-20 ») + 3 jours de préparation.
        $this->assertTrue($this->supplierOrder($order)->expected_hub_at->between(now()->addDays(23)->subMinute(), now()->addDays(23)->addMinute()));
        \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\CheckDeliveryAcceptanceJob::class);

        $orders->onWebhook(['cjOrderId' => 'CJ-100', 'orderStatus' => 'SHIPPED', 'trackNumber' => 'YT777', 'logisticName' => 'CJPacket Ordinary'], 'UPDATE');
        $this->actingAs($this->admin)->post('/admin/suppliers/cj/orders/receive', ['code' => 'YT777'])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'course est partie'));

        $supplierOrder = $this->supplierOrder($order);
        $this->assertSame(SupplierOrder::RECEIVED, $supplierOrder->status);
        $this->assertSame($this->admin->id, $supplierOrder->hub_received_by);
        $this->assertSame('arrived_hub', $order->fresh()->tracking_status);
        $this->assertTrue(Order::withoutGlobalScopes()->awaitingCourier()->whereKey($order->id)->exists());
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\CheckDeliveryAcceptanceJob::class);

        // Un second scan ne refait rien.
        $this->actingAs($this->admin)->post('/admin/suppliers/cj/orders/receive', ['code' => 'YT777'])->assertSessionHas('error');
    }

    public function test_the_buyer_follows_the_parcel_from_china(): void
    {
        [$order, $orders] = $this->paidAtCj();

        $orders->onWebhook(['cjOrderId' => 'CJ-100', 'orderStatus' => 'SHIPPED', 'trackNumber' => 'YT777', 'logisticName' => 'CJPacket Ordinary'], 'UPDATE');
        $orders->onLogistics(['orderId' => 'CJ-100', 'trackingNumber' => 'YT777', 'trackingStatus' => 7,
            'logisticsTrackEvents' => json_encode([['status' => 7, 'activity' => 'Customs clearance', 'location' => 'Douala', 'eventTime' => '2026-12-05 10:00:00']])]);
        $orders->onLogistics(['orderId' => 'CJ-100', 'trackingStatus' => 8]);

        $steps = $order->trackingEvents()->pluck('note', 'step')->all();
        $this->assertArrayHasKey('in_transit', $steps);
        $this->assertStringContainsString('suivi YT777', $steps['in_transit']);
        $this->assertSame(1, $order->trackingEvents()->where('step', 'customs')->count());
        $this->assertStringContainsString('Customs clearance', $this->supplierOrder($order)->logistics_event);

        $orders->onLogistics(['orderId' => 'CJ-100', 'trackingStatus' => 13]);
        $this->assertStringContainsString('Incident transporteur', $this->supplierOrder($order)->last_error);
        $orders->onLogistics(['orderId' => 'CJ-100', 'trackingStatus' => 12]);
        $this->assertSame(SupplierOrder::DELIVERED, $this->supplierOrder($order)->status);
        // Livré selon CJ : pas encore « arrivé à l'entrepôt » tant qu'ASSO n'a pas réceptionné.
        $this->assertNotSame('arrived_hub', $order->fresh()->tracking_status);
    }

    public function test_late_parcels_are_flagged(): void
    {
        [$order] = $this->paidAtCj();
        $this->assertSame(0, SupplierOrder::late()->count());

        $this->travel(24)->days();

        $this->assertTrue($this->supplierOrder($order)->isLate());
        $this->actingAs($this->admin)->get('/admin/suppliers/cj/orders?status=late')->assertOk()->assertSee('En retard')->assertSee($order->order_number);
    }

    public function test_an_unpaid_order_cannot_be_received(): void
    {
        $order = $this->paidOrder();

        $this->actingAs($this->admin)->post('/admin/suppliers/cj/orders/'.$this->supplierOrder($order)->id.'/receive')->assertSessionHas('error');
        $this->assertSame(SupplierOrder::AWAITING_APPROVAL, $this->supplierOrder($order)->status);
    }

    public function test_admin_opens_a_cj_dispute_and_follows_it(): void
    {
        [$order] = $this->paidAtCj();
        $supplierOrder = $this->supplierOrder($order);

        $this->actingAs($this->admin)->get("/admin/suppliers/cj/orders/{$supplierOrder->id}/dispute")
            ->assertOk()->assertSee('Damaged item')->assertSee('Cat Ear Hoody');
        $this->actingAs($this->admin)->post("/admin/suppliers/cj/orders/{$supplierOrder->id}/dispute", [
            'reason_id' => 4, 'expect_type' => 1, 'message' => 'Parcel received damaged at our Douala warehouse.', 'quantities' => ['L1' => 1],
        ])->assertSessionHas('success');

        $dispute = $supplierOrder->disputes()->firstOrFail();
        $this->assertSame(['SH-1', 'Damaged item', '10.00'], [$dispute->external_dispute_id, $dispute->reason_name, $dispute->amount_usd]);
        $create = $this->sent('disputes/create')[0];
        $this->assertSame([['lineItemId' => 'L1', 'quantity' => 1, 'price' => 10.0]], $create['productInfoList']);
        $this->assertSame([1, 1, 4], [$create['expectType'], $create['refundType'], $create['disputeReasonId']]);

        $this->responses['disputes/getDisputeList'] = ['list' => [['disputeId' => 'SH-1', 'status' => 'Completed', 'refundMoney' => 10, 'finallyDeal' => 1]]];
        $dispute->forceFill(['last_synced_at' => now()->subHours(4)])->save();
        $this->artisan('cj:sync-orders')->assertSuccessful();

        $dispute->refresh();
        $this->assertSame([1, '10.00'], [$dispute->final_result, $dispute->refund_usd]);
        $this->assertNotNull($dispute->closed_at);
        $this->assertSame('Remboursé', $dispute->stateLabel());
    }

    public function test_a_dispute_needs_a_paid_order_and_a_valid_reason(): void
    {
        $order = $this->paidOrder();
        $this->actingAs($this->admin)->get('/admin/suppliers/cj/orders/'.$this->supplierOrder($order)->id.'/dispute')->assertOk()->assertSee('commande payée chez CJ');

        app(SupplierManager::class)->driver(Supplier::CJ)->orders()->queueSubmission($this->supplierOrder($order), $this->admin->id);
        $this->actingAs($this->admin)->post('/admin/suppliers/cj/orders/'.$this->supplierOrder($order)->id.'/dispute', [
            'reason_id' => 99, 'expect_type' => 1, 'message' => 'Parcel never arrived here.', 'quantities' => ['L1' => 1],
        ])->assertSessionHas('error');
        $this->assertSame([], $this->sent('disputes/create'));
    }

    public function test_the_buyer_api_shows_the_international_parcel(): void
    {
        [$order, $orders] = $this->paidAtCj();
        $api = fn () => $this->actingAs($this->client, 'sanctum')->getJson("/api/v1/orders/{$order->id}")->assertOk()->json();
        $find = fn (array $json) => data_get($json, 'order.delivery.international_shipment') ?? data_get($json, 'data.delivery.international_shipment');

        $shipment = $find($api());
        $this->assertSame('preparing', $shipment['stage']);
        $this->assertSame(now()->addDays(23)->toDateString(), $shipment['expected_hub_date']);
        $this->assertNull($shipment['tracking_number']);
        $this->assertStringNotContainsString('CJ', json_encode($shipment));

        $orders->onWebhook(['cjOrderId' => 'CJ-100', 'orderStatus' => 'SHIPPED', 'trackNumber' => 'YT777', 'logisticName' => 'YunExpress'], 'UPDATE');
        $shipment = $find($api());
        $this->assertSame(['in_transit', 'YT777', 'YunExpress'], [$shipment['stage'], $shipment['tracking_number'], $shipment['carrier_name']]);
    }

    public function test_the_vendor_app_cannot_confirm_an_order_waiting_for_cj(): void
    {
        $order = $this->paidOrder();
        $platform = User::where('email', 'admin@asso.com')->first();

        $this->actingAs($platform, 'sanctum')->postJson("/api/v1/vendor/orders/{$order->id}/validate")
            ->assertStatus(422)->assertJsonPath('message', __('orders.supplier_validation_required'));
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_product_detail_says_it_ships_from_abroad(): void
    {
        $this->getJson("/api/v1/products/{$this->product->id}")->assertOk()->assertJsonPath('product.ships_from_abroad', true);
    }
}
