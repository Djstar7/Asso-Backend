<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Package;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Models\VendorPackage;
use App\Services\FcmService;
use App\Services\FirebaseMessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Produits saisis hors ligne : l'application renvoie une fiche tant qu'elle
 * n'a pas eu de réponse. Avec la même référence, le renvoi rend le produit
 * déjà créé au lieu d'en créer un second.
 */
class ProductClientReferenceTest extends TestCase
{
    use RefreshDatabase;

    private int $broadcasts = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->mock(FirebaseMessagingService::class, function ($mock) {
            $mock->shouldReceive('sendToUser')->andReturn([]);
            // Une annonce = un envoi groupé sur les topics de chaque langue.
            $mock->shouldReceive('sendToTopicsLocalized')->andReturnUsing(function () {
                $this->broadcasts++;

                return ['success' => true];
            });
        });
        $this->mock(FcmService::class, function ($mock) {
            $mock->shouldIgnoreMissing();
        });
    }

    private function vendor(): User
    {
        $vendor = User::factory()->create(['role' => 'vendeur']);
        Shop::create([
            'user_id' => $vendor->id,
            'name' => 'Boutique '.$vendor->id,
            'slug' => 'boutique-'.$vendor->id,
            'status' => 'active',
            'is_certified' => false,
        ]);
        VendorPackage::create([
            'user_id' => $vendor->id,
            'package_id' => Package::create([
                'type' => 'storage',
                'name' => 'Stockage',
                'price' => 1000,
                'duration_days' => 30,
                'storage_size_mb' => 100,
                'is_active' => true,
            ])->id,
            'storage_total_mb' => 100,
            'storage_used_mb' => 0,
            'storage_remaining_mb' => 100,
            'purchased_at' => now(),
            'expires_at' => now()->addDays(30),
            'status' => 'active',
        ]);

        return $vendor;
    }

    private function publish(User $vendor, ?string $reference, array $overrides = [])
    {
        $payload = $overrides + [
            'name' => 'Pagne wax',
            'description' => 'Six yards',
            'price' => 15000,
            'category_id' => Category::firstOrCreate(['slug' => 'test'], ['name' => 'Test', 'is_active' => true])->id,
            'type' => 'article',
            'condition' => 'new',
            'weight' => 1,
            'images' => [UploadedFile::fake()->image('pagne.jpg')->size(200)],
        ];
        if ($reference !== null) {
            $payload['client_reference'] = $reference;
        }

        return $this->actingAs($vendor, 'sanctum')
            ->post('/api/v1/products', $payload, ['Accept' => 'application/json']);
    }

    public function test_un_renvoi_de_la_meme_reference_rend_le_produit_deja_cree(): void
    {
        $vendor = $this->vendor();

        $first = $this->publish($vendor, 'offline-abc')->assertCreated();
        $replay = $this->publish($vendor, 'offline-abc')
            ->assertOk()
            ->assertJson(['success' => true, 'replayed' => true]);

        $this->assertSame($first->json('product.id'), $replay->json('product.id'));
        $this->assertSame(1, Product::count());
        $this->assertSame(1, Product::first()->images()->count());
        // Ni second débit du forfait, ni seconde annonce.
        $this->assertEqualsWithDelta(
            100 - 200 / 1024,
            (float) VendorPackage::first()->storage_remaining_mb,
            0.01,
        );
        $this->assertSame(1, $this->broadcasts);
    }

    public function test_la_reference_est_propre_a_chaque_vendeur(): void
    {
        $this->publish($this->vendor(), 'offline-abc')->assertCreated();
        $this->publish($this->vendor(), 'offline-abc')->assertCreated();

        $this->assertSame(2, Product::count());
    }

    public function test_sans_reference_chaque_envoi_cree_un_produit(): void
    {
        $vendor = $this->vendor();

        $this->publish($vendor, null)->assertCreated();
        $this->publish($vendor, null)->assertCreated();

        $this->assertSame(2, Product::count());
    }

    public function test_une_reference_trop_longue_est_refusee(): void
    {
        $this->publish($this->vendor(), str_repeat('x', 65))
            ->assertStatus(422)
            ->assertJsonValidationErrors('client_reference');
    }

    public function test_une_categorie_non_numerique_est_refusee_sans_erreur_sql(): void
    {
        // Anciennes fiches hors ligne : le nom de la catégorie à la place de
        // son identifiant. PostgreSQL rejetait la requête (500) et la fiche
        // restait bloquée en file.
        $this->publish($this->vendor(), 'offline-cat', [
            'category_id' => 'Électronique',
            'subcategory_id' => 'Ordinateurs',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category_id', 'subcategory_id']);

        $this->assertSame(0, Product::count());
    }
}
