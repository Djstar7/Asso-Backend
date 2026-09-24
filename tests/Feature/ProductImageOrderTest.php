<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La fiche produit affiche `images[0]`, la carte `primary_image` : les deux
 * doivent désigner la même photo, sinon le client voit changer l'image du
 * produit qu'il vient de toucher.
 */
class ProductImageOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_l_image_principale_ouvre_toujours_la_galerie(): void
    {
        $vendor = User::factory()->create(['role' => 'vendeur']);
        $shop = Shop::create([
            'user_id' => $vendor->id,
            'name' => 'Boutique',
            'slug' => 'boutique-' . $vendor->id,
            'status' => 'active',
        ]);
        $category = Category::firstOrCreate(['slug' => 'test'], ['name' => 'Test', 'is_active' => true]);

        $product = Product::create([
            'user_id' => $vendor->id,
            'shop_id' => $shop->id,
            'category_id' => $category->id,
            'name' => 'Montre',
            'price' => 10000,
            'stock' => 3,
            'status' => 'active',
        ]);

        // Principale ajoutée APRÈS une autre, `order` laissé à 0 comme à l'upload.
        $product->images()->create(['image_path' => 'products/cote.jpg', 'is_primary' => false]);
        $product->images()->create(['image_path' => 'products/face.jpg', 'is_primary' => true]);
        $product->images()->create(['image_path' => 'products/dos.jpg', 'is_primary' => false]);

        $body = $this->getJson("/api/v1/products/{$product->id}")->assertOk()->json('product');

        $this->assertStringEndsWith('products/face.jpg', $body['primary_image']);
        $this->assertSame($body['primary_image'], $body['images'][0]['url']);
        $this->assertTrue($body['images'][0]['is_primary']);
        // Le reste garde l'ordre d'ajout.
        $this->assertStringEndsWith('products/cote.jpg', $body['images'][1]['url']);
    }
}
