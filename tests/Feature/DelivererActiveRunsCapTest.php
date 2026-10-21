<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\DeliveryController;
use App\Models\Category;
use App\Models\DelivererCodeSync;
use App\Models\DelivererCompany;
use App\Models\DelivererSyncCode;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Un coursier transporte au plus MAX_ACTIVE_RUNS commandes à la fois.
 * Chaque livraison libère une place.
 */
class DelivererActiveRunsCapTest extends TestCase
{
    use RefreshDatabase;

    private User $deliverer;
    private DelivererCompany $company;
    private Product $product;
    private User $vendor;
    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = User::factory()->create(['role' => 'vendeur']);
        $this->client = User::factory()->create(['role' => 'client']);
        $this->deliverer = User::factory()->create(['role' => 'livreur']);

        $shop = Shop::create([
            'user_id' => $this->vendor->id,
            'name' => 'Boutique',
            'slug' => 'boutique',
            'status' => 'active',
        ]);
        $category = Category::create(['name' => 'Divers', 'slug' => 'divers']);
        $this->product = Product::create([
            'user_id' => $this->vendor->id,
            'shop_id' => $shop->id,
            'category_id' => $category->id,
            'name' => 'Article',
            'price' => 5000,
            'stock' => 100,
            'status' => 'active',
        ]);

        $this->company = DelivererCompany::create([
            'name' => 'Coursiers Test',
            'user_id' => $this->deliverer->id,
            'is_active' => true,
        ]);

        $syncCode = DelivererSyncCode::create([
            'sync_code' => 'TEST-0000-0002',
            'company_id' => $this->company->id,
            'user_id' => $this->deliverer->id,
            'is_used' => true,
        ]);

        DelivererCodeSync::create([
            'sync_code_id' => $syncCode->id,
            'user_id' => $this->deliverer->id,
            'company_id' => $this->company->id,
            'is_active' => true,
            'is_banned' => false,
            'synced_at' => now(),
        ]);
    }

    /** Commande validée par le vendeur, prête à être prise en charge. */
    private function confirmedOrder(): Order
    {
        $order = Order::create([
            'user_id' => $this->client->id,
            'status' => 'confirmed',
            'subtotal' => 5000,
            'total' => 6500,
            'delivery_fee' => 1500,
            'delivery_address' => 'Akwa, Douala',
            'customer_phone' => '+237600000000',
            'payment_method' => 'wallet_kpay',
            'payment_status' => 'paid',
            'delivery_company_id' => $this->company->id,
            'delivery_mode' => Order::DELIVERY_LOCAL,
            'confirmation_code' => '123456',
        ]);

        $order->items()->create([
            'product_id' => $this->product->id,
            'seller_id' => $this->vendor->id,
            'quantity' => 1,
            'unit_price' => 5000,
            'total_price' => 5000,
        ]);

        return $order;
    }

    public function test_manual_offline_hides_new_requests_until_courier_goes_online(): void
    {
        $order = $this->confirmedOrder();
        Sanctum::actingAs($this->deliverer);
        $this->getJson('/api/v1/delivery/pending')->assertJsonCount(1, 'requests');
        $this->putJson('/api/v1/delivery/availability', ['is_online' => false])->assertOk();
        $this->getJson('/api/v1/delivery/pending')->assertJsonCount(0, 'requests');
        $this->getJson('/api/v1/delivery/dashboard')->assertJsonCount(0, 'deliveries');
        $this->postJson("/api/v1/delivery/{$order->id}/accept")->assertUnprocessable();
        $this->putJson('/api/v1/delivery/availability', ['is_online' => true])->assertOk();
        $this->getJson('/api/v1/delivery/pending')->assertJsonCount(1, 'requests');
        $this->postJson("/api/v1/delivery/{$order->id}/accept")->assertOk();
    }

    public function test_deliverer_can_carry_several_runs_up_to_the_cap(): void
    {
        $cap = DeliveryController::MAX_ACTIVE_RUNS;
        $orders = collect(range(1, $cap))->map(fn () => $this->confirmedOrder());

        Sanctum::actingAs($this->deliverer);

        foreach ($orders as $order) {
            $this->postJson("/api/v1/delivery/{$order->id}/accept")->assertOk();
            $this->assertSame('shipped', $order->fresh()->status);
        }

        $this->assertSame(
            $cap,
            Order::where('delivery_person_id', $this->deliverer->id)
                ->where('status', 'shipped')
                ->count(),
        );
    }

    public function test_deliverer_cannot_exceed_the_cap(): void
    {
        $cap = DeliveryController::MAX_ACTIVE_RUNS;

        Sanctum::actingAs($this->deliverer);

        foreach (range(1, $cap) as $ignored) {
            $this->postJson("/api/v1/delivery/{$this->confirmedOrder()->id}/accept")->assertOk();
        }

        $extra = $this->confirmedOrder();

        $this->postJson("/api/v1/delivery/{$extra->id}/accept")
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonFragment([
                'message' => "Vous transportez déjà {$cap} commandes. Livrez-en une pour pouvoir en accepter une autre.",
            ]);

        $extra->refresh();
        $this->assertSame('confirmed', $extra->status);
        $this->assertNull($extra->delivery_person_id);
    }

    public function test_delivering_one_run_frees_a_slot(): void
    {
        $cap = DeliveryController::MAX_ACTIVE_RUNS;

        Sanctum::actingAs($this->deliverer);

        $accepted = collect(range(1, $cap))->map(function () {
            $order = $this->confirmedOrder();
            $this->postJson("/api/v1/delivery/{$order->id}/accept")->assertOk();

            return $order;
        });

        $extra = $this->confirmedOrder();
        $this->postJson("/api/v1/delivery/{$extra->id}/accept")->assertStatus(422);

        // Une course livrée libère une place.
        $this->postJson("/api/v1/delivery/{$accepted->first()->id}/complete", [
            'confirmation_code' => '123456',
        ])->assertOk();

        $this->postJson("/api/v1/delivery/{$extra->id}/accept")->assertOk();
        $this->assertSame('shipped', $extra->fresh()->status);

        $this->assertSame(
            $cap,
            Order::where('delivery_person_id', $this->deliverer->id)
                ->where('status', 'shipped')
                ->count(),
        );
    }
}
