<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\FirebaseMessagingService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Règlement des commandes payées en KPay direct (paiement encaissé direct).
 *
 * Vérifie le modèle unifié : après paiement, la commande reste 'pending' (le vendeur
 * doit la valider comme en mode wallet), sans crédit via pending_earnings ; et en cas
 * d'échec de paiement, le stock est restauré et la commande annulée.
 */
class KpayDirectOrderSettlementTest extends TestCase
{
    use RefreshDatabase;

    private function makeKpayDirectOrder(int $initialStock, int $qty, float $unitPrice): array
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create(['pending_earnings' => 0]);

        $category = Category::create(['name' => 'Test', 'slug' => 'test-' . uniqid()]);
        $product = Product::create([
            'user_id' => $seller->id,
            'category_id' => $category->id,
            'name' => 'Produit test',
            'slug' => 'produit-test-' . uniqid(),
            'price' => $unitPrice,
            'stock' => $initialStock, // déjà décrémenté à la création réelle ; ici on simule l'état post-création
            'status' => 'active',
        ]);

        $subtotal = $unitPrice * $qty;
        $order = Order::create([
            'user_id' => $buyer->id,
            'status' => 'pending',
            'subtotal' => $subtotal,
            'delivery_fee' => 500,
            'total' => $subtotal + 500,
            'payment_method' => 'kpay_direct',
            'payment_status' => 'pending',
            'payment_reference' => 'pay_test_123',
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'seller_id' => $seller->id,
            'quantity' => $qty,
            'unit_price' => $unitPrice,
            'total_price' => $subtotal,
        ]);

        return compact('buyer', 'seller', 'product', 'order');
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Neutraliser les notifications FCM (pas d'appel réseau en test)
        $this->mock(FirebaseMessagingService::class, function ($mock) {
            $mock->shouldReceive('sendToUser')->andReturn([]);
        });
    }

    public function test_confirm_marks_paid_keeps_pending_and_no_pending_earnings(): void
    {
        ['seller' => $seller, 'order' => $order] = $this->makeKpayDirectOrder(10, 2, 1000);

        app(OrderService::class)->confirmKpayOrderPayment($order);

        $order->refresh();
        $this->assertSame('paid', $order->payment_status, 'La commande doit être marquée payée');
        $this->assertSame('pending', $order->status, "La commande doit rester 'pending' (validation vendeur à venir)");

        // Le modèle pending_earnings ne doit PLUS être utilisé (crédit via escrow validate())
        $this->assertEquals(0, (float) $seller->fresh()->pending_earnings);

        // Trace de l'achat dans l'historique wallet du client (sans modifier le solde)
        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $order->user_id,
            'reference_type' => 'order',
            'reference_id' => $order->id,
            'type' => 'debit',
        ]);
    }

    public function test_confirm_is_idempotent(): void
    {
        ['order' => $order] = $this->makeKpayDirectOrder(10, 1, 1000);

        $service = app(OrderService::class);
        $service->confirmKpayOrderPayment($order);
        $service->confirmKpayOrderPayment($order->fresh());

        // Une seule trace créée malgré deux appels
        $this->assertEquals(1, \DB::table('wallet_transactions')
            ->where('reference_type', 'order')->where('reference_id', $order->id)->count());
    }

    public function test_fail_restores_stock_and_cancels_order(): void
    {
        // Stock 8 = état après décrément de 2 à la création
        ['product' => $product, 'order' => $order] = $this->makeKpayDirectOrder(8, 2, 1000);

        app(OrderService::class)->failKpayOrderPayment($order);

        $order->refresh();
        $this->assertSame('failed', $order->payment_status);
        $this->assertSame('cancelled', $order->status);
        // Stock restauré : 8 + 2 = 10
        $this->assertEquals(10, $product->fresh()->stock);
    }

    public function test_fail_is_noop_when_already_paid(): void
    {
        ['product' => $product, 'order' => $order] = $this->makeKpayDirectOrder(8, 2, 1000);
        $order->update(['payment_status' => 'paid']);

        app(OrderService::class)->failKpayOrderPayment($order);

        $order->refresh();
        $this->assertSame('paid', $order->payment_status, 'Ne doit pas écraser un paiement acquis');
        $this->assertNotSame('cancelled', $order->status);
        $this->assertEquals(8, $product->fresh()->stock, 'Le stock ne doit pas être re-crédité');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
