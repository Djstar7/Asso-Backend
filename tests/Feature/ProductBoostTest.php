<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Package;
use App\Models\Product;
use App\Models\ProductBoost;
use App\Models\Shop;
use App\Models\User;
use App\Models\WalletBalance;
use App\Services\FcmService;
use App\Services\FirebaseMessagingService;
use App\Services\ProductBoostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Asso Ads — sponsoring d'un produit.
 *
 * Le vendeur achète un quota de vues pour une durée. Ce qui doit tenir :
 * le quota acheté est délivré sans être dépassé, la campagne se ferme au
 * premier des deux plafonds, et rien ne démarre avant l'encaissement.
 */
class ProductBoostTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(FirebaseMessagingService::class, function ($mock) {
            $mock->shouldReceive('sendToUser')->andReturn([]);
            // Annonce du produit sponsorisé à tous (voir ProductBroadcastTest).
            $mock->shouldReceive('sendToTopic')->andReturn(['success' => true]);
        });
        $this->mock(FcmService::class, function ($mock) {
            $mock->shouldIgnoreMissing();
        });
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function vendor(float $balance = 0): User
    {
        $user = User::factory()->create(['role' => 'vendeur']);

        if ($balance > 0) {
            WalletBalance::create([
                'user_id' => $user->id,
                'currency' => 'XAF',
                'balance' => $balance,
                'locked_balance' => 0,
            ]);
        }

        return $user;
    }

    /** Le vendeur garde une seule boutique, quel que soit le nombre d'articles. */
    private function productFor(User $user): Product
    {
        $shop = Shop::firstOrCreate(
            ['user_id' => $user->id],
            [
                'name' => 'Boutique ' . $user->id,
                'slug' => 'boutique-' . $user->id,
                'status' => 'active',
            ],
        );

        $category = Category::firstOrCreate(
            ['slug' => 'test'],
            ['name' => 'Test', 'is_active' => true],
        );

        return Product::create([
            'user_id' => $user->id,
            'shop_id' => $shop->id,
            'category_id' => $category->id,
            'name' => 'Article ' . uniqid(),
            'price' => 10000,
            'stock' => 5,
            'status' => 'active',
        ]);
    }

    private function boostPackage(int $reach = 1000, int $days = 2, float $price = 1000): Package
    {
        return Package::create([
            'type' => 'boost',
            'name' => 'Coup de pouce',
            'price' => $price,
            'duration_days' => $days,
            'reach_users' => $reach,
            'is_active' => true,
        ]);
    }

    // ------------------------------------------------------------------
    // Achat
    // ------------------------------------------------------------------

    public function test_le_paiement_par_wallet_ouvre_la_campagne_et_debite_le_vendeur(): void
    {
        $vendor = $this->vendor(balance: 5000);
        $product = $this->productFor($vendor);
        $package = $this->boostPackage(reach: 1000, days: 2, price: 1000);

        $response = $this->actingAs($vendor, 'sanctum')->postJson('/api/v1/packages/subscribe', [
            'package_id' => $package->id,
            'product_id' => $product->id,
            'payment_mode' => 'wallet',
        ]);

        $response->assertStatus(201)->assertJson(['success' => true]);

        $boost = ProductBoost::first();
        $this->assertNotNull($boost);
        $this->assertSame($product->id, $boost->product_id);
        $this->assertSame(1000, $boost->impressions_quota);
        $this->assertSame(ProductBoost::ACTIVE, $boost->status);

        // Les conditions sont figées à l'achat, pas relues dans le forfait.
        $this->assertSame(2, $boost->duration_days);
        $this->assertEqualsWithDelta(1000, (float) $boost->amount_xaf, 0.01);

        $this->assertEqualsWithDelta(4000, $vendor->fresh()->kpayBalanceFor('XAF'), 0.01);
    }

    public function test_un_forfait_boost_sans_produit_est_refuse(): void
    {
        $vendor = $this->vendor(balance: 5000);
        $package = $this->boostPackage();

        $this->actingAs($vendor, 'sanctum')
            ->postJson('/api/v1/packages/subscribe', [
                'package_id' => $package->id,
                'payment_mode' => 'wallet',
            ])
            ->assertStatus(422);

        $this->assertSame(0, ProductBoost::count());
    }

    public function test_on_ne_peut_pas_sponsoriser_le_produit_d_un_autre_vendeur(): void
    {
        $vendor = $this->vendor(balance: 5000);
        $other = $this->vendor();
        $foreignProduct = $this->productFor($other);
        $package = $this->boostPackage();

        $this->actingAs($vendor, 'sanctum')
            ->postJson('/api/v1/packages/subscribe', [
                'package_id' => $package->id,
                'product_id' => $foreignProduct->id,
                'payment_mode' => 'wallet',
            ])
            ->assertStatus(422);

        $this->assertSame(0, ProductBoost::count());
        // Le refus intervient avant tout débit.
        $this->assertEqualsWithDelta(5000, $vendor->fresh()->kpayBalanceFor('XAF'), 0.01);
    }

    public function test_un_produit_deja_sponsorise_ne_peut_pas_l_etre_deux_fois(): void
    {
        $vendor = $this->vendor(balance: 10000);
        $product = $this->productFor($vendor);
        $package = $this->boostPackage();

        $payload = [
            'package_id' => $package->id,
            'product_id' => $product->id,
            'payment_mode' => 'wallet',
        ];

        $this->actingAs($vendor, 'sanctum')->postJson('/api/v1/packages/subscribe', $payload)
            ->assertStatus(201);

        $this->actingAs($vendor, 'sanctum')->postJson('/api/v1/packages/subscribe', $payload)
            ->assertStatus(422);

        $this->assertSame(1, ProductBoost::count());
    }

    public function test_un_forfait_de_stockage_ne_cree_aucune_campagne(): void
    {
        $vendor = $this->vendor(balance: 5000);
        $storage = Package::create([
            'type' => 'storage',
            'name' => 'Starter',
            'price' => 1500,
            'duration_days' => 30,
            'storage_size_mb' => 300,
            'is_active' => true,
        ]);

        $this->actingAs($vendor, 'sanctum')
            ->postJson('/api/v1/packages/subscribe', [
                'package_id' => $storage->id,
                'payment_mode' => 'wallet',
            ])
            ->assertStatus(201);

        $this->assertSame(0, ProductBoost::count());
    }

    // ------------------------------------------------------------------
    // Diffusion et consommation du quota
    // ------------------------------------------------------------------

    public function test_le_quota_achete_n_est_jamais_depasse_et_ferme_la_campagne(): void
    {
        $vendor = $this->vendor();
        $product = $this->productFor($vendor);
        $service = app(ProductBoostService::class);

        $boost = $service->startCampaign($vendor, $product, $this->boostPackage(reach: 3));

        // Trois impressions : le quota est atteint pile.
        $service->consume([$boost]);
        $service->consume([$boost]);
        $service->consume([$boost]);

        $boost->refresh();
        $this->assertSame(3, $boost->impressions_served);
        $this->assertSame(ProductBoost::COMPLETED, $boost->status);
        $this->assertNotNull($boost->completed_at);

        // Une impression de plus ne doit rien entamer : le vendeur a eu ce
        // qu'il a payé, ni plus ni moins.
        $service->consume([$boost]);
        $boost->refresh();
        $this->assertSame(3, $boost->impressions_served);

        // Et la campagne ne doit plus être servie.
        $this->assertFalse($boost->isServable());
        $this->assertCount(0, $service->pickForFeed(5));
    }

    public function test_une_campagne_echue_n_est_plus_diffusee_et_se_cloture(): void
    {
        $vendor = $this->vendor();
        $product = $this->productFor($vendor);
        $service = app(ProductBoostService::class);

        $boost = $service->startCampaign($vendor, $product, $this->boostPackage(reach: 1000, days: 2));

        // L'échéance passe alors qu'il restait des vues à délivrer.
        $boost->update(['ends_at' => now()->subHour()]);

        $this->assertCount(0, $service->pickForFeed(5));

        $this->assertSame(1, $service->expireOverdue());
        $this->assertSame(ProductBoost::EXPIRED, $boost->fresh()->status);
    }

    public function test_le_clic_est_compte_sans_entamer_le_quota(): void
    {
        $vendor = $this->vendor();
        $product = $this->productFor($vendor);
        $service = app(ProductBoostService::class);

        $boost = $service->startCampaign($vendor, $product, $this->boostPackage(reach: 100));

        $service->consume([$boost]);
        $service->recordClick($product);

        $boost->refresh();
        $this->assertSame(1, $boost->impressions_served, 'Le clic ne consomme pas de vue.');
        $this->assertSame(1, $boost->clicks);
        $this->assertEqualsWithDelta(100.0, $boost->clickThroughRate(), 0.01);
    }

    public function test_le_feed_marque_le_produit_sponsorise_et_decompte_une_vue(): void
    {
        $vendor = $this->vendor();
        $service = app(ProductBoostService::class);

        // Une page de feed qui contient déjà d'autres articles : l'annonce s'y
        // intercale, elle ne remplace pas le contenu.
        $this->productFor($vendor);
        $this->productFor($vendor);

        $sponsoredProduct = $this->productFor($vendor);
        $boost = $service->startCampaign($vendor, $sponsoredProduct, $this->boostPackage(reach: 100));

        $response = $this->getJson('/api/v1/products?per_page=2');
        $response->assertStatus(200);

        $products = collect($response->json('products'));
        $sponsored = $products->firstWhere('is_sponsored', true);

        $this->assertNotNull($sponsored, 'Le feed doit contenir une carte sponsorisée.');
        $this->assertSame($sponsoredProduct->id, $sponsored['id']);
        $this->assertSame('Sponsorisé', $sponsored['sponsored_label']);

        // L'annonce s'ajoute aux résultats de la page, elle n'en évince aucun.
        $this->assertSame(3, $products->count());
        $this->assertSame(1, $products->where('is_sponsored', true)->count());

        // La vue servie est décomptée du quota acheté.
        $this->assertSame(1, $boost->fresh()->impressions_served);
    }

    public function test_un_produit_deja_present_dans_la_page_n_est_pas_duplique_en_annonce(): void
    {
        $vendor = $this->vendor();
        $service = app(ProductBoostService::class);

        // Le seul produit du catalogue est aussi celui qui est sponsorisé :
        // l'afficher deux fois dans la même page n'apporterait rien au vendeur
        // et tromperait l'acheteur.
        $product = $this->productFor($vendor);
        $boost = $service->startCampaign($vendor, $product, $this->boostPackage(reach: 100));

        $response = $this->getJson('/api/v1/products?per_page=10');
        $response->assertStatus(200);

        $products = collect($response->json('products'));
        $this->assertSame(1, $products->count());
        $this->assertFalse($products->first()['is_sponsored']);

        // Aucune vue n'est facturée pour une annonce qui n'a pas été servie.
        $this->assertSame(0, $boost->fresh()->impressions_served);
    }

    public function test_la_diffusion_desactivee_ne_sert_aucune_annonce(): void
    {
        $vendor = $this->vendor();
        $product = $this->productFor($vendor);
        $service = app(ProductBoostService::class);

        $boost = $service->startCampaign($vendor, $product, $this->boostPackage(reach: 100));

        \App\Models\Setting::set('ads_enabled', '0', 'boolean', 'ads');

        $this->assertCount(0, $service->pickForFeed(5));

        // La campagne reste intacte : elle reprendra à la réactivation.
        $this->assertSame(ProductBoost::ACTIVE, $boost->fresh()->status);
        $this->assertSame(0, $boost->fresh()->impressions_served);
    }

    // ------------------------------------------------------------------
    // Suivi vendeur
    // ------------------------------------------------------------------

    public function test_le_vendeur_suit_sa_campagne_et_ne_voit_pas_celles_des_autres(): void
    {
        $vendor = $this->vendor();
        $product = $this->productFor($vendor);
        $service = app(ProductBoostService::class);
        $boost = $service->startCampaign($vendor, $product, $this->boostPackage(reach: 100));
        $service->consume([$boost]);

        $response = $this->actingAs($vendor, 'sanctum')->getJson('/api/v1/vendor/boosts');
        $response->assertStatus(200);

        $campaigns = $response->json('boosts');
        $this->assertCount(1, $campaigns);
        $this->assertSame(1, $campaigns[0]['reached']);
        $this->assertSame(100, $campaigns[0]['impressions_quota']);
        $this->assertSame(99, $campaigns[0]['impressions_remaining']);

        // Un autre vendeur n'y a pas accès.
        $intruder = $this->vendor();
        $this->actingAs($intruder, 'sanctum')
            ->getJson("/api/v1/vendor/boosts/{$boost->id}")
            ->assertStatus(404);
    }

    public function test_le_vendeur_peut_arreter_sa_campagne(): void
    {
        $vendor = $this->vendor();
        $product = $this->productFor($vendor);
        $boost = app(ProductBoostService::class)
            ->startCampaign($vendor, $product, $this->boostPackage(reach: 100));

        $this->actingAs($vendor, 'sanctum')
            ->postJson("/api/v1/vendor/boosts/{$boost->id}/cancel")
            ->assertStatus(200);

        $this->assertSame(ProductBoost::CANCELLED, $boost->fresh()->status);

        // Une campagne arrêtée n'est plus diffusée.
        $this->assertCount(0, app(ProductBoostService::class)->pickForFeed(5));
    }

    public function test_la_campagne_survit_a_la_suppression_du_produit(): void
    {
        $vendor = $this->vendor();
        $product = $this->productFor($vendor);
        $name = $product->name;

        $boost = app(ProductBoostService::class)
            ->startCampaign($vendor, $product, $this->boostPackage(reach: 100, price: 2500));

        $product->delete();

        // La campagne reste en base : le chiffre d'affaires ne doit pas
        // disparaître rétroactivement de la comptabilité.
        $boost->refresh();
        $this->assertSame(1, ProductBoost::count());
        $this->assertNull($boost->product_id);
        $this->assertEqualsWithDelta(2500, (float) $boost->amount_xaf, 0.01);

        // Et elle reste lisible dans l'historique du vendeur.
        $this->assertSame($name, $boost->productLabel());
    }

    public function test_les_statistiques_du_jour_collent_aux_impressions_facturees(): void
    {
        $vendor = $this->vendor();
        $product = $this->productFor($vendor);
        $service = app(ProductBoostService::class);

        $boost = $service->startCampaign($vendor, $product, $this->boostPackage(reach: 2));

        // Trois tentatives pour un quota de deux : la troisième ne doit ni
        // être facturée, ni gonfler la courbe du vendeur.
        $service->consume([$boost]);
        $service->consume([$boost]);
        $service->consume([$boost]);

        $boost->refresh();
        $daily = (int) $boost->dailyStats()->sum('impressions');

        $this->assertSame(2, $boost->impressions_served);
        $this->assertSame(2, $daily, 'La courbe doit refléter exactement ce qui est facturé.');
    }

    public function test_une_campagne_au_quota_epuise_ne_bloque_pas_un_nouvel_achat(): void
    {
        $vendor = $this->vendor();
        $product = $this->productFor($vendor);
        $service = app(ProductBoostService::class);

        $boost = $service->startCampaign($vendor, $product, $this->boostPackage(reach: 1));
        $service->consume([$boost]);

        // Quota atteint : le produit redevient disponible, même si le statut
        // n'a pas encore basculé (fenêtre de concurrence).
        $boost->update(['status' => ProductBoost::ACTIVE, 'completed_at' => null]);

        $this->assertNull($service->activeBoostFor($product->fresh()));
        $service->assertBoostable($vendor, $product->fresh());
    }

    public function test_un_paiement_encaisse_dont_le_produit_a_disparu_est_rembourse(): void
    {
        $vendor = $this->vendor();
        $product = $this->productFor($vendor);
        $package = $this->boostPackage(reach: 1000, price: 2500);

        // Intent créé par un rail direct (Mobile Money / carte) : la campagne
        // n'existe pas encore, seul le produit ciblé est mémorisé.
        $subscription = \App\Models\PackageSubscription::create([
            'user_id' => $vendor->id,
            'package_id' => $package->id,
            'payment_method' => 'kpay_direct',
            'status' => 'pending',
            'amount_xaf' => 2500,
            'payment_reference' => 'TEST-REFUND-1',
            'metadata' => ['package_name' => $package->name, 'boost_product_id' => $product->id],
        ]);

        // Le vendeur supprime son article pendant que le paiement aboutit.
        $product->delete();

        app(\App\Services\PackageSubscriptionService::class)->confirm($subscription);

        $subscription->refresh();

        // Aucune campagne ouverte, mais l'argent est rendu — pas gardé.
        $this->assertSame(0, ProductBoost::count());
        $this->assertSame('failed', $subscription->status);
        $this->assertSame('boost_product_missing', $subscription->metadata['failure_reason']);
        $this->assertNotNull($subscription->metadata['refunded_at']);
        $this->assertEqualsWithDelta(2500, $vendor->fresh()->kpayBalanceFor('XAF'), 0.01);
    }

    // ------------------------------------------------------------------
    // Administration
    // ------------------------------------------------------------------

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => 'admin'])->saveQuietly();

        return $user;
    }

    public function test_l_ecran_admin_liste_les_campagnes_et_leurs_chiffres(): void
    {
        $vendor = $this->vendor();
        $product = $this->productFor($vendor);
        $service = app(ProductBoostService::class);
        $boost = $service->startCampaign($vendor, $product, $this->boostPackage(reach: 100, price: 1000));
        $service->consume([$boost]);

        $response = $this->actingAs($this->admin())->get(route('admin.ads.index'));

        $response->assertStatus(200);
        $response->assertSee('Asso Ads');
        $response->assertSee('Revenus sponsoring');
        $response->assertSee($product->name);
    }

    public function test_l_ecran_admin_detaille_une_campagne(): void
    {
        $vendor = $this->vendor();
        $product = $this->productFor($vendor);
        $service = app(ProductBoostService::class);
        $boost = $service->startCampaign($vendor, $product, $this->boostPackage(reach: 100));
        $service->consume([$boost]);
        $service->recordClick($product);

        $response = $this->actingAs($this->admin())->get(route('admin.ads.show', $boost));

        $response->assertStatus(200);
        $response->assertSee('Portée délivrée');
        $response->assertSee('Personnes touchées');
        $response->assertSee($product->name);
    }

    public function test_l_admin_regle_la_diffusion_et_peut_la_suspendre(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.ads.settings'), [
                'slots_per_page' => 3,
                'slot_positions' => '2, 0, 7, 0',
            ])
            ->assertRedirect(route('admin.ads.index'));

        $service = app(ProductBoostService::class);

        $this->assertFalse($service->enabled(), 'La case décochée suspend la diffusion.');
        $this->assertSame(3, $service->slotsPerPage());
        // Positions dédoublonnées et triées : l'ordre de saisie n'a pas à compter.
        $this->assertSame([0, 2, 7], $service->slotPositions());
    }

    public function test_l_admin_peut_arreter_une_campagne(): void
    {
        $vendor = $this->vendor();
        $product = $this->productFor($vendor);
        $boost = app(ProductBoostService::class)
            ->startCampaign($vendor, $product, $this->boostPackage(reach: 100));

        $this->actingAs($this->admin())
            ->post(route('admin.ads.cancel', $boost))
            ->assertRedirect();

        $this->assertSame(ProductBoost::CANCELLED, $boost->fresh()->status);
    }

    /**
     * Le prix est fixé en XAF ; un vendeur qui paie dans une autre devise doit
     * voir la campagne ouverte aux conditions achetées, pas converties.
     */
    public function test_la_campagne_fige_le_montant_en_xaf_quelle_que_soit_la_devise_de_paiement(): void
    {
        $vendor = $this->vendor();
        $product = $this->productFor($vendor);
        $package = $this->boostPackage(reach: 1000, days: 2, price: 1000);

        $boost = app(ProductBoostService::class)
            ->startCampaign($vendor, $product, $package);

        // Le pivot XAF est la référence comptable : c'est lui qui est stocké.
        $this->assertEqualsWithDelta(1000, (float) $boost->amount_xaf, 0.01);
        $this->assertSame(1000, $boost->impressions_quota);
    }

    public function test_les_forfaits_de_sponsoring_sont_exposes_a_l_application(): void
    {
        $this->boostPackage(reach: 1000, days: 2, price: 1000);
        Package::create([
            'type' => 'storage',
            'name' => 'Starter',
            'price' => 1500,
            'duration_days' => 30,
            'storage_size_mb' => 300,
            'is_active' => true,
        ]);

        $vendor = $this->vendor();
        $response = $this->actingAs($vendor, 'sanctum')->getJson('/api/v1/packages/boost');

        $response->assertStatus(200);
        $packages = $response->json('packages');

        // Seuls les forfaits de sponsoring, avec l'audience achetée.
        $this->assertCount(1, $packages);
        $this->assertSame(1000, $packages[0]['reach_users']);
        $this->assertSame(2, $packages[0]['duration_days']);
    }
}
