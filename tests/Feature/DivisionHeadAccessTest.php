<?php

namespace Tests\Feature;

use App\Mail\ManagerCredentialsMail;
use App\Models\Category;
use App\Models\Division;
use App\Models\DivisionHead;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Un chef de rubrique ne voit que sa rubrique, et rien d'autre du back-office. */
class DivisionHeadAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Category $electronics;

    private Category $fashion;

    private Division $division;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['email' => 'admin@asso.com', 'role' => 'admin']);
        $this->electronics = Category::create(['name' => 'Électronique', 'slug' => 'elec']);
        $this->fashion = Category::create(['name' => 'Mode', 'slug' => 'mode']);
        $this->division = Division::create(['name' => 'Électronique', 'type' => Division::TYPE_CATEGORY]);
        $this->division->categories()->attach($this->electronics->id);
    }

    private function chief(?Division $division = null, float $rate = 20): User
    {
        $chief = User::factory()->create(['role' => 'division_head', 'roles' => ['division_head']]);
        DivisionHead::create(['division_id' => ($division ?? $this->division)->id, 'user_id' => $chief->id,
            'commission_rate' => $rate, 'started_at' => now()->subDay()]);

        return $chief;
    }

    private function product(Category $category, string $name): Product
    {
        $vendor = User::factory()->create(['role' => 'vendeur']);
        $shop = Shop::create(['user_id' => $vendor->id, 'name' => 'Boutique '.$name, 'slug' => 's-'.uniqid(), 'status' => 'active']);

        return Product::create(['shop_id' => $shop->id, 'user_id' => $vendor->id, 'category_id' => $category->id,
            'name' => $name, 'price' => 1000, 'stock' => 3, 'status' => 'active']);
    }

    private function order(array $products): Order
    {
        $client = User::where('phone', '+237699000111')->first()
            ?? User::factory()->create(['first_name' => 'Awa', 'last_name' => 'Ngono', 'phone' => '+237699000111']);
        $order = Order::create(['user_id' => $client->id, 'status' => 'delivered', 'subtotal' => 0, 'total' => 999999,
            'payment_status' => 'paid', 'delivery_address' => 'Rue secrète 12, Douala']);
        foreach ($products as $product) {
            $order->items()->create(['product_id' => $product->id, 'seller_id' => $product->user_id, 'quantity' => 1,
                'unit_price' => 1100, 'total_price' => 1100, 'seller_unit_price' => 1000, 'seller_total_price' => 1000]);
        }

        return $order;
    }

    public function test_admin_creates_a_division_and_appoints_its_chief(): void
    {
        Mail::fake();

        $this->actingAs($this->admin)->post('/admin/divisions', [
            'name' => 'Mode', 'type' => 'category', 'categories' => [$this->fashion->id], 'is_active' => 1,
        ])->assertRedirect();
        $division = Division::where('name', 'Mode')->firstOrFail();
        $this->assertSame([$this->fashion->id], $division->categoryIds());

        $this->actingAs($this->admin)->post("/admin/divisions/{$division->id}/heads", [
            'mode' => 'new', 'first_name' => 'Paul', 'last_name' => 'Ebo', 'email' => 'paul@example.com', 'commission_rate' => 15,
        ])->assertRedirect(route('admin.divisions.show', $division));

        $chief = User::where('email', 'paul@example.com')->firstOrFail();
        $this->assertSame('division_head', $chief->role);
        $this->assertSame([], $chief->admin_permissions);
        $this->assertEquals(15, (float) $division->currentHead()->first()->commission_rate);
        Mail::assertSent(ManagerCredentialsMail::class, fn ($m) => $m->hasTo('paul@example.com'));
    }

    public function test_a_category_belongs_to_only_one_division(): void
    {
        $this->actingAs($this->admin)->post('/admin/divisions', [
            'name' => 'Doublon', 'type' => 'category', 'categories' => [$this->electronics->id],
        ])->assertSessionHasErrors('categories.0');
    }

    public function test_rate_change_and_replacement_keep_history(): void
    {
        $chief = $this->chief(rate: 20);

        $this->actingAs($this->admin)->post("/admin/divisions/{$this->division->id}/heads/rate", ['commission_rate' => 25])->assertRedirect();
        $this->assertSame(2, DivisionHead::where('user_id', $chief->id)->count());
        $this->assertEquals(25, (float) $this->division->currentHead()->first()->commission_rate);
        $this->assertNotNull(DivisionHead::where('commission_rate', 20)->first()->ended_at);

        $this->actingAs($this->admin)->post("/admin/divisions/{$this->division->id}/heads/rate", ['commission_rate' => 150])
            ->assertSessionHasErrors('commission_rate');
    }

    public function test_chief_lands_on_his_space_and_cannot_open_any_other_section(): void
    {
        $chief = $this->chief();

        $this->actingAs($chief)->get('/admin/dashboard')->assertRedirect(route('admin.division.dashboard'));
        $this->actingAs($chief)->get('/admin/ma-rubrique')->assertOk()->assertSee('Électronique');

        foreach (['/admin/transactions', '/admin/products', '/admin/users', '/admin/divisions', '/admin/managers',
            '/admin/revenue', '/admin/asso-orders', '/admin/disputes', '/admin/settings'] as $url) {
            $this->actingAs($chief)->get($url)->assertForbidden();
        }
    }

    public function test_chief_sees_only_his_lines_of_a_mixed_order(): void
    {
        $chief = $this->chief();
        $phone = $this->product($this->electronics, 'Téléphone Galaxy');
        $shirt = $this->product($this->fashion, 'Chemise en lin');
        $mixed = $this->order([$phone, $shirt]);
        $fashionOnly = $this->order([$shirt]);

        $this->actingAs($chief)->get('/admin/ma-rubrique/commandes')->assertOk()
            ->assertSee($mixed->order_number)
            ->assertSee('Téléphone Galaxy')
            ->assertDontSee('Chemise en lin')
            ->assertDontSee($fashionOnly->order_number)
            ->assertDontSee('999 999');

        $this->actingAs($chief)->get("/admin/ma-rubrique/commandes/{$mixed->id}")->assertOk()
            ->assertSee('Téléphone Galaxy')
            ->assertDontSee('Chemise en lin')
            // Client réduit au prénom et à l'initiale : ni téléphone ni adresse.
            ->assertSee('Awa N.')
            ->assertDontSee('Ngono')
            ->assertDontSee('+237699000111')
            ->assertDontSee('Rue secrète');

        $this->actingAs($chief)->get("/admin/ma-rubrique/commandes/{$fashionOnly->id}")->assertNotFound();
    }

    public function test_chief_lists_only_products_of_his_division(): void
    {
        $chief = $this->chief();
        $this->product($this->electronics, 'Téléphone Galaxy');
        $this->product($this->fashion, 'Chemise en lin');

        $this->actingAs($chief)->get('/admin/ma-rubrique/produits')->assertOk()
            ->assertSee('Téléphone Galaxy')->assertDontSee('Chemise en lin');
    }

    public function test_chief_cannot_open_another_division_by_changing_the_address(): void
    {
        $chief = $this->chief();
        $other = Division::create(['name' => 'Mode', 'type' => Division::TYPE_CATEGORY]);
        $other->categories()->attach($this->fashion->id);
        $this->product($this->fashion, 'Chemise en lin');

        // Rubrique demandée hors de son périmètre : on reste sur la sienne.
        $this->actingAs($chief)->get("/admin/ma-rubrique/produits?division={$other->id}")->assertOk()
            ->assertDontSee('Chemise en lin');
    }

    public function test_ended_mandate_closes_the_division_but_keeps_earnings(): void
    {
        $chief = $this->chief();
        $this->actingAs($this->admin)->post("/admin/divisions/{$this->division->id}/heads/end")->assertRedirect();

        $this->actingAs($chief)->get('/admin/ma-rubrique')->assertRedirect(route('admin.division.earnings.index'));
        $this->actingAs($chief)->get('/admin/ma-rubrique/commandes')->assertNotFound();
        $this->actingAs($chief)->get('/admin/ma-rubrique/gains')->assertOk();
    }

    public function test_product_manager_cannot_reach_the_division_space(): void
    {
        $pm = User::factory()->create(['role' => 'product_manager', 'roles' => ['product_manager']]);

        $this->actingAs($pm)->get('/admin/ma-rubrique')->assertForbidden();
        $this->actingAs($pm)->get('/admin/ma-rubrique/gains')->assertForbidden();
    }

    public function test_withdrawal_requires_the_chiefs_password(): void
    {
        $chief = $this->chief();

        $this->actingAs($chief)->post('/admin/ma-rubrique/retrait/mobile', [
            'provider' => 'MTN_MOMO_CMR', 'phone' => '+237670000000', 'amount' => 500, 'country' => 'CMR-XAF',
        ])->assertSessionHasErrors('password');

        $this->actingAs($chief)->post('/admin/ma-rubrique/retrait/mobile', [
            'provider' => 'MTN_MOMO_CMR', 'phone' => '+237670000000', 'amount' => 500, 'country' => 'CMR-XAF', 'password' => 'mauvais',
        ])->assertSessionHasErrors('password');

        // L'admin n'utilise pas cette page (il a le retrait ASSO).
        $this->actingAs($this->admin)->get('/admin/ma-rubrique/retrait')->assertForbidden();
    }

    public function test_chief_menu_only_shows_his_space(): void
    {
        $response = $this->actingAs($this->chief())->get('/admin/ma-rubrique')->assertOk();

        $response->assertSee(route('admin.division.earnings.index'), false);
        $response->assertDontSee(route('admin.products.index'), false);
        $response->assertDontSee(route('admin.transactions.index'), false);
    }

    public function test_admin_can_preview_any_division_space(): void
    {
        $this->product($this->electronics, 'Téléphone Galaxy');

        $this->actingAs($this->admin)->get("/admin/ma-rubrique/produits?division={$this->division->id}")->assertOk()
            ->assertSee('Téléphone Galaxy')->assertSee('Aperçu administrateur');
    }

    public function test_every_page_of_the_space_and_of_the_admin_section_renders(): void
    {
        $chief = $this->chief();
        $phone = $this->product($this->electronics, 'Téléphone Galaxy');
        $order = $this->order([$phone]);
        $order->forceFill(['settled_at' => now()])->save();
        \App\Models\ShopAnalyticsEvent::create(['shop_id' => $phone->shop_id, 'product_id' => $phone->id,
            'event_type' => \App\Models\ShopAnalyticsEvent::PRODUCT_VIEW, 'visitor_hash' => 'v1', 'source' => 'search']);
        \App\Models\Dispute::create(['order_id' => $order->id, 'order_item_id' => $order->items->first()->id,
            'client_id' => $order->user_id, 'seller_id' => $phone->user_id, 'reason' => 'defective',
            'description' => 'Écran cassé', 'status' => \App\Models\Dispute::STATUS_NEW]);

        foreach (['/admin/ma-rubrique', '/admin/ma-rubrique?period=all', '/admin/ma-rubrique/commandes', "/admin/ma-rubrique/commandes/{$order->id}",
            '/admin/ma-rubrique/produits', '/admin/ma-rubrique/trafic', '/admin/ma-rubrique/litiges',
            '/admin/ma-rubrique/gains', '/admin/ma-rubrique/retrait'] as $url) {
            $this->actingAs($chief)->get($url)->assertOk();
        }
        $this->actingAs($chief)->get('/admin/ma-rubrique/litiges')->assertSee($order->order_number);
        $this->actingAs($chief)->get('/admin/ma-rubrique/trafic')->assertSee('Téléphone Galaxy');

        // Rubrique Immobilier.
        $immo = Division::create(['name' => 'Immobilier', 'type' => Division::TYPE_REAL_ESTATE]);
        $estateChief = $this->chief($immo);
        foreach (['/admin/ma-rubrique', '/admin/ma-rubrique/commandes', '/admin/ma-rubrique/produits',
            '/admin/ma-rubrique/trafic', '/admin/ma-rubrique/gains'] as $url) {
            $this->actingAs($estateChief)->get($url)->assertOk();
        }
        $this->actingAs($estateChief)->get('/admin/ma-rubrique/litiges')->assertNotFound();

        foreach (['/admin/divisions', '/admin/divisions/create', "/admin/divisions/{$this->division->id}",
            "/admin/divisions/{$this->division->id}/edit", "/admin/divisions/{$immo->id}"] as $url) {
            $this->actingAs($this->admin)->get($url)->assertOk();
        }
    }
}
