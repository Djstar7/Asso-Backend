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
        $service->startReplacement($dispute->fresh(), 'admin', $admin->id);
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

    public static function productOriginsAndResolutions(): array
    {
        $cases = [];
        foreach (['cameroon_vendor', 'imported_vendor', 'china_wholesale', 'asso_direct'] as $origin) {
            foreach (['replace', 'return'] as $resolution) {
                $cases[$origin.'_'.$resolution] = [$origin, $resolution];
            }
        }
        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('productOriginsAndResolutions')]
    public function test_all_product_origins_offer_both_cases_before_any_shipment_or_debit(string $origin, string $resolution): void
    {
        $assoManaged = in_array($origin, ['china_wholesale', 'asso_direct'], true);
        $order = $assoManaged ? $this->assoOrder() : $this->deliveredOrder();
        if ($origin === 'china_wholesale') {
            $order->update(['is_wholesale' => true, 'delivery_mode' => 'carrier',
                'delivery_breakdown' => ['origin_country' => 'CN', 'destination_country' => 'CM']]);
        }
        foreach ($order->items as $item) {
            $item->product->update(['origin_country' => in_array($origin, ['china_wholesale', 'imported_vendor'], true) ? 'CN' : 'CM',
                'is_wholesale' => $origin === 'china_wholesale']);
        }
        $dispute = $this->openDispute($order);
        $admin = $this->admin();
        $this->assertSame($assoManaged, $dispute->isAssoManaged());
        $before = \App\Models\WalletTransaction::count();
        $this->actingAs($admin)->post(route('admin.disputes.decide', $dispute), [
            'decision' => Dispute::DECISION_FOUNDED, 'note' => 'Non-conformité confirmée.',
        ])->assertSessionHas('success');
        $dispute->refresh();
        $this->assertSame(Dispute::STATUS_VENDOR_CONTACTED, $dispute->status);
        $this->assertNull($dispute->resolution);
        $this->assertSame(0, $dispute->replacement_count);
        $this->assertSame(0, $dispute->shipments()->count());
        $this->assertSame($before, \App\Models\WalletTransaction::count());
        $this->actingAs($this->seller, 'sanctum')->getJson("/api/v1/vendor/disputes/{$dispute->id}")->assertOk()
            ->assertJsonPath('dispute.actions.replace', true)->assertJsonPath('dispute.actions.organize_return', true);
        $this->actingAs($admin)->get(route('admin.disputes.show', $dispute))->assertOk()
            ->assertSee('Remplacer le produit (Cas A)')->assertSee('Retour et remboursement (Cas B)');
        $actions = app(DisputeService::class)->actions($dispute, 'vendor');
        $this->assertTrue($actions['replace']);
        $this->assertTrue($actions['organize_return']);
        $this->post(route('admin.disputes.'.$resolution, $dispute))->assertSessionHas('success');
        $dispute->refresh();
        $this->assertSame($resolution === 'replace' ? Dispute::STATUS_REPLACEMENT : Dispute::STATUS_RETURN, $dispute->status);
        $type = $resolution === 'replace'
            ? DisputeShipment::TYPE_COLLECTION
            : DisputeShipment::TYPE_RETURN;
        $shipment = $dispute->shipment($type);
        $this->assertNotNull($shipment);
        $this->assertSame($assoManaged ? 'asso' : 'vendor', $shipment->payer);
        $this->assertSame($assoManaged, $shipment->isPaid());
        $this->assertSame(1, $dispute->shipments()->count());
    }

    public function test_admin_can_choose_case_a_or_b_for_a_vendor_dispute_and_replacement_stays_vendor_funded(): void
    {
        $dispute = $this->openDispute($this->deliveredOrder());
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.disputes.show', $dispute))->assertOk()
            ->assertDontSee('Remplacer le produit (Cas A)');
        app(DisputeService::class)->decide($dispute, $admin, Dispute::DECISION_FOUNDED, 'Défaut confirmé.');
        $this->get(route('admin.disputes.show', $dispute))->assertOk()
            ->assertSee('Remplacer le produit (Cas A)')->assertSee('Retour et remboursement (Cas B)');
        $this->post(route('admin.disputes.replace', $dispute))->assertRedirect()->assertSessionHas('success');
        $dispute->refresh();
        $this->assertSame(Dispute::STATUS_REPLACEMENT, $dispute->status);
        $this->assertSame(1, $dispute->replacement_count);
        $shipment = $dispute->shipment(DisputeShipment::TYPE_COLLECTION);
        $this->assertNotNull($shipment);
        $this->assertSame('vendor', $shipment->payer);
        $this->assertFalse($shipment->isPaid());
        $this->assertDatabaseHas('dispute_events', ['dispute_id' => $dispute->id,
            'type' => 'replacement', 'actor_type' => 'admin', 'actor_id' => $admin->id]);
        $this->post(route('admin.disputes.replace', $dispute))->assertSessionHas('error');
        $this->assertSame(1, $dispute->shipments()->where('type', DisputeShipment::TYPE_COLLECTION)->count());
        $this->assertSame(0, $dispute->shipments()->where('type', DisputeShipment::TYPE_REPLACEMENT)->count());
        $this->get(route('admin.disputes.show', $dispute))->assertOk()
            ->assertDontSee('Remplacer le produit (Cas A)');
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

        $this->prepareLocalReplacement($dispute);
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
        $service->startReplacement($dispute, 'vendor', $this->seller->id);
        $this->prepareLocalReplacement($dispute);
        $shipment = $this->priceShipment($dispute->fresh()->shipment(DisputeShipment::TYPE_REPLACEMENT));
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
        $this->assertTrue($actions['replace']);
        $this->assertTrue($actions['organize_return']);

        // Le « vendeur » est ASSO : choix explicite du remplacement dans le back-office.
        $service->startReplacement($dispute->fresh(), 'admin', $admin->id);
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
        $service->startReplacement($dispute, 'vendor', $this->seller->id);
        $this->prepareLocalReplacement($dispute);
        $shipment = $this->priceShipment($dispute->fresh()->shipment(DisputeShipment::TYPE_REPLACEMENT));
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
    private function internationalDisputeFixture(): array
    {
        $order = $this->deliveredOrder();
        $this->shop->update(['country' => 'CM', 'city' => 'Douala', 'address' => 'Akwa', 'latitude' => 4.09, 'longitude' => 9.79]);
        foreach ($order->items as $item) $item->product->update(['weight' => '1']);
        $solex = DelivererCompany::create(['name' => 'SOLEX', 'is_active' => true, 'service_type' => 'intercity']);
        $agency = \App\Models\DeliveryAgency::create(['deliverer_company_id' => $solex->id, 'code' => 'SLX-LIT-DLA',
            'name' => 'Solex Douala', 'city' => 'Douala', 'country' => 'CM', 'address' => 'Centre Douala', 'phone' => '237670000001',
            'latitude' => 4.05, 'longitude' => 9.7, 'is_active' => true,
            'collection_pricing' => ['ranges' => [['max' => 100, 'price' => 1000]]]]);
        $this->company->update(['name' => 'DHL Express', 'service_type' => 'international', 'service_mode' => 'door_to_door']);
        $route = \App\Models\DeliveryRoute::create(['deliverer_company_id' => $this->company->id,
            'origin_country' => 'CM', 'destination_country' => 'FR', 'is_active' => true,
            'pricing_data' => ['mode' => 'per_kg', 'price_per_kg' => 8000],
            'export_config' => ['solex_agency_id' => $agency->id, 'coverage_mode' => 'country',
                'return_pricing' => ['mode' => 'per_kg', 'price_per_kg' => 5000]]]);
        $order->update(['delivery_country' => 'FR', 'delivery_city' => 'Tulle', 'delivery_address' => 'Route de la Trémouille, Tulle',
            'delivery_latitude' => 45.267, 'delivery_longitude' => 1.77, 'delivery_mode' => 'carrier',
            'delivery_breakdown' => ['international_export' => true, 'solex_agency' => $agency->toPickupArray(),
                'destination_country' => 'FR', 'destination_city' => 'Tulle', 'company_name' => 'DHL Express']]);
        $localCourier = User::factory()->create();
        $code = \App\Models\DelivererSyncCode::create(['company_id' => $solex->id, 'delivery_agency_id' => $agency->id,
            'sync_code' => \App\Models\DelivererSyncCode::generateSyncCode(), 'expires_at' => now()->addDay()]);
        \App\Models\DelivererCodeSync::create(['sync_code_id' => $code->id, 'company_id' => $solex->id,
            'delivery_agency_id' => $agency->id, 'user_id' => $localCourier->id, 'is_active' => true, 'is_online' => true]);
        $this->setBalance($this->seller, 100000, 2000);
        return [$this->openDispute($order), $localCourier, $route];
    }

    private function payInternationalShipment(DisputeShipment $shipment, float $expectedHt): DisputeShipment
    {
        $service = app(DisputeService::class);
        $quotes = $service->partnerQuotes($shipment);
        $this->assertTrue($quotes['available'], $quotes['message'] ?? 'Pas de devis');
        $offer = $quotes['partners'][0];
        $this->assertEquals(1, $offer['breakdown']['weight_kg']);
        $this->assertEquals($expectedHt, $offer['breakdown']['carrier_price_ht']);
        $shipment = $service->choosePartner($shipment, array_replace($offer, ['delivery_price' => 1]));
        $this->assertEquals($offer['delivery_price'], $shipment->price);
        $before = $this->bal($this->seller);
        $service->initiatePayment($shipment, $this->seller, 'wallet');
        $this->assertTrue($shipment->fresh()->isPaid());
        $this->assertEquals($before - $offer['delivery_price'], $this->bal($this->seller));
        $credits = \App\Models\WalletTransaction::where('metadata->partner_reference', 'dispute_shipment:'.$shipment->id)->get();
        $this->assertCount(2, $credits, 'DHL and Solex receive only their own leg');
        $this->assertEquals($shipment->carrier_amount, $credits->sum('amount'));
        return $shipment->fresh();
    }

    private function deliverInternationalReturnToSeller(DisputeShipment $shipment, User $courier, User $admin): DisputeShipment
    {
        $service = app(DisputeService::class);
        foreach (['pickup_scheduled', 'picked_up', 'international_in_transit', 'customs', 'at_solex'] as $step) {
            $shipment = $service->recordStep($shipment, $step, 'admin', $admin->id, trackingNumber: 'DHL-RETURN-'.$shipment->id);
        }
        $this->assertCount(0, $service->courierShipments($this->courier), 'DHL does not receive the Solex mission');
        $this->assertCount(1, $service->courierShipments($courier));
        $presentation = $service->courseToApi($shipment, $courier);
        $this->assertSame('CM', $presentation['pickup']['country']);
        $this->assertSame('Solex Douala', $presentation['pickup']['name']);
        $this->assertEquals(1000, $presentation['commission']);
        $this->assertFalse($presentation['picked_up'], 'DHL collection is not Solex pickup');
        $service->acceptCourse($shipment, $courier);
        $service->pickUpCourse($shipment->fresh(), $courier);
        $before = $this->bal($this->client);
        $code = $shipment->confirmation_code;
        $wrongCode = $code === '000000' ? '111111' : '000000';
        $this->assertThrows(fn () => $service->completeCourse($shipment->fresh(), $courier, $wrongCode), \Exception::class);
        $this->actingAs($admin)->post(route('admin.disputes.shipments.step', $shipment), ['step' => 'delivered_to_vendor'])->assertSessionHas('error');
        $this->assertSame('solex_collected', $shipment->fresh()->status);
        $this->assertEquals($before, $this->bal($this->client));
        $shipment = $service->completeCourse($shipment->fresh(), $courier, $code);
        $this->assertThrows(fn () => $service->completeCourse($shipment->fresh(), $courier, $code), \Exception::class);
        $this->assertSame('confirmed', $shipment->status);
        $this->assertEquals($shipment->type === DisputeShipment::TYPE_COLLECTION ? 0 : 1100, $this->bal($this->client), 'Code confirms receipt: only a final return refunds');
        return $shipment;
    }

    public function test_international_exchange_code_confirms_receipt_without_refund_then_second_return_code_refunds(): void
    {
        [$dispute, $courier, $route] = $this->internationalDisputeFixture();
        $service = app(DisputeService::class);
        $admin = $this->admin();
        $service->decide($dispute, $admin, Dispute::DECISION_FOUNDED, 'Défaut confirmé');
        $collection = $service->startReplacement($dispute, 'vendor', $this->seller->id);
        $this->assertSame(DisputeShipment::TYPE_COLLECTION, $collection->type);
        $this->actingAs($admin)->post(route('admin.disputes.shipments.step', $collection), ['step' => 'picked_up'])->assertSessionHas('error');
        $collection = $this->payInternationalShipment($collection, 6000);
        $this->assertSame('France → Cameroun', $collection->quote['route_label']);
        $this->assertCount(0, $service->courierShipments($courier), 'Solex waits for arrival from France');
        $this->actingAs($this->seller, 'sanctum')->postJson("/api/v1/vendor/disputes/shipments/{$collection->id}/step", ['step' => 'confirmed'])->assertUnprocessable();
        $collection = $this->deliverInternationalReturnToSeller($collection, $courier, $admin);
        $this->assertFalse($service->actions($dispute->fresh(), 'vendor')['confirm_return_received']);
        $this->assertSame(0.0, $this->bal($this->client));
        $this->actingAs($this->seller, 'sanctum')->postJson("/api/v1/vendor/disputes/shipments/{$collection->id}/step", ['step' => 'confirmed'])->assertUnprocessable();
        $replacement = $dispute->fresh()->shipment(DisputeShipment::TYPE_REPLACEMENT);
        $this->assertSame('awaiting_preparation', $replacement->status);
        $this->assertFalse($service->actions($dispute->fresh(), 'vendor')['pay_shipment']);
        $this->assertTrue($service->actions($dispute->fresh(), 'vendor')['prepare_replacement']);
        $service->startReplacement($dispute->fresh(), 'vendor', $this->seller->id);
        $replacement = $this->payInternationalShipment($replacement->fresh(), 9000);
        $service->acceptCourse($replacement, $courier);
        $service->pickUpCourse($replacement->fresh(), $courier);
        $replacement = $service->completeCourse($replacement->fresh(), $courier, $replacement->confirmation_code);
        $this->assertSame('at_solex', $replacement->status);
        $this->assertCount(0, $service->courierShipments($courier));
        foreach (['handed_to_carrier', 'international_in_transit', 'customs', 'out_for_delivery', 'delivered'] as $step) {
            $replacement = $service->recordStep($replacement, $step, 'admin', $admin->id, trackingNumber: 'DHL-REPLACEMENT');
        }
        $this->assertNull($replacement->toApi('client')['confirmation_code']);
        $this->assertTrue($service->actions($dispute->fresh(), 'client')['report_replacement']);
        $service->reportReplacement($dispute->fresh(), $this->client, 'Remplacement également défectueux');
        $this->assertNull($dispute->fresh()->shipment(DisputeShipment::TYPE_RETURN), 'New issue needs an ASSO decision');
        $service->decide($dispute->fresh(), $admin, Dispute::DECISION_FOUNDED, 'Second défaut confirmé');
        $this->assertThrows(fn () => $service->refund($dispute->fresh()), \Exception::class, __('disputes.vendor_receipt_required'));
        $return = $this->payInternationalShipment($dispute->fresh()->shipment(DisputeShipment::TYPE_RETURN), 6000);
        $return = $this->deliverInternationalReturnToSeller($return, $courier, $admin);
        $this->actingAs($this->seller, 'sanctum')->postJson("/api/v1/vendor/disputes/shipments/{$return->id}/step", ['step' => 'confirmed'])->assertUnprocessable();
        $this->assertSame(1100.0, $this->bal($this->client));
        $this->assertSame(Dispute::STATUS_REFUNDED, $dispute->fresh()->status);
        $this->assertSame(0.0, $service->refund($dispute->fresh()));
        $this->postJson("/api/v1/vendor/disputes/shipments/{$return->id}/step", ['step' => 'confirmed'])->assertUnprocessable();
        $this->assertSame(1100.0, $this->bal($this->client));
        $this->assertSame(1, $dispute->fresh()->replacement_count);
    }

    public function test_international_case_b_uses_reverse_rates_and_refunds_immediately_on_seller_code(): void
    {
        [$dispute, $courier, $route] = $this->internationalDisputeFixture();
        $service = app(DisputeService::class);
        $admin = $this->admin();
        $service->decide($dispute, $admin, Dispute::DECISION_FOUNDED, 'Défaut confirmé');
        $return = $service->startReturn($dispute, 'vendor', $this->seller->id);
        $config = $route->export_config;
        unset($config['return_pricing']);
        $route->update(['export_config' => $config]);
        $this->assertFalse($service->partnerQuotes($return)['available'], 'A real return tariff is required outside provisional mode');
        $config['provisional_rates'] = true;
        $route->update(['export_config' => $config]);
        $this->assertEquals(9000, $service->partnerQuotes($return)['partners'][0]['breakdown']['carrier_price_ht']);
        $config['provisional_rates'] = false;
        $config['return_pricing'] = $route->pricing_data;
        $config['return_pricing_provisional'] = true;
        $route->update(['export_config' => $config]);
        $this->assertFalse($service->partnerQuotes($return)['available'], 'An unconfirmed seeded return rate must not become a real tariff');
        unset($config['return_pricing_provisional']);
        $config['return_pricing'] = ['mode' => 'per_kg', 'price_per_kg' => 5000];
        $route->update(['export_config' => $config]);
        $return = $this->payInternationalShipment($return, 6000);
        $paidPrice = $return->price;
        $config['return_pricing']['price_per_kg'] = 90000;
        $route->update(['export_config' => $config]);
        $this->assertSame($paidPrice, $return->fresh()->price);
        $this->assertThrows(fn () => $service->initiatePayment($return->fresh(), $this->seller, 'wallet'), \Exception::class);
        $return = $this->deliverInternationalReturnToSeller($return, $courier, $admin);
        $this->assertSame(0.0, $service->refund($dispute->fresh()));
        $this->actingAs($admin)->post(route('admin.disputes.shipments.step', $return), ['step' => 'confirmed'])->assertSessionHas('error');
        $this->actingAs(User::factory()->create(), 'sanctum')->postJson("/api/v1/vendor/disputes/shipments/{$return->id}/step", ['step' => 'confirmed'])->assertNotFound();
        $this->actingAs($this->seller, 'sanctum')->postJson("/api/v1/vendor/disputes/shipments/{$return->id}/step", ['step' => 'confirmed'])->assertUnprocessable();
        $this->assertSame(Dispute::STATUS_REFUNDED, $dispute->fresh()->status);
        $this->assertSame(1100.0, $this->bal($this->client));
    }

    public function test_migration_corrects_only_untouched_international_replacement_drafts(): void
    {
        [$dispute] = $this->internationalDisputeFixture();
        $stops = ['pickup' => ['role' => 'vendor', 'country' => 'CM'], 'dropoff' => ['role' => 'client', 'country' => 'FR']];
        $draft = $dispute->shipments()->create(['type' => 'replacement', 'payment_status' => 'pending', 'status' => 'awaiting_payment', 'stops' => $stops]);
        $paid = $dispute->shipments()->create(['type' => 'replacement', 'payment_status' => 'paid', 'status' => 'courier_selected', 'stops' => $stops]);
        $migration = require database_path('migrations/2026_10_24_180000_normalize_unpaid_international_dispute_collections.php');
        $migration->up();
        $this->assertSame('collection', $draft->fresh()->type);
        $this->assertSame('FR', $draft->fresh()->stops['pickup']['country']);
        $this->assertSame('replacement', $paid->fresh()->type);
        $migration->up();
        $this->assertSame(2, $dispute->shipments()->count());
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

    private function prepareLocalReplacement(Dispute $dispute): void
    {
        $service = app(DisputeService::class);
        $collection = $this->priceShipment($dispute->fresh()->shipment(DisputeShipment::TYPE_COLLECTION));
        $this->setBalance($this->seller, 3000, 2000);
        $service->initiatePayment($collection, $this->seller, 'wallet');
        $this->handOver($collection);
        $this->assertSame(0.0, $this->bal($this->client));
        $service->startReplacement($dispute->fresh(), 'vendor', $this->seller->id);
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
        $service->startReplacement($dispute->fresh(), 'admin', $admin->id);
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
        $admin = $this->admin();
        $service->decide($dispute, $admin, Dispute::DECISION_FOUNDED, 'Défaut confirmé.');
        $service->startReplacement($dispute->fresh(), 'admin', $admin->id);
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
        $service->startReplacement($dispute->fresh(), 'admin', $admin->id);
        $this->handOver($dispute->fresh()->shipment(DisputeShipment::TYPE_COLLECTION));
        $this->assertNull($service->startReturn($dispute->fresh(), 'admin', $admin->id));
        $this->assertSame(1100.0, $this->bal($this->client));
        $this->assertSame(Dispute::STATUS_REFUNDED, $dispute->fresh()->status);
        $this->assertNotNull($dispute->fresh()->closed_at);
        $this->assertSame(1, $dispute->shipments()->count());
    }

    private function proof(Dispute $dispute, string $author = 'admin'): \App\Models\DisputeAttachment
    {
        return $dispute->attachments()->create(['author_type' => $author,
            'author_id' => $author === 'vendor' ? $this->seller->id : $this->admin()->id, 'path' => 'verified-return.jpg']);
    }

    public static function refundOrigins(): array
    {
        return ['local' => ['local'], 'import' => ['import'], 'asso' => ['asso'], 'export' => ['export']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refundOrigins')]
    public function test_documented_arbitration_refunds_once_for_every_origin(string $origin): void
    {
        $order = $origin === 'asso' || $origin === 'import' ? $this->assoOrder() : $this->deliveredOrder();
        if ($origin === 'import') $order->update(['is_wholesale' => true]);
        if ($origin === 'export') $order->update(['delivery_breakdown' => ['international_export' => true]]);
        $dispute = $this->openDispute($order);
        $piece = $this->proof($dispute);
        $admin = $this->admin();
        $resolution = app(\App\Services\DisputeResolutionService::class);
        $this->assertSame(1100.0, $resolution->refund($dispute, $admin, $piece->id, 'Refus du vendeur vérifié sur les justificatifs.'));
        $this->assertSame(0.0, $resolution->refund($dispute, $admin, $piece->id, 'Deuxième appel du même arbitrage documenté.'));
        $this->assertSame(1100.0, $this->bal($this->client));
        $this->assertSame(Dispute::STATUS_REFUNDED, $dispute->fresh()->status);
        $this->assertSame(1, $dispute->events()->where('type', 'administrative_refund')->count());
        $this->actingAs($admin)->get(route('admin.disputes.show', $dispute))->assertOk();
    }

    public function test_arbitration_requires_admin_and_evidence_from_this_dispute(): void
    {
        $dispute = $this->openDispute($this->deliveredOrder());
        $piece = $this->proof($dispute);
        $route = route('admin.disputes.resolution.refund', $dispute);
        $manager = User::factory()->create(['role' => 'product_manager', 'roles' => ['product_manager'], 'admin_permissions' => ['disputes']]);
        $this->actingAs($this->admin())->post($route, ['note' => 'Pièce absente du dossier de litige.'])->assertSessionHasErrors('attachment_id');
        $other = $this->openDispute($this->deliveredOrder());
        $otherPiece = $this->proof($other);
        $this->actingAs($this->admin(), 'web')->post($route, ['attachment_id' => $otherPiece->id, 'note' => 'Une pièce provenant d’un autre dossier.'])->assertNotFound();
        $this->assertNull($dispute->fresh()->refunded_at);
        $this->actingAs($manager, 'web')->get(route('admin.disputes.resolution.index'))->assertForbidden();
    }

    private function fundedReturn(bool $collection = false): array
    {
        $dispute = $this->openDispute($this->deliveredOrder());
        $service = app(DisputeService::class);
        $admin = $this->admin();
        $service->decide($dispute, $admin, Dispute::DECISION_FOUNDED, 'Défaut confirmé par ASSO.');
        $shipment = $collection ? $service->startReplacement($dispute->fresh(), 'vendor', $this->seller->id)
            : $service->startReturn($dispute->fresh(), 'admin', $admin->id);
        $shipment = $this->priceShipment($shipment);
        $this->setBalance($this->seller, 3000, 2000);
        $service->initiatePayment($shipment, $this->seller, 'wallet');
        return [$dispute->fresh(), $shipment->fresh(), $admin];
    }

    public function test_independent_receipt_expires_without_seller_code_and_refunds_once(): void
    {
        [$dispute, $shipment, $admin] = $this->fundedReturn();
        $resolution = app(\App\Services\DisputeResolutionService::class);
        $receipt = $resolution->registerReceipt($shipment, $admin, $this->proof($dispute)->id, 'Agence ASSO Douala', 'Produit identifié et reçu en agence.');
        $resolution->process();
        $this->assertNull($dispute->fresh()->refunded_at);
        $this->travel(49)->hours();
        $resolution->process();
        $resolution->process();
        $this->assertSame(1100.0, $this->bal($this->client));
        $this->assertNotNull($receipt->fresh()->accepted_at);
        $this->assertSame('confirmed', $shipment->fresh()->status);
    }

    public function test_independent_collection_receipt_prepares_exchange_without_refund(): void
    {
        [$dispute, $shipment, $admin] = $this->fundedReturn(true);
        $resolution = app(\App\Services\DisputeResolutionService::class);
        $receipt = $resolution->registerReceipt($shipment, $admin, $this->proof($dispute)->id, 'Agence Solex', 'Produit défectueux conservé en agence.');
        $this->travel(49)->hours();
        $resolution->process();
        $this->assertSame(0.0, $this->bal($this->client));
        $this->assertNotNull($receipt->fresh()->accepted_at);
        $this->assertSame('awaiting_preparation', $dispute->fresh()->shipment('replacement')->status);
        $this->assertSame(1, $dispute->shipments()->where('type', 'replacement')->count());
    }

    public function test_contested_receipt_requires_admin_arbitration(): void
    {
        [$dispute, $shipment, $admin] = $this->fundedReturn();
        $resolution = app(\App\Services\DisputeResolutionService::class);
        $receipt = $resolution->registerReceipt($shipment, $admin, $this->proof($dispute)->id, 'Agence Solex', 'Retour reçu et référence produit contrôlée.');
        $proof = $this->proof($dispute, 'vendor');
        $this->actingAs($this->seller, 'sanctum')->postJson('/api/v1/vendor/disputes/receipts/'.$receipt->id.'/contest',
            ['attachment_id' => $proof->id, 'note' => 'Le numéro de série du produit est différent.'])->assertOk();
        $this->travel(49)->hours();
        $resolution->process();
        $this->assertNull($receipt->fresh()->accepted_at);
        $this->assertSame(0.0, $this->bal($this->client));
        $resolution->acceptReceipt($receipt->fresh(), $admin, 'Numéro de série vérifié et contestation examinée.');
        $this->assertSame(1100.0, $this->bal($this->client));
    }

    public function test_transport_advance_is_accounted_once_without_seller_balance(): void
    {
        $dispute = $this->openDispute($this->deliveredOrder());
        $admin = $this->admin();
        $service = app(DisputeService::class);
        $service->decide($dispute, $admin, Dispute::DECISION_FOUNDED, 'Produit défectueux vérifié.');
        $shipment = $this->priceShipment($service->startReturn($dispute->fresh(), 'admin', $admin->id));
        $before = $this->bal($this->seller);
        $this->setBalance($this->asso, 1000);
        $resolution = app(\App\Services\DisputeResolutionService::class);
        $resolution->advanceTransport($shipment, $admin, 'Avance autorisée pour débloquer le retour.');
        $resolution->advanceTransport($shipment, $admin, 'Reprise du même financement de secours.');
        $this->assertSame(600.0, $this->bal($this->asso));
        $this->assertSame($before, $this->bal($this->seller));
        $this->assertDatabaseCount('dispute_advances', 1);
        $this->assertDatabaseHas('dispute_advances', ['dispute_shipment_id' => $shipment->id, 'debtor_id' => $this->seller->id, 'amount' => 400]);
        $this->assertTrue($shipment->fresh()->isPaid());
    }

    public function test_failed_advance_rolls_back_all_financial_state(): void
    {
        $dispute = $this->openDispute($this->deliveredOrder());
        $admin = $this->admin();
        $service = app(DisputeService::class);
        $service->decide($dispute, $admin, Dispute::DECISION_FOUNDED, 'Défaut confirmé par ASSO.');
        $shipment = $this->priceShipment($service->startReturn($dispute->fresh(), 'admin', $admin->id));
        $this->setBalance($this->asso, 0);
        try { app(\App\Services\DisputeResolutionService::class)->advanceTransport($shipment, $admin, 'Avance avec trésorerie insuffisante.'); $this->fail('Should reject insufficient funds'); }
        catch (\Exception $e) { $this->assertNotEmpty($e->getMessage()); }
        $this->assertSame('vendor', $shipment->fresh()->payer);
        $this->assertFalse($shipment->fresh()->isPaid());
        $this->assertDatabaseCount('dispute_advances', 0);
    }

    public function test_resolution_deadlines_reassign_without_blind_refund_or_duplicate_escalation(): void
    {
        $dispute = $this->openDispute($this->deliveredOrder());
        $this->admin(); $this->admin();
        $resolution = app(\App\Services\DisputeResolutionService::class);
        $resolution->process();
        $this->travel(49)->hours();
        $resolution->process();
        $task = \App\Models\DisputeResolutionTask::where('dispute_id', $dispute->id)->whereNull('completed_at')->firstOrFail();
        $this->assertSame(1, $task->escalations);
        $first = $task->assigned_to;
        $resolution->process();
        $this->assertSame(1, $task->fresh()->escalations);
        $this->travel(49)->hours();
        $resolution->process();
        $this->assertSame(2, $task->fresh()->escalations);
        $this->assertNotEquals($first, $task->fresh()->assigned_to);
        $this->assertNull($dispute->fresh()->refunded_at);
        $this->actingAs($this->admin())->get(route('admin.disputes.resolution.index'))->assertOk()->assertSee($dispute->number);
    }

    public function test_late_transport_payment_after_arbitration_is_returned_to_payer_once(): void
    {
        $dispute = $this->openDispute($this->deliveredOrder());
        $admin = $this->admin();
        $service = app(DisputeService::class);
        $service->decide($dispute, $admin, Dispute::DECISION_FOUNDED, 'Défaut reconnu par ASSO.');
        $shipment = $this->priceShipment($service->startReturn($dispute->fresh(), 'admin', $admin->id));
        $shipment->update(['payment_mode' => 'kpay_direct', 'payment_reference' => 'LATE-RETURN']);
        $resolution = app(\App\Services\DisputeResolutionService::class);
        $resolution->refund($dispute, $admin, $this->proof($dispute)->id, 'Refus de retour documenté par le transporteur.');
        $before = $this->bal($this->seller);
        $service->confirmPayment($shipment);
        $service->confirmPayment($shipment);
        $this->assertSame($before + 500, $this->bal($this->seller));
        $this->assertSame('cancelled', $shipment->fresh()->status);
        $this->assertFalse($shipment->fresh()->isOpenForCourier());
    }

    private function undeliveredOrder(bool $settled = false): Order
    {
        $order = $this->deliveredOrder();
        $order->update(['status' => 'confirmed', 'delivered_at' => null, 'auto_validate_at' => null]);
        if (!$settled) {
            // Fixture d’un acompte direct non encore réparti aux bénéficiaires.
            $order->update(['settled_at' => null, 'vendor_funds_status' => null, 'vendor_funds_holder_id' => null, 'vendor_funds_held_amount' => 0,
                'total' => 2700, 'payment_method' => 'kpay_direct']);
        }
        return $order->fresh();
    }

    public function test_undelivered_settled_order_refunds_collected_total_once_and_preserves_carrier_recours(): void
    {
        $order = $this->undeliveredOrder(true);
        $resolution = app(\App\Services\DisputeResolutionService::class);
        $claim = $resolution->openDeliveryClaim($order, $this->client, 'lost', 'Le transporteur confirme la perte du colis.');
        $this->setBalance($this->asso, 1000);
        $admin = $this->admin();
        $this->assertSame(2700.0, $resolution->resolveDeliveryClaim($claim, $admin, 'refund', 'Perte vérifiée après enquête transporteur.', 'claims/carrier-proof.pdf'));
        $this->assertSame(0.0, $resolution->resolveDeliveryClaim($claim, $admin, 'refund', 'Même décision répétée par le responsable.', 'claims/carrier-proof.pdf'));
        $this->assertSame(2700.0, $this->bal($this->client));
        $this->assertSame(0.0, $this->locked($this->seller));
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertDatabaseHas('dispute_advances', ['order_delivery_claim_id' => $claim->id, 'amount' => 700]);
    }

    public function test_paid_undelivered_claim_api_checks_ownership_and_delivery_state(): void
    {
        $order = $this->undeliveredOrder();
        $route = '/api/v1/orders/'.$order->id.'/delivery-claim';
        $this->actingAs($this->seller, 'sanctum')->postJson($route, ['reason' => 'not_received', 'description' => 'Commande jamais reçue par le client.'])->assertNotFound();
        $this->actingAs($this->client, 'sanctum')->postJson($route, ['reason' => 'not_received', 'description' => 'Commande jamais reçue par le client.'])->assertCreated();
        $this->postJson($route, ['reason' => 'not_received', 'description' => 'Commande jamais reçue par le client.'])->assertCreated();
        $this->assertDatabaseCount('order_delivery_claims', 1);
        $order->update(['status' => 'delivered']);
        $this->postJson($route, ['reason' => 'not_received', 'description' => 'Commande jamais reçue par le client.'])->assertUnprocessable();
    }

    public function test_supervisor_detects_paid_orders_that_never_ship_without_refunding_blindly(): void
    {
        $order = $this->undeliveredOrder();
        $order->forceFill(['created_at' => now()->subDays(5), 'confirmed_at' => now()->subDays(5)])->save();
        app(\App\Services\DisputeResolutionService::class)->process();
        app(\App\Services\DisputeResolutionService::class)->process();
        $this->assertDatabaseCount('order_delivery_claims', 1);
        $this->assertDatabaseCount('dispute_resolution_tasks', 1);
        $this->assertNull($order->fresh()->refunded_at);
    }

    public function test_import_deposit_refunds_only_collected_money_and_unlocks_mixed_wallet_balance(): void
    {
        $resolution = app(\App\Services\DisputeResolutionService::class);
        $admin = $this->admin();
        $order = Order::create(['user_id' => $this->client->id, 'status' => 'pending', 'subtotal' => 2200,
            'delivery_fee' => 500, 'total' => 2700, 'payment_status' => 'paid', 'payment_method' => 'kpay_direct',
            'payment_plan' => 'deposit', 'deposit_amount' => 900, 'balance_amount' => 1800,
            'balance_status' => 'locked', 'is_wholesale' => true, 'delivery_address' => 'Douala']);
        $claim = $resolution->openDeliveryClaim($order, $this->client, 'not_shipped', 'Fournisseur chinois sans preuve d’expédition.');
        $this->assertSame(900.0, $resolution->resolveDeliveryClaim($claim, $admin, 'refund', 'Absence d’expédition vérifiée par ASSO.', 'proof.pdf'));
        $this->assertSame(900.0, $this->bal($this->client));

        $mixed = Order::create(['user_id' => $this->client->id, 'status' => 'confirmed', 'subtotal' => 2200,
            'delivery_fee' => 500, 'total' => 2700, 'payment_status' => 'paid', 'payment_method' => 'kpay_direct',
            'payment_plan' => 'deposit', 'deposit_amount' => 900, 'balance_amount' => 1800,
            'balance_status' => 'paid', 'balance_payment_method' => 'wallet_kpay', 'delivery_address' => 'Douala']);
        $this->setBalance($this->client, 1800, 1800);
        $claim = $resolution->openDeliveryClaim($mixed, $this->client, 'not_received', 'Colis non reçu après paiement des deux tranches.');
        $this->assertSame(2700.0, $resolution->resolveDeliveryClaim($claim, $admin, 'refund', 'Perte confirmée par justificatif transporteur.', 'proof.pdf'));
        $this->assertSame(2700.0, $this->bal($this->client));
        $this->assertSame(0.0, $this->locked($this->client));
    }

    public function test_delivery_claim_cannot_refund_an_order_delivered_during_investigation(): void
    {
        $order = $this->undeliveredOrder(true);
        $resolution = app(\App\Services\DisputeResolutionService::class);
        $claim = $resolution->openDeliveryClaim($order, $this->client, 'not_received', 'Le colis semble perdu depuis plusieurs jours.');
        $order->update(['status' => 'delivered', 'delivered_at' => now()]);
        $this->expectException(\DomainException::class);
        $resolution->resolveDeliveryClaim($claim, $this->admin(), 'refund', 'Décision sur une situation devenue obsolète.', 'proof.pdf');
    }

    public function test_stale_admin_actions_and_courier_steps_cannot_reopen_an_arbitrated_dispute(): void
    {
        [$dispute, $shipment, $admin] = $this->fundedReturn();
        $stale = $dispute->fresh();
        $stale->status = Dispute::STATUS_NEW;
        $stale->decision = null;
        app(\App\Services\DisputeResolutionService::class)->refund($dispute, $admin, $this->proof($dispute)->id, 'Refus de réception établi et remboursement autorisé.');
        app(DisputeService::class)->markInReview($stale, $admin);
        $this->assertSame(Dispute::STATUS_REFUNDED, $dispute->fresh()->status);
        $this->assertSame([], $shipment->fresh()->manualSteps());
        $this->assertThrows(fn () => app(DisputeService::class)->contactVendor($stale, $admin, 'Relance obsolète'), \Exception::class);
        $this->assertThrows(fn () => app(DisputeService::class)->recordStep($shipment->fresh(), 'pickup_scheduled', 'admin', $admin->id), \Exception::class);
        $this->assertSame(1100.0, $this->bal($this->client));
    }

    public function test_automatic_advance_respects_configured_ceiling_and_is_idempotent(): void
    {
        $dispute = $this->openDispute($this->deliveredOrder());
        $admin = $this->admin();
        $service = app(DisputeService::class);
        $service->decide($dispute, $admin, Dispute::DECISION_FOUNDED, 'Défaut confirmé par ASSO.');
        $shipment = $this->priceShipment($service->startReturn($dispute->fresh(), 'admin', $admin->id));
        $this->setBalance($this->asso, 1000);
        \App\Models\Setting::set('dispute_automatic_advance_limit', 399, 'integer', 'disputes');
        $resolution = app(\App\Services\DisputeResolutionService::class);
        $resolution->process();
        $this->travel(49)->hours();
        $resolution->process();
        $this->assertFalse($shipment->fresh()->isPaid());
        $this->assertDatabaseCount('dispute_advances', 0);
        \App\Models\Setting::set('dispute_automatic_advance_limit', 400, 'integer', 'disputes');
        $this->travel(49)->hours();
        $resolution->process();
        $resolution->process();
        $this->assertTrue($shipment->fresh()->isPaid());
        $this->assertDatabaseCount('dispute_advances', 1);
        $this->assertSame(600.0, $this->bal($this->asso));
    }

    public function test_pending_external_payment_prevents_an_advance(): void
    {
        $dispute = $this->openDispute($this->deliveredOrder());
        $admin = $this->admin();
        $service = app(DisputeService::class);
        $service->decide($dispute, $admin, Dispute::DECISION_FOUNDED, 'Défaut confirmé par ASSO.');
        $shipment = $this->priceShipment($service->startReturn($dispute->fresh(), 'admin', $admin->id));
        $shipment->update(['payment_mode' => 'kpay_direct', 'payment_reference' => 'PENDING-EXTERNAL']);
        $this->setBalance($this->asso, 1000);
        $this->assertThrows(fn () => app(\App\Services\DisputeResolutionService::class)->advanceTransport($shipment, $admin, 'Avance pendant une opération externe en cours.'), \DomainException::class);
        $this->assertSame(1000.0, $this->bal($this->asso));
        $this->assertSame('vendor', $shipment->fresh()->payer);
        $this->assertDatabaseCount('dispute_advances', 0);
    }

    public function test_international_return_can_be_received_at_authorized_agency_without_vendor_code(): void
    {
        [$dispute] = $this->internationalDisputeFixture();
        $admin = $this->admin();
        $service = app(DisputeService::class);
        $service->decide($dispute, $admin, Dispute::DECISION_FOUNDED, 'Défaut confirmé après examen du dossier.');
        $shipment = $this->payInternationalShipment($service->startReturn($dispute->fresh(), 'admin', $admin->id), 6000);
        $resolution = app(\App\Services\DisputeResolutionService::class);
        $receipt = $resolution->registerReceipt($shipment, $admin, $this->proof($dispute)->id, 'Solex Douala', 'Retour international contrôlé et conservé en agence.');
        $this->travel(49)->hours();
        $resolution->process();
        $this->assertNotNull($receipt->fresh()->accepted_at);
        $this->assertSame('confirmed', $shipment->fresh()->status);
        $this->assertSame(1100.0, $this->bal($this->client));
    }

    public function test_delivery_claim_proofs_are_private_and_admin_settings_use_supported_storage_types(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $order = $this->undeliveredOrder();
        $this->actingAs($this->client, 'sanctum')->post('/api/v1/orders/'.$order->id.'/delivery-claim', [
            'reason' => 'not_received', 'description' => 'Livraison absente, justificatif de suivi joint.',
            'photos' => [\Illuminate\Http\UploadedFile::fake()->image('tracking.jpg')],
        ], ['Accept' => 'application/json'])->assertCreated();
        $claim = \App\Models\OrderDeliveryClaim::where('order_id', $order->id)->firstOrFail();
        $this->assertCount(1, $claim->client_proofs);
        $this->actingAs($this->admin(), 'web')->get(route('admin.disputes.resolution.proof', ['claim' => $claim, 'index' => 0]))->assertOk();
        $values = array_fill_keys(['review_hours', 'payment_hours', 'receipt_contest_hours', 'local_transit_hours',
            'international_transit_hours', 'dispatch_hours', 'preparation_hours', 'import_preparation_hours'], 48);
        $this->post(route('admin.disputes.resolution.settings'), $values + ['automatic_advance_limit' => '5000.50'])->assertSessionHas('success');
        $this->assertDatabaseHas('settings', ['key' => 'dispute_automatic_advance_limit', 'type' => 'string', 'value' => '5000.50']);
    }

}
