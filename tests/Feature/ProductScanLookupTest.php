<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Subcategory;
use App\Models\User;
use App\Services\ProductLookupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fonctionnalité 3 : fiche produit pré-remplie depuis un scan ML Kit.
 * Le micro-service Asso-Lookup est simulé (Http::fake).
 */
class ProductScanLookupTest extends TestCase
{
    use RefreshDatabase;

    private const NUTELLA = '3017620422003';

    private Category $food;

    private Subcategory $grocery;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.product_lookup.url' => 'http://lookup.test',
            'services.product_lookup.token' => 'secret',
        ]);
        $this->food = Category::create(['name' => 'Alimentation & Boissons', 'slug' => 'alimentation-boissons']);
        $this->grocery = Subcategory::create([
            'category_id' => $this->food->id, 'name' => 'Épicerie', 'name_en' => 'Grocery', 'slug' => 'epicerie',
        ]);
    }

    private function scan(array $payload)
    {
        return $this->actingAs(User::factory()->create(['role' => 'vendeur']), 'sanctum')
            ->postJson('/api/v1/products/scan-lookup', $payload);
    }

    private function fakeService(array $body = [], int $status = 200): void
    {
        Http::fake(['http://lookup.test/lookup' => Http::response($body + [
            'source' => 'openfoodfacts',
            'barcode' => self::NUTELLA,
            'name' => 'Nutella 400 g',
            'brand' => 'Nutella',
            'description' => 'Nutella 400 g, prêt à rejoindre votre table.',
            'quantity_label' => '400 g',
            'weight_kg' => 0.4,
            'category_slug' => 'alimentation-boissons',
            'subcategory_slug' => 'epicerie',
            'characteristics' => [],
            'confidence' => ['name' => 0.9, 'brand' => 0.9, 'description' => 0.6, 'category' => 0.7, 'weight' => 0.85],
        ], $status)]);
    }

    public function test_connexion_obligatoire(): void
    {
        $this->postJson('/api/v1/products/scan-lookup', ['barcode' => self::NUTELLA])->assertUnauthorized();
    }

    public function test_il_faut_au_moins_un_element_scanne(): void
    {
        $this->scan([])->assertStatus(422)->assertJsonValidationErrors('barcode');
        $this->scan(['labels' => [['text' => 'Food', 'confidence' => 2]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('labels.0.confidence');
    }

    public function test_fiche_trouvee_et_slugs_traduits_en_identifiants(): void
    {
        $this->fakeService();

        $this->scan(['barcode' => self::NUTELLA, 'labels' => [['text' => 'Food', 'confidence' => 0.9]]])
            ->assertOk()
            ->assertJsonPath('data.source', 'openfoodfacts')
            ->assertJsonPath('data.suggested_data.name', 'Nutella 400 g')
            ->assertJsonPath('data.suggested_data.brand', 'Nutella')
            ->assertJsonPath('data.suggested_data.barcode', self::NUTELLA)
            ->assertJsonPath('data.suggested_data.weight_kg', 0.4)
            ->assertJsonPath('data.suggested_data.category_id', $this->food->id)
            ->assertJsonPath('data.suggested_data.subcategory_id', $this->grocery->id)
            ->assertJsonPath('data.confidence.name', 0.9);

        Http::assertSent(fn (HttpRequest $request) => $request->hasHeader('X-Internal-Token', 'secret')
            && $request['barcode'] === self::NUTELLA
            && $request['labels'][0]['text'] === 'Food');
    }

    public function test_slug_inconnu_sans_categorie(): void
    {
        $this->fakeService(['category_slug' => 'inexistante', 'subcategory_slug' => 'epicerie']);

        $this->scan(['barcode' => self::NUTELLA])
            ->assertOk()
            ->assertJsonPath('data.suggested_data.category_id', null)
            ->assertJsonPath('data.suggested_data.subcategory_id', null)
            ->assertJsonPath('data.confidence.category', 0);
    }

    public function test_reponse_d_une_base_gardee_en_cache(): void
    {
        $this->fakeService();

        $this->scan(['barcode' => self::NUTELLA])->assertOk();
        $this->scan(['barcode' => self::NUTELLA])->assertOk()->assertJsonPath('data.source', 'openfoodfacts');

        Http::assertSentCount(1);
    }

    public function test_deduction_par_regles_jamais_en_cache(): void
    {
        $this->fakeService(['source' => 'rules']);

        $this->scan(['barcode' => self::NUTELLA, 'ocr_text' => 'Nutella'])->assertOk();
        $this->scan(['barcode' => self::NUTELLA, 'ocr_text' => 'Nutella'])->assertOk();

        Http::assertSentCount(2);
    }

    public function test_code_barres_invalide_non_transmis(): void
    {
        $this->fakeService(['source' => 'rules', 'barcode' => null]);

        $this->scan(['barcode' => '3017620422004', 'ocr_text' => 'Nutella'])
            ->assertOk()
            ->assertJsonPath('data.suggested_data.barcode', null);

        Http::assertSent(fn (HttpRequest $request) => $request['barcode'] === null);
    }

    public function test_produit_asso_deja_publie_reutilise_sans_appel(): void
    {
        Http::fake();
        $seller = User::factory()->create(['role' => 'vendeur']);
        $shop = Shop::create([
            'user_id' => $seller->id, 'name' => 'Épicerie Mboa', 'slug' => 'epicerie-mboa',
            'status' => 'active', 'is_certified' => false,
        ]);
        Product::create([
            'shop_id' => $shop->id, 'user_id' => $seller->id, 'category_id' => $this->food->id,
            'subcategory_id' => $this->grocery->id, 'name' => 'Nutella pot 400 g', 'description' => 'Pâte à tartiner',
            'price' => 3500, 'type' => 'article', 'weight' => '0.45', 'status' => 'active',
            'barcode' => self::NUTELLA, 'brand' => 'Nutella',
        ]);

        $this->scan(['barcode' => self::NUTELLA])
            ->assertOk()
            ->assertJsonPath('data.source', 'asso')
            ->assertJsonPath('data.suggested_data.name', 'Nutella pot 400 g')
            ->assertJsonPath('data.suggested_data.weight_kg', 0.45)
            ->assertJsonPath('data.suggested_data.subcategory_id', $this->grocery->id);

        Http::assertNothingSent();
    }

    public function test_service_en_panne_reponse_vide_non_bloquante(): void
    {
        $this->fakeService([], 500);

        $this->scan(['barcode' => self::NUTELLA])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.source', 'none')
            ->assertJsonPath('data.suggested_data.name', null)
            ->assertJsonPath('data.suggested_data.barcode', self::NUTELLA);
    }

    public function test_service_non_configure(): void
    {
        config(['services.product_lookup.token' => '']);
        Http::fake();

        $this->scan(['ocr_text' => 'Nutella'])->assertOk()->assertJsonPath('data.source', 'none');
        Http::assertNothingSent();
    }

    public function test_cle_de_controle_des_codes_barres(): void
    {
        $this->assertSame(self::NUTELLA, ProductLookupService::normalizeBarcode(' 3017620422003 '));
        $this->assertNull(ProductLookupService::normalizeBarcode('3017620422004'));
        $this->assertSame('96385074', ProductLookupService::normalizeBarcode('96385074'));
        $this->assertSame('036000291452', ProductLookupService::normalizeBarcode('036000291452'));
        $this->assertNull(ProductLookupService::normalizeBarcode('12345'));
    }
}
