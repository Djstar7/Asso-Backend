<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Commande kpay:diagnose-legacy-orders : détection (et réparation du cas sûr) des
 * commandes kpay_direct « héritées » — payées et avancées mais sans escrow vendeur,
 * créées avant le fix du règlement unifié. Voir DEPLOY_NOTES_UNIFY.md § 2.
 */
class DiagnoseLegacyKpayOrdersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Commande kpay_direct payée + avancée. Par défaut SANS escrow vendeur (= héritée),
     * avec pending_earnings crédité (comme le faisait l'ancien code court-circuitant validate()).
     */
    private function makeLegacyOrder(string $status = 'confirmed', float $unitPrice = 1000, int $qty = 2): array
    {
        $buyer = User::factory()->create();
        $subtotal = $unitPrice * $qty;
        $seller = User::factory()->create(['pending_earnings' => $subtotal]);

        $category = Category::create(['name' => 'Test', 'slug' => 'cat-' . uniqid()]);
        $product = Product::create([
            'user_id' => $seller->id, 'category_id' => $category->id,
            'name' => 'Produit', 'slug' => 'prod-' . uniqid(),
            'price' => $unitPrice, 'stock' => 10, 'status' => 'active',
        ]);

        $order = Order::create([
            'user_id' => $buyer->id,
            'status' => $status,
            'confirmed_at' => now(),
            'subtotal' => $subtotal,
            'delivery_fee' => 500,
            'total' => $subtotal + 500,
            'payment_method' => 'kpay_direct',
            'payment_status' => 'paid',
            'payment_reference' => 'pay_legacy',
        ]);
        $order->items()->create([
            'product_id' => $product->id, 'seller_id' => $seller->id,
            'quantity' => $qty, 'unit_price' => $unitPrice, 'total_price' => $subtotal,
        ]);

        return compact('buyer', 'seller', 'order', 'subtotal');
    }

    public function test_report_detects_legacy_order_read_only(): void
    {
        ['order' => $order] = $this->makeLegacyOrder('confirmed');

        $this->artisan('kpay:diagnose-legacy-orders')->assertSuccessful();

        // Mode lecture seule : rien n'est modifié
        $this->assertSame('confirmed', $order->fresh()->status);
    }

    public function test_repair_resets_safe_confirmed_order(): void
    {
        ['order' => $order, 'seller' => $seller] = $this->makeLegacyOrder('confirmed');

        $this->artisan('kpay:diagnose-legacy-orders --repair')
            ->expectsConfirmation(
                "Réparer 1 commande(s) (retour en 'pending' + rollback pending_earnings) ?",
                'yes'
            )
            ->assertSuccessful();

        $order->refresh();
        $this->assertSame('pending', $order->status, 'La commande héritée doit repasser en pending');
        $this->assertNull($order->confirmed_at);
        // pending_earnings de l'ancien code annulé
        $this->assertEquals(0, (float) $seller->fresh()->pending_earnings);
    }

    public function test_order_with_seller_escrow_is_not_flagged(): void
    {
        ['order' => $order, 'seller' => $seller] = $this->makeLegacyOrder('confirmed');

        // Escrow vendeur présent → la commande a suivi le flux normal, PAS héritée
        WalletTransaction::create([
            'user_id' => $seller->id,
            'type' => 'credit',
            'amount' => 100,
            'description' => 'Escrow vendeur (test)',
            'balance_before' => 0,
            'balance_after' => 100,
            'reference_type' => 'order',
            'reference_id' => $order->id,
            'status' => 'completed',
            'provider' => 'kpay',
        ]);

        $this->artisan('kpay:diagnose-legacy-orders --repair')->assertSuccessful();

        // Non détectée → inchangée (aucune confirmation demandée)
        $this->assertSame('confirmed', $order->fresh()->status);
    }

    public function test_advanced_order_is_manual_not_auto_repaired(): void
    {
        // status='preparing' → héritée mais NON auto-réparable (cas manuel)
        ['order' => $order] = $this->makeLegacyOrder('preparing');

        $this->artisan('kpay:diagnose-legacy-orders --repair')->assertSuccessful();

        // Reste en preparing (traitement manuel requis, pas de confirmation demandée)
        $this->assertSame('preparing', $order->fresh()->status);
    }

    public function test_no_legacy_orders_is_clean(): void
    {
        $this->artisan('kpay:diagnose-legacy-orders')
            ->expectsOutputToContain('Aucune commande kpay_direct héritée')
            ->assertSuccessful();
    }
}
