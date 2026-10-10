<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DelivererCompany;
use App\Models\Dispute;
use App\Models\DisputeShipment;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Models\WalletBalance;
use App\Services\DisputeService;
use App\Services\FirebaseMessagingService;
use App\Services\OrderTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Réclamations & litiges : part vendeur bloquée 48 h après la livraison, décision
 * ASSO, remplacement (une fois), retour payé par le vendeur, remboursement du client
 * et produits similaires à livraison offerte par leur vendeur.
 */
class DisputeTest extends TestCase
{
    use RefreshDatabase;

    private User $client;
    private User $seller;
    private User $courier;
    private User $asso;
    private DelivererCompany $company;
    private Category $category;
    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(FirebaseMessagingService::class, fn ($mock) => $mock->shouldReceive('sendToUser')->andReturn([]));

        $this->client = User::factory()->create();
        $this->seller = User::factory()->create();
        $this->courier = User::factory()->create();
        $this->asso = User::factory()->create(['email' => 'admin@asso.com']);
        foreach ([$this->client, $this->seller, $this->courier, $this->asso] as $user) {
            $this->setBalance($user, 0);
        }
        $this->company = DelivererCompany::create(['user_id' => $this->courier->id, 'name' => 'Livreur SARL', 'is_active' => true]);
        $this->category = Category::create(['name' => 'Mode', 'slug' => 'mode-' . uniqid()]);
        $this->shop = Shop::create([
            'user_id' => $this->seller->id, 'name' => 'Boutique', 'slug' => 'boutique-' . uniqid(), 'status' => 'active',
        ]);
    }

    private function setBalance(User $user, float $balance, float $locked = 0): void
    {
        WalletBalance::updateOrCreate(['user_id' => $user->id, 'currency' => 'XAF'], ['balance' => $balance, 'locked_balance' => $locked]);
    }

    private function bal(User $user): float
    {
        return $user->fresh()->kpayBalanceFor('XAF');
    }

    private function locked(User $user): float
    {
        return $user->fresh()->kpayBalanceFor('XAF') - $user->fresh()->kpayAvailableFor('XAF');
    }

    private function product(array $attributes = []): Product
    {
        return Product::create($attributes + [
            'user_id' => $this->seller->id,
            'shop_id' => $this->shop->id,
            'category_id' => $this->category->id,
            'name' => 'Sac',
            'slug' => 'sac-' . uniqid(),
            'price' => 1000,
            'currency' => 'XAF',
            'stock' => 10,
            'status' => 'active',
        ]);
    }

    /**
     * Commande payée (Mobile Money) de deux articles, validée par le vendeur puis
     * livrée : part vendeur (2 × 1000) créditée et bloquée, fenêtre de 48 h ouverte.
     */
    private function deliveredOrder(): Order
    {
        $order = Order::create([
            'user_id' => $this->client->id,
            'status' => 'pending',
            'subtotal' => 2200,
            'sale_commission' => 200,
            'sale_commission_rate' => 10,
            'vendor_net_amount' => 2000,
            'delivery_fee' => 500,
            'base_delivery_price' => 400,
            'delivery_commission' => 100,
            'total' => 2700,
            'delivery_company_id' => $this->company->id,
            'delivery_address' => 'Bonapriso, Douala',
            'payment_method' => 'kpay_direct',
            'payment_status' => 'paid',
        ]);
        foreach ([$this->product(), $this->product(['name' => 'Chaussures'])] as $product) {
            $order->items()->create([
                'product_id' => $product->id, 'seller_id' => $this->seller->id, 'quantity' => 1,
                'unit_price' => 1100, 'total_price' => 1100, 'seller_unit_price' => 1000, 'seller_total_price' => 1000,
            ]);
        }

        $this->actingAs($this->seller, 'sanctum')->postJson("/api/v1/vendor/orders/{$order->id}/validate")->assertOk();
        $order->refresh()->update(['status' => 'delivered', 'delivered_at' => now()]);
        app(OrderTrackingService::class)->record($order, 'delivered');

        return $order->refresh();
    }

    private function openDispute(Order $order, int $itemIndex = 0): Dispute
    {
        $item = $order->items()->orderBy('id')->get()[$itemIndex];
        $this->actingAs($this->client, 'sanctum')
            ->postJson("/api/v1/orders/{$order->id}/disputes", [
                'order_item_id' => $item->id,
                'reason' => 'damaged',
                'description' => 'Le sac est arrivé déchiré sur le côté.',
            ])->assertCreated();

        return Dispute::where('order_item_id', $item->id)->firstOrFail();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'roles' => ['admin']]);
    }

    /** Course choisie (partenaire et prix figés) sans passer par le moteur de devis. */
    private function priceShipment(DisputeShipment $shipment): DisputeShipment
    {
        $shipment->update(['deliverer_company_id' => $this->company->id, 'price' => 500, 'carrier_amount' => 400, 'asso_commission' => 100]);

        return $shipment->refresh();
    }

    public function test_seller_share_is_held_and_released_when_client_confirms(): void
    {
        $order = $this->deliveredOrder();
        $this->assertSame(2000.0, $this->locked($this->seller));
        $this->assertTrue($order->isInControlWindow());

        $this->actingAs($this->client, 'sanctum')->postJson("/api/v1/orders/{$order->id}/conform")
            ->assertOk()->assertJsonPath('order.control.validated', true);

        $this->assertSame(2000.0, $this->bal($this->seller));
        $this->assertSame(0.0, $this->locked($this->seller));
        $this->assertNotNull($order->fresh()->conformity_confirmed_at);
    }

    public function test_order_is_validated_automatically_after_48_hours(): void
    {
        $order = $this->deliveredOrder();

        $this->artisan('orders:auto-validate');
        $this->assertSame(2000.0, $this->locked($this->seller), 'pas avant 48 h');

        $this->travel(49)->hours();
        $this->artisan('orders:auto-validate')->assertSuccessful();

        $this->assertSame(0.0, $this->locked($this->seller));
        $this->assertSame(Order::VENDOR_FUNDS_RELEASED, $order->fresh()->vendor_funds_status);

        // Fenêtre fermée : plus de réclamation possible.
        $this->actingAs($this->client, 'sanctum')
            ->postJson("/api/v1/orders/{$order->id}/disputes", [
                'order_item_id' => $order->items->first()->id, 'reason' => 'damaged', 'description' => 'Trop tard pour signaler.',
            ])->assertStatus(422);
    }

    public function test_only_the_disputed_item_stays_blocked(): void
    {
        $order = $this->deliveredOrder();
        $dispute = $this->openDispute($order);

        $this->assertSame(1000.0, (float) $dispute->held_amount);
        $this->assertSame(1000.0, (float) $order->fresh()->vendor_funds_held_amount);

        $this->travel(49)->hours();
        $this->artisan('orders:auto-validate');

        // L'autre article est réglé ; celui en litige reste bloqué.
        $this->assertSame(2000.0, $this->bal($this->seller));
        $this->assertSame(1000.0, $this->locked($this->seller));

        // Un seul litige par article.
        $this->actingAs($this->client, 'sanctum')->getJson("/api/v1/orders/{$order->id}")
            ->assertJsonPath('order.items.0.dispute.number', $dispute->number);
    }

    public function test_unfounded_claim_releases_the_item_share(): void
    {
        $dispute = $this->openDispute($this->deliveredOrder());

        $this->actingAs($this->admin())
            ->post("/admin/disputes/{$dispute->id}/decide", ['decision' => 'unfounded', 'note' => 'Photos sans défaut visible.'])
            ->assertRedirect();

        $dispute->refresh();
        $this->assertSame(Dispute::STATUS_REJECTED, $dispute->status);
        $this->assertNotNull($dispute->closed_at);
        $this->assertSame(1000.0, $this->locked($this->seller)); // reste : l'autre article, en fenêtre
        $this->assertDatabaseHas('dispute_events', ['dispute_id' => $dispute->id, 'type' => 'decision']);
    }

    public function test_seller_cannot_decide_and_replacement_is_allowed_once(): void
    {
        $dispute = $this->openDispute($this->deliveredOrder());
        $service = app(DisputeService::class);

        // Avant la décision ASSO, le vendeur ne peut rien lancer.
        $this->actingAs($this->seller, 'sanctum')->postJson("/api/v1/vendor/disputes/{$dispute->id}/replace")->assertStatus(422);

        $service->decide($dispute, $this->admin(), Dispute::DECISION_FOUNDED, 'Défaut confirmé.');
        $this->actingAs($this->seller, 'sanctum')->postJson("/api/v1/vendor/disputes/{$dispute->id}/replace")
            ->assertOk()->assertJsonPath('dispute.status', Dispute::STATUS_REPLACEMENT);

        $shipment = $this->priceShipment($dispute->fresh()->shipment(DisputeShipment::TYPE_REPLACEMENT));
        $this->setBalance($this->seller, 2500, 2000);
        $this->actingAs($this->seller, 'sanctum')
            ->postJson("/api/v1/vendor/disputes/shipments/{$shipment->id}/pay", ['payment_mode' => 'wallet'])->assertOk();
        $service->recordStep($shipment->fresh(), 'shipped', 'vendor', $this->seller->id);
        $service->recordStep($shipment->fresh(), 'delivered', 'admin', null);

        // Remplacement encore non conforme → Cas B imposé, pas de second remplacement.
        $this->actingAs($this->client, 'sanctum')
            ->postJson("/api/v1/disputes/{$dispute->id}/report-replacement", ['description' => 'Le nouveau sac est aussi abîmé.'])
            ->assertOk()->assertJsonPath('dispute.status', Dispute::STATUS_RETURN);

        $this->assertSame(1, $dispute->fresh()->replacement_count);
        $this->expectException(\Exception::class);
        $service->startReplacement($dispute->fresh(), 'vendor', $this->seller->id);
    }

    public function test_wholesale_dispute_follows_the_same_rules_as_a_regular_one(): void
    {
        $order = $this->deliveredOrder();
        $order->update(['is_wholesale' => true]);
        $dispute = $this->openDispute($order);
        $service = app(DisputeService::class);
        $service->decide($dispute, $this->admin(), Dispute::DECISION_FOUNDED, 'Défaut confirmé.');

        $actions = $service->actions($dispute->fresh(), 'vendor');
        $this->assertTrue($actions['replace']);
        $this->assertTrue($actions['organize_return']);

        // Le « vendeur » est ASSO : remplacement lancé et course payée depuis le back-office.
        $admin = $this->admin();
        $this->actingAs($admin)->post("/admin/disputes/{$dispute->id}/replace")->assertSessionHas('success');
        $shipment = $dispute->fresh()->shipment(DisputeShipment::TYPE_REPLACEMENT);
        $this->assertSame('asso', $shipment->payer);

        $this->priceShipment($shipment);
        $this->actingAs($admin)->post(route('admin.disputes.shipments.pay-by-asso', $shipment))->assertSessionHas('success');
        $this->assertTrue($shipment->fresh()->isPaid());
    }

    public function test_replacement_validated_by_client_releases_the_item_share(): void
    {
        $dispute = $this->openDispute($this->deliveredOrder());
        $service = app(DisputeService::class);
        $service->decide($dispute, $this->admin(), Dispute::DECISION_FOUNDED, 'Défaut confirmé.');
        $shipment = $this->priceShipment($service->startReplacement($dispute, 'vendor', $this->seller->id));
        $this->setBalance($this->seller, 2500, 2000);
        $service->initiatePayment($shipment, $this->seller, 'wallet');
        $service->recordStep($shipment->fresh(), 'shipped', 'vendor', $this->seller->id);
        $service->recordStep($shipment->fresh(), 'delivered', 'admin', null);
        $this->assertTrue($dispute->fresh()->isInReplacementControl());

        $this->actingAs($this->client, 'sanctum')->postJson("/api/v1/disputes/{$dispute->id}/confirm-replacement")
            ->assertOk()->assertJsonPath('dispute.status', Dispute::STATUS_RESOLVED);

        // Article réglé au vendeur ; l'autre article reste dans la fenêtre de 48 h.
        $this->assertSame(1000.0, $this->locked($this->seller));
        $this->assertSame(0.0, (float) $dispute->fresh()->held_amount);
    }

    public function test_return_paid_by_seller_then_refund_without_seller_confirmation(): void
    {
        $order = $this->deliveredOrder();
        $dispute = $this->openDispute($order);
        $service = app(DisputeService::class);
        $service->decide($dispute, $this->admin(), Dispute::DECISION_FOUNDED, 'Défaut confirmé.');

        $this->actingAs($this->seller, 'sanctum')->postJson("/api/v1/vendor/disputes/{$dispute->id}/return")
            ->assertOk()->assertJsonPath('dispute.actions.pay_shipment', true);

        // Le vendeur paie la course retour depuis son Wallet (solde disponible : 500).
        $shipment = $this->priceShipment($dispute->fresh()->shipment(DisputeShipment::TYPE_RETURN));
        $this->setBalance($this->seller, 2500, 2000);
        $this->actingAs($this->seller, 'sanctum')
            ->postJson("/api/v1/vendor/disputes/shipments/{$shipment->id}/pay", ['payment_mode' => 'wallet'])
            ->assertOk()->assertJsonPath('shipment.status', 'courier_selected');

        $this->assertSame(2000.0, $this->bal($this->seller));
        $this->assertSame(800.0, $this->bal($this->courier)); // 400 commande + 400 retour
        $this->assertSame(400.0, $this->bal($this->asso));    // 300 commande + 100 retour

        // ASSO confirme le retour (le vendeur n'a rien confirmé) : remboursement.
        $this->actingAs($this->admin())
            ->post("/admin/disputes/shipments/{$shipment->id}/step", ['step' => 'confirmed'])->assertRedirect();

        $dispute->refresh();
        $this->assertSame(Dispute::STATUS_REFUNDED, $dispute->status);
        $this->assertSame(1100.0, (float) $dispute->refund_amount);
        $this->assertSame(1100.0, $this->bal($this->client));
        // Part de l'article retirée au vendeur ; l'autre article reste bloqué (fenêtre).
        $this->assertSame(1000.0, $this->bal($this->seller));
        $this->assertSame(1000.0, $this->locked($this->seller));
        $this->assertSame(300.0, $this->bal($this->asso)); // commission de l'article rendue
    }

    public function test_return_payment_by_mobile_money_resumes_on_kpay_webhook_reference(): void
    {
        $dispute = $this->openDispute($this->deliveredOrder());
        $service = app(DisputeService::class);
        $service->decide($dispute, $this->admin(), Dispute::DECISION_FOUNDED, 'Défaut confirmé.');
        $shipment = $this->priceShipment($service->startReturn($dispute, 'admin', null));
        $shipment->update(['payment_mode' => 'kpay_direct', 'payment_reference' => 'kp_123']);

        $resolved = DisputeService::shipmentForKpayReference(DisputeService::KPAY_PREFIX . $shipment->id);
        $this->assertTrue($resolved->is($shipment));
        $service->confirmPayment($resolved);
        $service->confirmPayment($resolved); // idempotent

        $this->assertSame(DisputeShipment::PAYMENT_PAID, $shipment->fresh()->payment_status);
        $this->assertSame(800.0, $this->bal($this->courier));
        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $this->seller->id, 'reference_type' => 'dispute_shipment', 'reference_id' => $shipment->id, 'type' => 'debit',
        ]);
    }

    public function test_similar_products_only_offer_seller_paid_free_delivery(): void
    {
        $order = $this->deliveredOrder();
        $dispute = $this->openDispute($order);

        $otherSeller = User::factory()->create();
        $freeShop = Shop::create(['user_id' => $otherSeller->id, 'name' => 'Gratuite', 'slug' => 'g-' . uniqid(), 'status' => 'active', 'free_delivery' => true]);
        $paidShop = Shop::create(['user_id' => User::factory()->create()->id, 'name' => 'Payante', 'slug' => 'p-' . uniqid(), 'status' => 'active']);
        $inherits = $this->product(['shop_id' => $freeShop->id, 'user_id' => $otherSeller->id, 'name' => 'Suit la boutique']);
        $optedOut = $this->product(['shop_id' => $freeShop->id, 'user_id' => $otherSeller->id, 'name' => 'Exclu', 'free_delivery' => false]);
        $ownFree = $this->product(['shop_id' => $paidShop->id, 'user_id' => $paidShop->user_id, 'name' => 'Offerte produit', 'free_delivery' => true]);
        $paid = $this->product(['shop_id' => $paidShop->id, 'user_id' => $paidShop->user_id, 'name' => 'Payante']);
        $sameSeller = $this->product(['name' => 'Même vendeur', 'free_delivery' => true]);

        // Pas avant le remboursement.
        $this->actingAs($this->client, 'sanctum')->getJson("/api/v1/disputes/{$dispute->id}/similar-products")->assertStatus(422);

        $dispute->update(['status' => Dispute::STATUS_REFUNDED]);
        $ids = collect($this->actingAs($this->client, 'sanctum')
            ->getJson("/api/v1/disputes/{$dispute->id}/similar-products")->assertOk()->json('products'))->pluck('id');

        $this->assertTrue($ids->contains($inherits->id));
        $this->assertTrue($ids->contains($ownFree->id));
        $this->assertFalse($ids->contains($optedOut->id));
        $this->assertFalse($ids->contains($paid->id));
        $this->assertFalse($ids->contains($sameSeller->id), 'pas la boutique du vendeur en cause');
    }
}
