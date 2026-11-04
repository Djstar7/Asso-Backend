<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductPriceTier;
use App\Models\Setting;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicShareTest extends TestCase
{
    use RefreshDatabase;

    private const FINGERPRINT = 'AA:BB:CC:DD:EE:FF:00:11:22:33:44:55:66:77:88:99:AA:BB:CC:DD:EE:FF:00:11:22:33:44:55:66:77:88:99';

    private const ANDROID = 'Mozilla/5.0 (Linux; Android 13)';

    private const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)';

    private function shop(array $attributes = []): Shop
    {
        return Shop::create($attributes + [
            'user_id' => User::factory()->create()->id,
            'name' => 'Boutique Marie',
            'description' => 'Vente de pagnes',
            'phone' => '+237699000000',
            'email' => 'vendeuse@example.test',
            'status' => 'active',
            'verified_at' => now(),
        ]);
    }

    private function product(?Shop $shop = null, array $attributes = []): Product
    {
        $shop ??= $this->shop();

        return Product::create($attributes + [
            'user_id' => $shop->user_id,
            'shop_id' => $shop->id,
            'category_id' => Category::create(['name' => 'Cat '.uniqid(), 'slug' => uniqid('cat-')])->id,
            'name' => 'Robe rouge',
            'description' => 'Une belle robe',
            'price' => 10000,
            'stock' => 5,
            'status' => 'active',
        ]);
    }

    public function test_la_page_produit_porte_l_apercu_social_et_le_prix(): void
    {
        $product = $this->product();
        ProductImage::create(['product_id' => $product->id, 'image_path' => 'products/robe.jpg', 'is_primary' => true]);

        $this->get("/p/{$product->id}")
            ->assertOk()
            ->assertSee('Robe rouge')
            ->assertSee('<meta property="og:title"', false)
            ->assertSee('<meta property="og:image" content="'.asset('storage/products/robe.jpg').'"', false)
            ->assertSee('Boutique Marie');
    }

    public function test_le_telephone_et_l_e_mail_du_vendeur_ne_sortent_pas(): void
    {
        $shop = $this->shop();
        $product = $this->product($shop);

        foreach (["/p/{$product->id}", "/b/{$shop->id}"] as $url) {
            $this->get($url)->assertOk()
                ->assertDontSee('+237699000000')
                ->assertDontSee('vendeuse@example.test');
        }
    }

    public function test_les_boutons_suivent_la_plateforme(): void
    {
        $product = $this->product();
        $host = parse_url(config('app.url'), PHP_URL_HOST) ?: 'localhost';

        $this->withHeader('User-Agent', self::ANDROID)->get("/p/{$product->id}")
            ->assertSee('intent://', false)
            ->assertSee('package=com.asso.asso', false)
            ->assertSee('S.browser_fallback_url=', false);

        $this->withHeader('User-Agent', self::IPHONE)->get("/p/{$product->id}")
            ->assertSee('assomarket://', false)
            ->assertSee('app-id=6763892792', false);

        $this->withHeader('User-Agent', 'Mozilla/5.0 (X11; Linux x86_64)')->get("/p/{$product->id}")
            ->assertDontSee('intent://', false)
            ->assertDontSee('assomarket://', false)
            ->assertSee('apps.apple.com/app/id6763892792', false);
    }

    public function test_un_contenu_retire_repond_404_en_gardant_la_page(): void
    {
        $inactive = $this->product(null, ['status' => 'inactive']);
        $hiddenShop = $this->shop(['verified_at' => null]);
        $inHiddenShop = $this->product($hiddenShop);
        $importedRetail = $this->product(null, ['origin_country' => 'CN', 'is_wholesale' => false]);

        foreach (["/p/{$inactive->id}", "/p/{$inHiddenShop->id}", "/p/{$importedRetail->id}", "/b/{$hiddenShop->id}", '/p/999999'] as $url) {
            $this->get($url)->assertNotFound()
                ->assertSee('Contenu indisponible')
                ->assertSee('noindex', false)
                ->assertDontSee('og:title', false);
        }
    }

    public function test_un_produit_en_gros_affiche_son_plus_petit_palier(): void
    {
        $product = $this->product(null, ['is_wholesale' => true, 'origin_country' => 'CN']);
        ProductPriceTier::create(['product_id' => $product->id, 'label' => 'Pack 10', 'min_quantity' => 10, 'unit_price' => 5000, 'currency' => 'XAF', 'is_active' => true]);
        ProductPriceTier::create(['product_id' => $product->id, 'label' => 'Pack 100', 'min_quantity' => 100, 'unit_price' => 3000, 'currency' => 'XAF', 'is_active' => true]);

        $this->get("/p/{$product->id}")->assertOk()->assertSee('3 000')->assertDontSee('5 000');
    }

    public function test_la_page_boutique_liste_seulement_les_produits_ouvrables(): void
    {
        $shop = $this->shop();
        $visible = $this->product($shop, ['name' => 'Sac visible']);
        $this->product($shop, ['name' => 'Sac masque', 'status' => 'inactive']);

        $this->get("/b/{$shop->id}")->assertOk()
            ->assertSee('Boutique Marie')
            ->assertSee('Sac visible')
            ->assertDontSee('Sac masque')
            ->assertSee(route('share.product', $visible->id), false);
    }

    public function test_la_langue_suit_le_navigateur_et_le_parametre(): void
    {
        $product = $this->product();

        $this->withHeader('Accept-Language', 'en-US')->get("/p/{$product->id}")->assertSee('Download on the App Store');
        $this->withHeader('Accept-Language', 'en-US')->get("/p/{$product->id}?lang=fr")->assertSee('Télécharger sur l');
    }

    public function test_une_page_ne_compte_pas_comme_une_visite_de_boutique(): void
    {
        $product = $this->product();

        $this->get("/p/{$product->id}")->assertOk();

        $this->assertDatabaseCount('shop_analytics_events', 0);
    }

    public function test_une_page_ne_cree_pas_de_session(): void
    {
        $product = $this->product();

        $this->get("/p/{$product->id}")->assertOk()->assertCookieMissing(config('session.cookie'));
    }

    public function test_l_ancienne_adresse_renvoie_vers_la_vitrine(): void
    {
        $this->get('/produit/42')->assertStatus(301)->assertRedirect('/p/42');
    }

    public function test_les_fichiers_d_association_restent_vides_tant_que_c_est_coupe(): void
    {
        $this->getJson('/.well-known/assetlinks.json')->assertOk()->assertExactJson([]);
        $this->getJson('/.well-known/apple-app-site-association')->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['applinks' => ['apps' => [], 'details' => []]]);
    }

    public function test_les_fichiers_d_association_ne_reclament_que_les_nouvelles_adresses(): void
    {
        Setting::set('app_links_association_enabled', true, 'boolean', 'links');
        Setting::set('android_sha256_fingerprints', self::FINGERPRINT, 'text', 'links');

        $this->getJson('/.well-known/assetlinks.json')
            ->assertJsonPath('0.target.package_name', 'com.asso.asso')
            ->assertJsonPath('0.target.sha256_cert_fingerprints.0', self::FINGERPRINT);

        $this->getJson('/.well-known/apple-app-site-association')
            ->assertJsonPath('applinks.details.0.appID', 'MVNNT5L9VD.com.asso.asso')
            ->assertJsonPath('applinks.details.0.paths', ['/p/*', '/b/*']);
    }

    public function test_l_admin_regle_les_interrupteurs_et_seul_le_partage_est_public(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('admin.settings.index'))->assertOk()->assertSee('Liens produit et boutique');

        $this->actingAs($admin)->put(route('admin.settings.links.update'), [
            'share_links_enabled' => '1',
            'app_links_association_enabled' => '1',
            'android_sha256_fingerprints' => strtolower(self::FINGERPRINT),
        ])->assertSessionHasNoErrors();

        $settings = $this->getJson('/api/settings')->assertOk()->json();
        $flat = json_encode($settings);
        $this->assertStringContainsString('share_links_enabled', $flat);
        $this->assertStringNotContainsString('android_sha256_fingerprints', $flat);
        $this->assertStringNotContainsString('app_links_association_enabled', $flat);
        $this->assertSame(self::FINGERPRINT, $this->getJson('/.well-known/assetlinks.json')->json('0.target.sha256_cert_fingerprints.0'));
    }

    public function test_une_empreinte_invalide_ou_absente_est_refusee(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->put(route('admin.settings.links.update'), [
            'app_links_association_enabled' => '1',
            'android_sha256_fingerprints' => 'REMPLACER_PAR_EMPREINTE',
        ])->assertSessionHasErrors('android_sha256_fingerprints');

        $this->actingAs($admin)->put(route('admin.settings.links.update'), [
            'app_links_association_enabled' => '1',
        ])->assertSessionHasErrors('android_sha256_fingerprints');

        $this->assertFalse((bool) Setting::get('app_links_association_enabled', false));
    }

    public function test_un_gestionnaire_ne_regle_pas_les_liens(): void
    {
        $manager = User::factory()->create(['role' => 'product_manager']);

        $this->actingAs($manager)
            ->put(route('admin.settings.links.update'), ['share_links_enabled' => '1'])
            ->assertForbidden();
    }
}
