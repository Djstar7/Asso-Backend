<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Support\LocationFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShopLocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_addresses_are_summarized_as_city_and_country(): void
    {
        $this->assertSame('Douala, Cameroun', LocationFormatter::label(null, null, 'Rue 1, Douala, Cameroun'));
        $this->assertSame('Douala, Cameroun', LocationFormatter::label(null, null,
            'Marché Central de Douala, Douala IIe, Communauté Urbaine de Douala, Wouri, Région du Littoral, Cameroun'));
        $this->assertSame('Yaoundé, Cameroun', LocationFormatter::label(null, null, 'Marché Mokolo, Yaoundé'));
        $this->assertNull(LocationFormatter::label(null, null, 'Lat: 4.05, Lng: 9.70'));
        $this->assertSame('Kribi, Cameroun', LocationFormatter::label('Kribi', 'Cameroun', 'n’importe quoi'));
    }

    /**
     * Déplace une boutique déjà placée par le seul chemin possible : demande du
     * vendeur, puis validation par l'admin.
     */
    private function moveShop(Shop $shop, User $vendor, array $location): void
    {
        $requestId = $this->actingAs($vendor, 'sanctum')
            ->postJson('/api/v1/vendor/shop/location-requests', $location)
            ->assertCreated()
            ->json('request.id');

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)
            ->post("/admin/shops/{$shop->id}/location-requests/{$requestId}/approve")
            ->assertRedirect();
    }

    public function test_vendor_places_shop_once_then_products_show_city_country(): void
    {
        $vendor = User::factory()->create(['role' => 'vendeur']);
        $shop = Shop::create([
            'user_id' => $vendor->id,
            'name' => 'Boutique',
            'slug' => 'boutique',
            'status' => 'active',
            'address' => 'Bonapriso',
        ]);
        $category = Category::create(['name' => 'Divers', 'slug' => 'divers']);
        $product = Product::create([
            'user_id' => $vendor->id, 'shop_id' => $shop->id, 'category_id' => $category->id,
            'name' => 'Sac', 'price' => 5000, 'stock' => 2, 'status' => 'active',
        ]);
        Sanctum::actingAs($vendor);

        $this->postJson('/api/v1/vendor/shop', [
            '_method' => 'PUT',
            'shop_address' => 'Rue Njo-Njo, Douala, Cameroun',
            'shop_city' => 'Douala',
            'shop_country' => 'Cameroun',
            'shop_latitude' => 4.03,
            'shop_longitude' => 9.69,
        ])->assertOk()
            ->assertJsonPath('shop.location_label', 'Douala, Cameroun');

        $this->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('product.location', 'Douala, Cameroun');

        // Boutique placée : l'adresse ne change plus directement, même depuis une
        // ancienne version de l'app qui n'envoie que l'adresse.
        $this->postJson('/api/v1/vendor/shop', [
            '_method' => 'PUT',
            'shop_address' => 'Carrefour Warda, Yaoundé, Cameroun',
        ])->assertStatus(422)
            ->assertJsonPath('code', 'location_change_requires_approval');
        $this->assertSame('Douala', $shop->fresh()->city);
    }

    public function test_products_are_placed_where_their_shop_moved(): void
    {
        $vendor = User::factory()->create(['role' => 'vendeur']);
        $shop = Shop::create([
            'user_id' => $vendor->id,
            'name' => 'Boutique',
            'slug' => 'boutique',
            'status' => 'active',
            'address' => 'Bastos, Yaoundé, Cameroun',
            'latitude' => 3.89,
            'longitude' => 11.51,
            'verified_at' => now(),
        ]);
        $category = Category::create(['name' => 'Divers', 'slug' => 'divers']);
        // Coordonnées propres posées par un seeder, jamais mises à jour depuis.
        $product = Product::create([
            'user_id' => $vendor->id, 'shop_id' => $shop->id, 'category_id' => $category->id,
            'name' => 'Sac', 'price' => 5000, 'stock' => 2, 'status' => 'active',
            'latitude' => 3.8480, 'longitude' => 11.5021,
        ]);
        $this->moveShop($shop, $vendor, [
            'address' => 'Rue Njo-Njo, Douala, Cameroun',
            'city' => 'Douala',
            'country' => 'Cameroun',
            'latitude' => 4.03,
            'longitude' => 9.69,
        ]);

        $this->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('product.latitude', 4.03)
            ->assertJsonPath('product.longitude', 9.69)
            ->assertJsonPath('product.shop.address', 'Rue Njo-Njo, Douala, Cameroun');

        $this->getJson("/api/v1/shops/{$shop->id}")
            ->assertOk()
            ->assertJsonPath('shop.products.0.latitude', 4.03)
            ->assertJsonPath('shop.products.0.longitude', 9.69);
    }

    public function test_moving_to_a_city_without_delivery_grid_drops_the_old_quarter(): void
    {
        $vendor = User::factory()->create(['role' => 'vendeur']);
        $shop = Shop::create([
            'user_id' => $vendor->id,
            'name' => 'Boutique',
            'slug' => 'boutique',
            'status' => 'active',
            'address' => 'Rue des Palmiers, Akwa, Douala',
            'city' => 'Douala',
            'country' => 'Cameroun',
            'latitude' => 4.0483,
            'longitude' => 9.7043,
        ]);
        // Quartier posé par la grille de livraison de Douala.
        $shop->forceFill(['quarter' => 'Essengué'])->saveQuietly();
        $category = Category::create(['name' => 'Divers', 'slug' => 'divers']);
        $product = Product::create([
            'user_id' => $vendor->id, 'shop_id' => $shop->id, 'category_id' => $category->id,
            'name' => 'Sac', 'price' => 5000, 'stock' => 2, 'status' => 'active',
        ]);
        $this->moveShop($shop, $vendor, [
            'address' => 'Bafoussam, Cameroun',
            'city' => 'Bafoussam',
            'country' => 'Cameroun',
            'latitude' => 5.4781,
            'longitude' => 10.4176,
        ]);
        $this->assertSame('Bafoussam, Cameroun', $shop->fresh()->location_label);

        $this->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('product.location', 'Bafoussam, Cameroun');
    }

    public function test_placed_shop_keeps_its_location_while_other_fields_save(): void
    {
        $vendor = User::factory()->create(['role' => 'vendeur']);
        $shop = Shop::create([
            'user_id' => $vendor->id, 'name' => 'Boutique', 'slug' => 'boutique', 'status' => 'active',
            'address' => 'Rue Njo-Njo, Douala, Cameroun', 'city' => 'Douala', 'country' => 'Cameroun',
            'latitude' => 4.03, 'longitude' => 9.69,
        ]);
        Sanctum::actingAs($vendor);

        // Le formulaire renvoie l'emplacement inchangé avec le reste : accepté.
        $this->postJson('/api/v1/vendor/shop', [
            '_method' => 'PUT',
            'shop_name' => 'Boutique Njo-Njo',
            'shop_address' => 'Rue Njo-Njo, Douala, Cameroun',
            'shop_latitude' => 4.03,
            'shop_longitude' => 9.69,
        ])->assertOk()->assertJsonPath('shop.name', 'Boutique Njo-Njo');

        // Le repère déplacé : refusé, la boutique ne bouge pas.
        $this->postJson('/api/v1/vendor/shop', [
            '_method' => 'PUT',
            'shop_name' => 'Autre nom',
            'shop_latitude' => 4.06,
            'shop_longitude' => 9.72,
        ])->assertStatus(422)->assertJsonPath('code', 'location_change_requires_approval');

        $shop->refresh();
        $this->assertSame('Boutique Njo-Njo', $shop->name);
        $this->assertEquals(4.03, (float) $shop->latitude);
    }

    public function test_location_request_is_replaced_while_pending_and_admin_can_reject_it(): void
    {
        $vendor = User::factory()->create(['role' => 'vendeur']);
        $shop = Shop::create([
            'user_id' => $vendor->id, 'name' => 'Boutique', 'slug' => 'boutique', 'status' => 'active',
            'address' => 'Rue Njo-Njo, Douala, Cameroun', 'latitude' => 4.03, 'longitude' => 9.69,
        ]);
        Sanctum::actingAs($vendor);

        $move = ['address' => 'Akwa, Douala, Cameroun', 'latitude' => 4.05, 'longitude' => 9.70, 'reason' => 'Nouveau local'];
        $this->postJson('/api/v1/vendor/shop/location-requests', $move)->assertCreated();
        $this->postJson('/api/v1/vendor/shop/location-requests', ['latitude' => 4.06] + $move)->assertCreated();

        // Une seule demande en attente : la seconde a remplacé la première.
        $this->getJson('/api/v1/vendor/shop/location-requests')
            ->assertOk()
            ->assertJsonPath('pending_count', 1)
            ->assertJsonPath('requests.0.reason', 'Nouveau local');

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/admin/shops?location_request=pending')->assertOk()->assertSee('Boutique');
        $this->actingAs($admin)->get("/admin/shops/{$shop->id}")->assertOk()->assertSee('Akwa, Douala, Cameroun')->assertSee('Nouveau local');

        $requestId = $shop->locationRequests()->value('id');
        $this->actingAs($admin)
            ->post("/admin/shops/{$shop->id}/location-requests/{$requestId}/reject", ['rejection_reason' => 'Adresse introuvable'])
            ->assertRedirect();

        $this->actingAs($vendor, 'sanctum')->getJson('/api/v1/vendor/shop/location-requests')
            ->assertJsonPath('pending_count', 0)
            ->assertJsonPath('requests.0.status', 'rejected')
            ->assertJsonPath('requests.0.rejection_reason', 'Adresse introuvable');
        $this->assertEquals(4.03, (float) $shop->fresh()->latitude);
    }

    public function test_vendor_can_clear_description_and_categories(): void
    {
        $vendor = User::factory()->create(['role' => 'vendeur']);
        Shop::create([
            'user_id' => $vendor->id,
            'name' => 'Boutique',
            'slug' => 'boutique',
            'status' => 'active',
            'address' => 'Akwa, Douala, Cameroun',
            'description' => 'Ancienne description',
            'categories' => ['Électronique', 'Mode & Vêtements'],
        ]);
        Sanctum::actingAs($vendor);

        // Champs vides envoyés en multipart, comme le fait l'app.
        $this->post('/api/v1/vendor/shop', [
            '_method' => 'PUT',
            'shop_name' => 'Boutique',
            'shop_description' => '',
            'categories' => '',
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('shop.description', null)
            ->assertJsonPath('shop.categories', []);

        // Champs absents (ancienne version de l'app) : rien n'est effacé.
        $this->postJson('/api/v1/vendor/shop', [
            '_method' => 'PUT',
            'shop_description' => 'Nouvelle description',
            'categories' => ['Électronique'],
        ])->assertOk();
        $this->postJson('/api/v1/vendor/shop', [
            '_method' => 'PUT',
            'shop_name' => 'Boutique renommée',
        ])->assertOk()
            ->assertJsonPath('shop.description', 'Nouvelle description')
            ->assertJsonPath('shop.categories', ['Électronique']);
    }
}
