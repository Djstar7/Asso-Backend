<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Models\WalletBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Supprimer un compte l'anonymise : données personnelles effacées, historique financier intact. */
class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function vendorWithSale(string $orderStatus = 'delivered', ?string $fundsStatus = Order::VENDOR_FUNDS_RELEASED): array
    {
        $vendor = User::factory()->create(['role' => 'vendeur', 'roles' => ['vendeur'], 'first_name' => 'Jean',
            'last_name' => 'Mballa', 'phone' => '+2376900'.random_int(10000, 99999), 'address' => 'Akwa, Douala']);
        $buyer = User::factory()->create();
        $shop = Shop::create(['user_id' => $vendor->id, 'name' => 'Boutique Jean', 'slug' => 'bj-'.uniqid(), 'status' => 'active']);
        $category = Category::create(['name' => 'Mode', 'slug' => 'mode-'.uniqid()]);
        $product = Product::create(['shop_id' => $shop->id, 'user_id' => $vendor->id, 'category_id' => $category->id,
            'name' => 'Chemise', 'price' => 1000, 'stock' => 5, 'status' => 'active']);
        $order = Order::create(['user_id' => $buyer->id, 'status' => $orderStatus, 'subtotal' => 1100, 'total' => 1100,
            'payment_status' => 'paid', 'vendor_funds_status' => $fundsStatus]);
        $item = $order->items()->create(['product_id' => $product->id, 'seller_id' => $vendor->id, 'quantity' => 1,
            'unit_price' => 1100, 'total_price' => 1100, 'seller_unit_price' => 1000, 'seller_total_price' => 1000]);

        return compact('vendor', 'buyer', 'shop', 'product', 'order', 'item');
    }

    public function test_deleting_an_account_anonymizes_it_and_keeps_the_sales_history(): void
    {
        ['vendor' => $vendor, 'order' => $order, 'item' => $item, 'product' => $product, 'shop' => $shop] = $this->vendorWithSale();
        WalletBalance::create(['user_id' => $vendor->id, 'currency' => 'XAF', 'balance' => 0, 'locked_balance' => 0]);
        $phone = $vendor->phone;
        $token = $vendor->createToken('mobile-app')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/auth/delete-account')->assertOk()->assertJsonPath('success', true);

        $vendor->refresh();
        $this->assertNotNull($vendor->anonymized_at);
        $this->assertSame('Compte', $vendor->first_name);
        $this->assertNull($vendor->phone);
        $this->assertNull($vendor->address);
        $this->assertStringEndsWith('@deleted.asso.invalid', $vendor->email);
        $this->assertSame(0, $vendor->tokens()->count());

        // Historique intact : la commande du client, sa ligne, le Wallet.
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
        $this->assertDatabaseHas('order_items', ['id' => $item->id, 'seller_id' => $vendor->id]);
        $this->assertDatabaseHas('wallet_balances', ['user_id' => $vendor->id]);
        // Plus rien en vente.
        $this->assertSame('inactive', $shop->fresh()->status);
        $this->assertSame('inactive', $product->fresh()->status);

        // L'ancien jeton ne marche plus, ni la connexion par l'ancien numéro.
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/profile')->assertUnauthorized();
        $this->assertNull(User::where('phone', $phone)->first());
    }

    public function test_money_on_the_wallet_blocks_the_deletion(): void
    {
        $user = User::factory()->create();
        WalletBalance::create(['user_id' => $user->id, 'currency' => 'XAF', 'balance' => 5000, 'locked_balance' => 0]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/delete-account')
            ->assertStatus(422)->assertJsonPath('blockers', ['wallet_balance']);
        $this->assertNull($user->fresh()->anonymized_at);
    }

    public function test_unfinished_sale_or_held_funds_block_the_vendor_deletion(): void
    {
        ['vendor' => $shipping] = $this->vendorWithSale('shipped', null);
        $this->actingAs($shipping, 'sanctum')->postJson('/api/v1/auth/delete-account')
            ->assertStatus(422)->assertJsonPath('blockers', ['seller_orders']);

        ['vendor' => $held, 'buyer' => $buyer] = $this->vendorWithSale('delivered', Order::VENDOR_FUNDS_HELD);
        $this->actingAs($held, 'sanctum')->postJson('/api/v1/auth/delete-account')->assertStatus(422);
        // Côté acheteur aussi : sa commande n'est pas soldée.
        $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/auth/delete-account')
            ->assertStatus(422)->assertJsonPath('blockers', ['buyer_orders']);
    }

    public function test_open_dispute_blocks_the_deletion(): void
    {
        ['vendor' => $vendor, 'buyer' => $buyer, 'order' => $order, 'item' => $item] = $this->vendorWithSale();
        Dispute::create(['order_id' => $order->id, 'order_item_id' => $item->id, 'client_id' => $buyer->id,
            'seller_id' => $vendor->id, 'reason' => 'defective', 'description' => 'Déchirée', 'status' => Dispute::STATUS_NEW]);

        $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/auth/delete-account')
            ->assertStatus(422)->assertJsonPath('blockers', ['open_disputes']);
    }

    public function test_admin_deletion_anonymizes_or_explains_why_not(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        ['vendor' => $vendor, 'order' => $order] = $this->vendorWithSale();

        $this->actingAs($admin)->delete(route('admin.users.destroy', $vendor))->assertRedirect(route('admin.users.index'));
        $this->assertNotNull($vendor->fresh()->anonymized_at);
        $this->assertDatabaseHas('orders', ['id' => $order->id]);

        $rich = User::factory()->create();
        WalletBalance::create(['user_id' => $rich->id, 'currency' => 'XAF', 'balance' => 0, 'locked_balance' => 700]);
        $this->actingAs($admin)->from(route('admin.users.index'))->delete(route('admin.users.destroy', $rich))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Wallet'));
        $this->assertNull($rich->fresh()->anonymized_at);
    }
}
