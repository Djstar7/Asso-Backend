<?php

namespace Tests\Feature;

use App\Mail\ManagerCredentialsMail;
use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProductManagerAccessTest extends TestCase
{
    use RefreshDatabase;

    private function productManager(array $extra = []): User
    {
        return User::factory()->create([
            'role' => 'product_manager',
            'roles' => ['product_manager'],
            'admin_permissions' => $extra,
        ]);
    }

    private function product(string $status = 'active'): Product
    {
        $vendor = User::factory()->create(['role' => 'vendeur']);
        $shop = Shop::create(['user_id' => $vendor->id, 'name' => 'Boutique', 'slug' => 'boutique-'.uniqid(), 'status' => 'active']);
        $category = Category::create(['name' => 'Mode', 'slug' => 'mode-'.uniqid()]);

        return Product::create([
            'shop_id' => $shop->id,
            'user_id' => $vendor->id,
            'category_id' => $category->id,
            'name' => 'Sac',
            'slug' => 'sac-'.uniqid(),
            'price' => 1000,
            'stock' => 3,
            'status' => $status,
        ]);
    }

    public function test_admin_creates_manager_and_credentials_are_emailed(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post('/admin/managers', [
            'first_name' => 'Awa',
            'last_name' => 'Ngo',
            'email' => 'awa@example.com',
            // Le formulaire n'a plus de champ rôle : une valeur envoyée est ignorée.
            'role' => 'admin',
            'permissions' => ['products.view', 'shops'],
        ])->assertRedirect(route('admin.managers.index'));

        $manager = User::where('email', 'awa@example.com')->firstOrFail();
        $this->assertSame('product_manager', $manager->role);
        $this->assertSame(['product_manager'], $manager->roles);
        // Seule la permission en plus du rôle est stockée.
        $this->assertSame(['shops'], $manager->admin_permissions);
        $this->assertSame($admin->id, $manager->created_by_admin_id);

        $sentPassword = null;
        Mail::assertSent(ManagerCredentialsMail::class, function (ManagerCredentialsMail $mail) use ($manager, &$sentPassword) {
            $sentPassword = $mail->plainPassword;

            return $mail->hasTo('awa@example.com') && $mail->manager->is($manager) && ! $mail->isReset;
        });
        $this->assertTrue(Hash::check($sentPassword, $manager->password));
    }

    public function test_manager_cannot_be_created_with_an_existing_email(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->create(['email' => 'client@example.com', 'role' => 'client']);

        $this->actingAs($admin)->post('/admin/managers', [
            'first_name' => 'A', 'last_name' => 'B', 'email' => 'client@example.com',
        ])->assertSessionHasErrors('email');

        Mail::assertNothingSent();
    }

    public function test_manager_is_not_created_when_the_email_fails(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP down'));
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post('/admin/managers', [
            'first_name' => 'A', 'last_name' => 'B', 'email' => 'x@example.com',
        ])->assertSessionHas('error');

        $this->assertDatabaseMissing('users', ['email' => 'x@example.com']);
    }

    public function test_product_manager_logs_in_and_lands_on_products(): void
    {
        User::factory()->create([
            'email' => 'pm@example.com',
            'password' => 'secret-pass',
            'role' => 'product_manager',
            'roles' => ['product_manager'],
        ]);

        $this->post('/admin/gestionnaire/login', ['email' => 'pm@example.com', 'password' => 'secret-pass'])
            ->assertRedirect(route('admin.products.index'));
    }

    public function test_manager_login_page_is_personalised(): void
    {
        $this->get('/admin/gestionnaire/login')
            ->assertOk()
            ->assertSee('Espace gestionnaire')
            ->assertSee(route('admin.manager.login.submit'), false)
            ->assertDontSee('admin@asso.com');
    }

    public function test_product_manager_on_admin_page_is_sent_to_manager_page(): void
    {
        User::factory()->create([
            'email' => 'pm@example.com',
            'password' => 'secret-pass',
            'role' => 'product_manager',
            'roles' => ['product_manager'],
        ]);

        $this->post('/admin/login', ['email' => 'pm@example.com', 'password' => 'secret-pass'])
            ->assertRedirect(route('admin.manager.login'))
            ->assertSessionHas('portal_notice');
        $this->assertGuest();
    }

    public function test_admin_on_manager_page_is_sent_to_admin_page(): void
    {
        User::factory()->create(['email' => 'root@example.com', 'password' => 'secret-pass', 'role' => 'admin']);

        $this->post('/admin/gestionnaire/login', ['email' => 'root@example.com', 'password' => 'secret-pass'])
            ->assertRedirect(route('admin.login'))
            ->assertSessionHas('portal_notice');
        $this->assertGuest();
    }

    public function test_manager_logout_returns_to_manager_page(): void
    {
        $this->actingAs($this->productManager())->post(route('admin.logout'))
            ->assertRedirect(route('admin.manager.login'));
    }

    public function test_app_user_cannot_log_in_to_backoffice(): void
    {
        User::factory()->create(['email' => 'v@example.com', 'password' => 'secret-pass', 'role' => 'vendeur']);

        $this->post('/admin/login', ['email' => 'v@example.com', 'password' => 'secret-pass'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_app_user_with_session_is_rejected_from_admin_routes(): void
    {
        $vendor = User::factory()->create(['role' => 'vendeur']);

        $this->actingAs($vendor)->get('/admin/products')->assertRedirect(route('admin.login'));
    }

    public function test_product_manager_only_reaches_the_products_section(): void
    {
        $pm = $this->productManager();
        $product = $this->product();

        $this->actingAs($pm)->get('/admin/products')->assertOk();
        $this->actingAs($pm)->get("/admin/products/{$product->id}")->assertOk();
        $this->actingAs($pm)->get('/admin/products/create')->assertOk();
        $this->actingAs($pm)->get("/admin/products/{$product->id}/edit")->assertOk();

        $this->actingAs($pm)->get('/admin/dashboard')->assertRedirect(route('admin.products.index'));
        foreach (['/admin/users', '/admin/shops', '/admin/transactions', '/admin/settings', '/admin/database', '/admin/managers', '/admin/vault'] as $url) {
            $this->actingAs($pm)->get($url)->assertForbidden();
        }
    }

    public function test_product_manager_sidebar_only_shows_products(): void
    {
        $response = $this->actingAs($this->productManager())->get('/admin/products')->assertOk();

        $response->assertSee(route('admin.products.index'), false);
        $response->assertDontSee(route('admin.users.index'), false);
        $response->assertDontSee(route('admin.managers.index'), false);
        $response->assertDontSee(route('admin.settings.index'), false);
        $response->assertSee('Gestionnaire produits');
    }

    public function test_product_manager_can_hide_show_and_delete_products(): void
    {
        $pm = $this->productManager();
        $product = $this->product();

        $this->actingAs($pm)->patch("/admin/products/{$product->id}/toggle-status")->assertRedirect();
        $this->assertSame('inactive', $product->fresh()->status);

        $this->actingAs($pm)->patch("/admin/products/{$product->id}/toggle-status")->assertRedirect();
        $this->assertSame('active', $product->fresh()->status);

        $this->actingAs($pm)->delete("/admin/products/{$product->id}")->assertRedirect(route('admin.products.index'));
        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }

    public function test_admin_can_extend_manager_permissions(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $pm = $this->productManager();

        $this->actingAs($pm)->get('/admin/shops')->assertForbidden();

        $this->actingAs($admin)->put("/admin/managers/{$pm->id}", [
            'first_name' => $pm->first_name,
            'last_name' => $pm->last_name,
            'email' => $pm->email,
            'permissions' => ['shops'],
        ])->assertRedirect(route('admin.managers.index'));

        $this->actingAs($pm->fresh())->get('/admin/shops')->assertOk();
    }

    public function test_admin_only_permissions_cannot_be_granted(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $pm = $this->productManager();

        $this->actingAs($admin)->put("/admin/managers/{$pm->id}", [
            'first_name' => 'A', 'last_name' => 'B', 'email' => $pm->email,
            'permissions' => ['database'],
        ])->assertSessionHasErrors('permissions.0');
    }

    public function test_manager_with_users_section_cannot_promote_to_admin(): void
    {
        $pm = $this->productManager(['users']);
        $client = User::factory()->create(['role' => 'client']);

        $this->actingAs($pm)->put("/admin/users/{$client->id}", [
            'first_name' => 'A', 'last_name' => 'B', 'email' => $client->email, 'role' => 'admin',
        ])->assertSessionHasErrors('role');
        $this->assertSame('client', $client->fresh()->role);

        // Ni modifier un compte du back-office depuis la section Utilisateurs.
        $this->actingAs($pm)->get("/admin/users/{$pm->id}/edit")->assertForbidden();
    }

    public function test_resend_credentials_resets_password(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $pm = $this->productManager();
        $oldHash = $pm->password;

        $this->actingAs($admin)->post("/admin/managers/{$pm->id}/resend-credentials")->assertRedirect();

        $this->assertNotSame($oldHash, $pm->fresh()->password);
        Mail::assertSent(ManagerCredentialsMail::class, fn ($mail) => $mail->isReset && $mail->hasTo($pm->email));
    }

    public function test_managers_section_does_not_touch_admins_or_app_users(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $client = User::factory()->create(['role' => 'client']);

        $this->actingAs($admin)->delete("/admin/managers/{$client->id}")->assertNotFound();
        $this->actingAs($admin)->delete("/admin/managers/{$admin->id}")->assertNotFound();
        $this->assertDatabaseHas('users', ['id' => $client->id]);
    }

    public function test_admin_sees_the_managers_pages(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $pm = $this->productManager(['shops']);

        $this->actingAs($admin)->get('/admin/managers')->assertOk()
            ->assertSee($pm->email)->assertSee('Boutiques');
        $this->actingAs($admin)->get('/admin/managers/create')->assertOk()
            ->assertSee('Masquer / afficher des produits')->assertDontSee('value="database"', false);
        $this->actingAs($admin)->get("/admin/managers/{$pm->id}/edit")->assertOk();
        $this->actingAs($admin)->get('/admin/dashboard')->assertOk()->assertSee(route('admin.managers.index'), false);
    }
}
