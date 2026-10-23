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

    public function test_photos_stay_with_their_author_and_their_message(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $order = $this->deliveredOrder();
        $service = app(DisputeService::class);
        $dispute = $service->open($order, $order->items()->first()->id, $this->client, 'damaged', 'Photo du client',
            [\Illuminate\Http\UploadedFile::fake()->image('client.jpg')]);
        $service->addEvidence($dispute, 'vendor', $this->seller, 'Première réponse',
            [\Illuminate\Http\UploadedFile::fake()->image('vendor-one.jpg')]);
        $service->addEvidence($dispute, 'vendor', $this->seller, 'Deuxième réponse',
            [\Illuminate\Http\UploadedFile::fake()->image('vendor-two.jpg')]);
        $data = $this->actingAs($this->client, 'sanctum')->getJson("/api/v1/disputes/{$dispute->id}")->assertOk()->json('dispute');
        $this->assertCount(1, $data['attachments']);
        $this->assertSame('client', $data['attachments'][0]['author_type']);
        $replies = collect($data['events'])->where('type', 'evidence')->values();
        $this->assertCount(2, $replies);
        foreach ($replies as $reply) {
            $this->assertCount(1, $reply['attachments']);
            $this->assertSame('vendor', $reply['attachments'][0]['author_type']);
        }
        $this->assertNotSame($replies[0]['attachments'][0]['url'], $replies[1]['attachments'][0]['url']);
        $this->assertSame(0, $dispute->attachments()->whereNull('dispute_event_id')->count());
    }

    public function test_admin_cannot_skip_collection_steps_or_handover_code(): void
    {
        $service = app(DisputeService::class);
        $dispute = $this->openDispute($this->assoOrder());
        $admin = $this->admin();
        $service->decide($dispute, $admin, Dispute::DECISION_FOUNDED, 'Défaut confirmé');
        $shipment = $dispute->fresh()->shipment(DisputeShipment::TYPE_COLLECTION);
        $this->assertSame(['pickup_scheduled'], $shipment->manualSteps());
        $this->actingAs($admin)->post(route('admin.disputes.shipments.step', $shipment),
            ['step' => 'delivered_to_vendor', 'confirmation_code' => $shipment->confirmation_code])->assertSessionHas('error');
        $this->assertSame('courier_selected', $shipment->fresh()->status);
        foreach (['pickup_scheduled', 'picked_up', 'in_transit'] as $step) {
            $service->recordStep($shipment->fresh(), $step, 'admin', $admin->id);
        }
        $this->assertSame(['delivered_to_vendor'], $shipment->fresh()->manualSteps());
        $this->get(route('admin.disputes.show', $dispute))->assertOk()->assertSee('Réception en boutique');
        $this->post(route('admin.disputes.shipments.step', $shipment),
            ['step' => 'delivered_to_vendor', 'confirmation_code' => 'wrong'])->assertSessionHas('error');
        $this->assertSame('in_transit', $shipment->fresh()->status);
        $this->post(route('admin.disputes.shipments.step', $shipment),
            ['step' => 'delivered_to_vendor', 'confirmation_code' => $shipment->confirmation_code])->assertSessionHas('success');
        $this->assertTrue($shipment->fresh()->isHandedOver());
        $this->assertSame(0.0, $this->bal($this->client));
    }

    public function test_staff_alerts_skip_technical_admin_accounts(): void
    {
        $manager = User::factory()->create(['role' => 'admin', 'email' => 'gestion@asso.cm']);
        User::factory()->create(['role' => 'admin', 'email' => 'system@asso.app']);
        User::factory()->create(['role' => 'admin', 'email' => \App\Services\SupportService::EMAIL]);

        $emails = DisputeService::disputeStaff()->pluck('email');

        $this->assertContains($manager->email, $emails);
        $this->assertNotContains('system@asso.app', $emails);
        $this->assertNotContains(\App\Services\SupportService::EMAIL, $emails);
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
        $order = $this->deliveredOrder();
        $dispute = $this->openDispute($order);
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
        $this->deliverReplacementWithClientCode($dispute);

        // Problème sur le remplacement : le dossier est rouvert (réponse du vendeur,
        // décision ASSO), sans retour automatique.
        $this->actingAs($this->client, 'sanctum')
            ->postJson("/api/v1/disputes/{$dispute->id}/report-replacement", ['description' => 'Le nouveau sac est aussi abîmé.'])
            ->assertOk()
            ->assertJsonPath('dispute.status', Dispute::STATUS_NEW)
            ->assertJsonPath('dispute.decision', null);
        $this->assertSame(1000.0, (float) $dispute->fresh()->held_amount);

        // Fondée : pas de second remplacement, le retour est lancé et le vendeur doit le payer.
        $service->decide($dispute->fresh(), $this->admin(), Dispute::DECISION_FOUNDED, 'Toujours abîmé.');
        $dispute->refresh();
        $this->assertSame(Dispute::STATUS_RETURN, $dispute->status);
        $this->assertSame(1, $dispute->replacement_count);
        $this->assertSame('vendor', $dispute->shipment(DisputeShipment::TYPE_RETURN)->payer);
        $this->expectException(\Exception::class);
        $service->startReplacement($dispute->fresh(), 'vendor', $this->seller->id);
    }

    public function test_unfounded_issue_on_the_replacement_concludes_the_sale(): void
    {
        $dispute = $this->openDispute($this->deliveredOrder());
        $service = app(DisputeService::class);
        $service->decide($dispute, $this->admin(), Dispute::DECISION_FOUNDED, 'Défaut confirmé.');
        $shipment = $this->priceShipment($service->startReplacement($dispute, 'vendor', $this->seller->id));
        $this->setBalance($this->seller, 2500, 2000);
        $service->initiatePayment($shipment, $this->seller, 'wallet');
        $this->deliverReplacementWithClientCode($dispute);

        $service->reportReplacement($dispute->fresh(), $this->client, 'Couleur différente de la photo.');
        $service->decide($dispute->fresh(), $this->admin(), Dispute::DECISION_UNFOUNDED, 'Conforme à l\'annonce.');

        $this->assertSame(Dispute::STATUS_REJECTED, $dispute->fresh()->status);
        $this->assertSame(1000.0, $this->locked($this->seller)); // seul l'autre article reste bloqué
    }

    /**
     * Le livreur du partenaire prend la course, récupère le remplacement à la boutique
     * et le remet au client contre son code (affiché au client seulement).
     */
    private function deliverReplacementWithClientCode(Dispute $dispute): void
    {
        $shipment = $dispute->fresh()->shipment(DisputeShipment::TYPE_REPLACEMENT);
        $courses = $this->actingAs($this->courier, 'sanctum')->getJson('/api/v1/delivery/dispute-courses')
            ->assertOk()->json('courses');
        $this->assertSame([$shipment->id], array_column($courses, 'id'));
        $this->assertSame('vendor', $courses[0]['pickup']['role']);
        $this->assertSame('client', $courses[0]['dropoff']['role']);

        $code = $this->actingAs($this->client, 'sanctum')->getJson("/api/v1/disputes/{$dispute->id}")
            ->json('dispute.replacement.confirmation_code');
        $this->assertSame($shipment->confirmation_code, $code);
        $this->assertNull($this->actingAs($this->seller, 'sanctum')->getJson("/api/v1/vendor/disputes/{$dispute->id}")
            ->json('dispute.replacement.confirmation_code'));

        $this->actingAs($this->courier, 'sanctum')->postJson("/api/v1/delivery/dispute-courses/{$shipment->id}/accept")->assertOk();
        $this->actingAs($this->courier, 'sanctum')->postJson("/api/v1/delivery/dispute-courses/{$shipment->id}/pickup")
            ->assertOk()->assertJsonPath('course.status', 'shipped');
        $this->actingAs($this->courier, 'sanctum')->postJson("/api/v1/delivery/dispute-courses/{$shipment->id}/complete", ['confirmation_code' => '000000' === $code ? '111111' : '000000'])
            ->assertStatus(422);
        $this->actingAs($this->courier, 'sanctum')->postJson("/api/v1/delivery/dispute-courses/{$shipment->id}/complete", ['confirmation_code' => $code])
            ->assertOk()->assertJsonPath('course.status', 'delivered');

        $this->assertTrue($dispute->fresh()->isInReplacementControl());
        // Commande : mêmes choix qu'à la première réception.
        $item = collect($this->actingAs($this->client, 'sanctum')->getJson("/api/v1/orders/{$dispute->order_id}")->json('order.items'))
            ->firstWhere('id', $dispute->order_item_id);
        $this->assertNotNull($item['dispute']['replacement_control_until']);
    }

    public function test_wholesale_dispute_follows_the_same_rules_as_a_regular_one(): void
    {
        $order = $this->deliveredOrder();
        $order->update(['is_wholesale' => true]);
        $admin = $this->admin();
        \Illuminate\Support\Facades\Mail::fake();

        // Le « vendeur » étant ASSO, ce sont les employés des litiges qui sont prévenus.
        $notified = [];
        $this->mock(FirebaseMessagingService::class, function ($mock) use (&$notified) {
            $mock->shouldReceive('sendToUser')->andReturnUsing(function (User $user, $title, $body, array $data = []) use (&$notified) {
                $notified[] = [$user->id, $data['type']];

                return [];
            });
        });

        $dispute = $this->openDispute($order);
        $service = app(DisputeService::class);
        $service->decide($dispute, $admin, Dispute::DECISION_FOUNDED, 'Défaut confirmé.');
        $this->assertContains([$admin->id, 'dispute_opened'], $notified);
        $this->assertNotContains($this->seller->id, array_column($notified, 0));
        // … et reçoivent un e-mail avec le lien vers le litige.
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\DisputeStaffAlertMail::class, fn ($mail) => $mail->hasTo($admin->email)
            && $mail->dispute->is($dispute)
            && str_contains($mail->render(), $dispute->number));
        \Illuminate\Support\Facades\Mail::assertNotSent(\App\Mail\DisputeStaffAlertMail::class, fn ($mail) => $mail->hasTo($this->seller->email));

        $actions = $service->actions($dispute->fresh(), 'vendor');
        $this->assertFalse($actions['replace']);
        $this->assertFalse($actions['organize_return']);

        // Le « vendeur » est ASSO : remplacement lancé et course payée depuis le back-office.
        $shipment = $dispute->fresh()->shipment(DisputeShipment::TYPE_COLLECTION);
        $this->assertSame('asso', $shipment->payer);
        $this->actingAs($admin)->get('/admin/disputes?status=all&asso_payment=1')->assertOk()->assertSee($dispute->number);

        $this->priceShipment($shipment);
        $this->setBalance($this->asso, 1000);
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
        // Saisie de secours par ASSO : sans le code du client, refusée.
        $this->actingAs($this->admin())->post("/admin/disputes/shipments/{$shipment->id}/step", ['step' => 'delivered'])
            ->assertSessionHas('error');
        $this->actingAs($this->admin())->post("/admin/disputes/shipments/{$shipment->id}/step", [
            'step' => 'delivered', 'confirmation_code' => $shipment->fresh()->confirmation_code,
        ])->assertSessionHas('success');
        $this->assertTrue($dispute->fresh()->isInReplacementControl());

        $this->actingAs($this->client, 'sanctum')->postJson("/api/v1/disputes/{$dispute->id}/confirm-replacement")
            ->assertOk()->assertJsonPath('dispute.status', Dispute::STATUS_RESOLVED);

        // Article réglé au vendeur ; l'autre article reste dans la fenêtre de 48 h.
        $this->assertSame(1000.0, $this->locked($this->seller));
        $this->assertSame(0.0, (float) $dispute->fresh()->held_amount);
    }

    public function test_return_handed_to_seller_with_his_code_refunds_the_client(): void
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
        // 400 commande + 400 retour, au Wallet du compte interne du partenaire.
        $this->assertSame(0.0, $this->bal($this->courier));
        $this->assertSame(800.0, (\App\Models\DelivererCompany::find($this->company->id)?->account?->kpayBalanceFor('XAF') ?? 0));
        $this->assertSame(400.0, $this->bal($this->asso));    // 300 commande + 100 retour

        // « Retour confirmé » ne se saisit plus à la main.
        $this->actingAs($this->admin())
            ->post("/admin/disputes/shipments/{$shipment->id}/step", ['step' => 'confirmed'])->assertSessionHas('error');

        // Le livreur prend le produit chez le client et le remet au vendeur contre le
        // code du vendeur : le client est remboursé aussitôt.
        $code = $this->actingAs($this->seller, 'sanctum')->getJson("/api/v1/vendor/disputes/{$dispute->id}")
            ->json('dispute.return.confirmation_code');
        $this->assertNotNull($code);
        $this->assertNull($this->actingAs($this->client, 'sanctum')->getJson("/api/v1/disputes/{$dispute->id}")
            ->json('dispute.return.confirmation_code'));
        $course = $this->actingAs($this->courier, 'sanctum')->getJson('/api/v1/delivery/dispute-courses')->json('courses.0');
        $this->assertSame('client', $course['pickup']['role']);
        $this->assertSame('vendor', $course['dropoff']['role']);
        $this->actingAs($this->courier, 'sanctum')->postJson("/api/v1/delivery/dispute-courses/{$shipment->id}/accept")->assertOk();
        $this->actingAs($this->courier, 'sanctum')->postJson("/api/v1/delivery/dispute-courses/{$shipment->id}/pickup")
            ->assertOk()->assertJsonPath('course.status', 'in_transit');
        $this->actingAs($this->courier, 'sanctum')->postJson("/api/v1/delivery/dispute-courses/{$shipment->id}/complete", ['confirmation_code' => $code])
            ->assertOk();
        $this->assertSame('confirmed', $shipment->fresh()->status);
        $this->assertSame([], $this->actingAs($this->courier, 'sanctum')->getJson('/api/v1/delivery/dispute-courses')->json('courses'));

        $dispute->refresh();
        $this->assertSame(Dispute::STATUS_REFUNDED, $dispute->status);
        $this->assertSame(1100.0, (float) $dispute->refund_amount);
        $this->assertSame(1100.0, $this->bal($this->client));
        // Part de l'article retirée au vendeur ; l'autre article reste bloqué (fenêtre).
        $this->assertSame(1000.0, $this->bal($this->seller));
        $this->assertSame(1000.0, $this->locked($this->seller));
        $this->assertSame(300.0, $this->bal($this->asso)); // commission de l'article rendue
    }

    public function test_partner_and_its_couriers_are_notified_when_the_return_is_paid(): void
    {
        $synced = User::factory()->create();
        $code = \App\Models\DelivererSyncCode::create([
            'sync_code' => 'TEST-' . random_int(1000, 9999), 'company_id' => $this->company->id,
            'user_id' => $synced->id, 'is_used' => true,
        ]);
        \App\Models\DelivererCodeSync::create([
            'sync_code_id' => $code->id, 'user_id' => $synced->id, 'company_id' => $this->company->id,
            'is_active' => true, 'is_banned' => false, 'synced_at' => now(),
        ]);
        $notified = [];
        $this->mock(FirebaseMessagingService::class, function ($mock) use (&$notified) {
            $mock->shouldReceive('sendToUser')->andReturnUsing(function (User $user, $title, $body, array $data = []) use (&$notified) {
                $notified[] = [$user->id, $data['type']];

                return [];
            });
        });

        $dispute = $this->openDispute($this->deliveredOrder());
        $service = app(DisputeService::class);
        $service->decide($dispute, $this->admin(), Dispute::DECISION_FOUNDED, 'Défaut confirmé.');
        $shipment = $this->priceShipment($service->startReturn($dispute, 'admin', null));
        $this->setBalance($this->seller, 2500, 2000);
        $service->initiatePayment($shipment, $this->seller, 'wallet');

        $this->assertContains([$this->courier->id, 'dispute_return_course'], $notified);
        $this->assertContains([$synced->id, 'dispute_return_course'], $notified);
        $this->assertContains([$this->client->id, 'dispute_return_courier'], $notified);
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
        // 400 commande + 400 retour, au Wallet du compte interne du partenaire.
        $this->assertSame(0.0, $this->bal($this->courier));
        $this->assertSame(800.0, (\App\Models\DelivererCompany::find($this->company->id)?->account?->kpayBalanceFor('XAF') ?? 0));
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
    private function assoOrder(): Order
    {
        $this->seller = $this->asso;
        $this->shop->update([
            'user_id' => $this->asso->id, 'city' => 'Douala', 'country' => 'Cameroun',
            'address' => 'Akwa, Douala', 'phone' => '237670000001',
        ]);
        $this->setBalance($this->asso, 20000);
        $order = $this->deliveredOrder();
        $order->update(['delivery_city' => 'Yaoundé', 'delivery_country' => 'CM', 'delivery_address' => 'Mvan, Yaoundé']);
        $offer = [
            'company_id' => $this->company->id, 'company_name' => $this->company->name,
            'delivery_price' => 500, 'base_price' => 400, 'asso_commission' => 100,
            'grid_id' => null, 'route_id' => null, 'zone_id' => null, 'vehicle' => null,
            'delivery_option' => 'home_delivery',
        ];
        $this->mock(\App\Services\DeliveryQuoteService::class, function ($mock) use ($offer) {
            $mock->shouldReceive('quotes')->andReturn(['available' => true, 'partners' => [$offer]]);
            $mock->shouldReceive('quoteFor')->andReturnUsing(function (...$args) use ($offer) {
                // Le départ est explicite : retour depuis Yaoundé, remplacement depuis Douala.
                $this->assertContains($args[12]['origin'][0], ['Douala', 'Yaoundé']);
                $this->assertNotSame($args[6], $args[12]['origin'][0]);
                return $offer;
            });
        });
        return $order;
    }

    private function handOver(DisputeShipment $shipment): void
    {
        $service = app(DisputeService::class);
        $service->acceptCourse($shipment->fresh(), $this->courier);
        $service->pickUpCourse($shipment->fresh(), $this->courier);
        $service->completeCourse($shipment->fresh(), $this->courier, $shipment->confirmation_code);
    }

    public static function assoProductKinds(): array
    {
        return ['retail' => [false], 'wholesale' => [true]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('assoProductKinds')]
    public function test_asso_second_complaint_requires_a_decision_then_return_and_refund(bool $wholesale): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $order = $this->assoOrder();
        $order->update(['is_wholesale' => $wholesale, 'delivery_mode' => 'carrier', 'tracking_status' => 'delivered']);
        $admin = $this->admin();
        $dispute = $this->openDispute($order);
        $this->assertTrue($dispute->isAssoManaged());
        $this->assertSame($wholesale, (bool) $order->is_wholesale);
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\DisputeStaffAlertMail::class, fn ($mail) => $mail->hasTo($admin->email));
        $this->actingAs($admin)->post(route('admin.disputes.evidence', $dispute), ['note' => 'Justificatif de la boutique ASSO.'])->assertSessionHas('success');
        $this->assertDatabaseHas('dispute_events', ['dispute_id' => $dispute->id, 'actor_type' => 'vendor', 'type' => 'evidence']);

        $service = app(DisputeService::class);
        $before = $this->bal($this->asso);
        $service->decide($dispute, $admin, Dispute::DECISION_FOUNDED, 'Défaut confirmé.');
        $collection = $dispute->fresh()->shipment(DisputeShipment::TYPE_COLLECTION);
        $this->assertTrue($collection->isPaid(), $collection->funding_error ?? 'Course non payée');
        $this->assertSame($before - 400, $this->bal($this->asso));
        $this->actingAs($admin)->get(route('admin.disputes.show', $dispute))->assertOk()->assertSee($collection->confirmation_code);
        $this->handOver($collection);
        $this->assertSame(0.0, $this->bal($this->client), 'Collecte pour échange sans remboursement');
        $this->assertNull($dispute->fresh()->refunded_at);
        $replacement = $dispute->fresh()->shipment(DisputeShipment::TYPE_REPLACEMENT);
        $this->assertSame('awaiting_preparation', $replacement->status);
        $service->startReplacement($dispute->fresh(), 'admin', $admin->id);
        $this->assertTrue($replacement->fresh()->isPaid());
        $this->handOver($replacement->fresh());
        $this->actingAs($this->client, 'sanctum')->getJson("/api/v1/disputes/{$dispute->id}")
            ->assertJsonPath('dispute.actions.confirm_replacement', true)
            ->assertJsonPath('dispute.actions.report_replacement', true);
        $items = $this->actingAs($this->client, 'sanctum')->getJson("/api/v1/orders/{$order->id}")->json('order.items');
        $item = collect($items)->firstWhere('id', $dispute->order_item_id);
        $this->assertTrue($item['dispute']['can_report_replacement']);

        $this->actingAs($this->client, 'sanctum')->postJson("/api/v1/disputes/{$dispute->id}/report-replacement", ['description' => 'Le remplacement est encore abîmé.'])
            ->assertOk()->assertJsonPath('dispute.status', Dispute::STATUS_NEW)
            ->assertJsonPath('dispute.decision', null);
        $this->assertSame(0.0, $this->bal($this->client));
        $this->assertNull($dispute->fresh()->shipment(DisputeShipment::TYPE_RETURN));
        $service->decide($dispute->fresh(), $admin, Dispute::DECISION_FOUNDED, 'Deuxième défaut confirmé.');
        $this->assertSame(Dispute::STATUS_RETURN, $dispute->fresh()->status);
        $this->assertSame(0.0, $this->bal($this->client));
        $this->assertNull($dispute->fresh()->closed_at);
        $return = $dispute->fresh()->shipment(DisputeShipment::TYPE_RETURN);
        $this->assertTrue($return->isPaid(), $return->funding_error ?? 'Retour non payé');
        $this->assertSame($before - 3 * 400, $this->bal($this->asso));
        $this->handOver($return);
        $this->assertNotNull($dispute->fresh()->closed_at);
        $this->assertSame(Dispute::STATUS_REFUNDED, $dispute->fresh()->status);
        $this->assertSame(1100.0, $this->bal($this->client));
        $this->assertSame($before - 3 * 400 - 1100, $this->bal($this->asso));
        $this->assertSame(1, $dispute->fresh()->replacement_count);
        $this->assertSame(0.0, $service->refund($dispute->fresh()));
    }

    public function test_asso_funding_failure_is_retriable_without_double_debit_or_partner_credit(): void
    {
        $dispute = $this->openDispute($this->assoOrder());
        $this->setBalance($this->asso, 2000, 2000);
        $service = app(DisputeService::class);
        $service->decide($dispute, $this->admin(), Dispute::DECISION_FOUNDED, 'Défaut confirmé.');
        $shipment = $dispute->fresh()->shipment(DisputeShipment::TYPE_COLLECTION);
        $this->assertFalse($shipment->isPaid());
        $this->assertNotEmpty($shipment->funding_error);
        $this->assertCount(0, $service->courierShipments($this->courier));
        $this->setBalance($this->asso, 3000, 2000);
        $service->payByAsso($shipment, $this->admin());
        $service->payByAsso($shipment, $this->admin());
        $this->assertSame(2600.0, $this->bal($this->asso));
        $this->assertSame(1, \App\Models\WalletTransaction::where('user_id', $this->asso->id)->where('reference_type', 'dispute_shipment')->where('reference_id', $shipment->id)->where('type', 'debit')->count());
        $other = User::factory()->create();
        $service->acceptCourse($shipment->fresh(), $this->courier);
        $this->expectException(\Exception::class);
        $service->acceptCourse($shipment->fresh(), $other);
    }

    public function test_disputed_quantity_only_holds_and_refunds_the_selected_units(): void
    {
        $order = $this->deliveredOrder();
        $item = $order->items()->first();
        $item->update(['quantity' => 2]);
        $this->actingAs($this->client, 'sanctum')->postJson("/api/v1/orders/{$order->id}/disputes", [
            'order_item_id' => $item->id, 'quantity' => 3, 'reason' => 'damaged', 'description' => 'Une unité est endommagée.',
        ])->assertStatus(422);
        $this->actingAs($this->client, 'sanctum')->postJson("/api/v1/orders/{$order->id}/disputes", [
            'order_item_id' => $item->id, 'quantity' => 1, 'reason' => 'damaged', 'description' => 'Une unité est endommagée.',
        ])->assertCreated()->assertJsonPath('dispute.product.quantity', 1)->assertJsonPath('dispute.product.total_price', 550);
        $dispute = Dispute::where('order_item_id', $item->id)->firstOrFail();
        $this->assertSame(500.0, (float) $dispute->held_amount);
        $this->assertSame(1500.0, (float) $order->fresh()->vendor_funds_held_amount);
        $service = app(DisputeService::class);
        $service->decide($dispute, $this->admin(), Dispute::DECISION_FOUNDED, 'Défaut confirmé.');
        $service->startReturn($dispute->fresh(), 'admin', null);
        $service->refund($dispute->fresh());
        $this->assertSame(550.0, $this->bal($this->client));
        $sources = collect(app(\App\Services\PlatformRevenueService::class)->summary('30d')['sources']);
        $this->assertEquals(50, $sources['dispute_refunds']['amount']);
    }

    public function test_vendor_deadlines_remind_then_escalate_once_and_expired_replacement_becomes_return(): void
    {
        \App\Models\Setting::set('dispute_response_hours', 1, 'integer', 'disputes');
        $dispute = $this->openDispute($this->deliveredOrder());
        $service = app(DisputeService::class);
        $this->travel(2)->hours();
        $service->processDeadlines();
        $service->processDeadlines();
        $this->assertSame(1, $dispute->events()->where('type', 'vendor_reminder')->count());
        $this->travel(2)->hours();
        $service->processDeadlines();
        $service->processDeadlines();
        $this->assertSame(1, $dispute->events()->where('type', 'vendor_escalation')->count());
        $service->decide($dispute->fresh(), $this->admin(), Dispute::DECISION_FOUNDED, 'Défaut confirmé.');
        $this->travel(49)->hours();
        $service->processDeadlines();
        $this->assertSame(Dispute::STATUS_RETURN, $dispute->fresh()->status);
        $this->assertSame(1, $dispute->shipments()->where('type', 'return')->count());
    }

    public function test_asso_refunds_without_an_extra_course_when_collected_item_cannot_be_replaced(): void
    {
        $dispute = $this->openDispute($this->assoOrder());
        $service = app(DisputeService::class);
        $admin = $this->admin();
        $service->decide($dispute, $admin, Dispute::DECISION_FOUNDED, 'Défaut confirmé.');
        $this->handOver($dispute->fresh()->shipment(DisputeShipment::TYPE_COLLECTION));
        $this->assertNull($service->startReturn($dispute->fresh(), 'admin', $admin->id));
        $this->assertSame(1100.0, $this->bal($this->client));
        $this->assertSame(Dispute::STATUS_REFUNDED, $dispute->fresh()->status);
        $this->assertNotNull($dispute->fresh()->closed_at);
        $this->assertSame(1, $dispute->shipments()->count());
    }

}
