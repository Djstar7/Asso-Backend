<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DelivererCompany;
use App\Models\Dispute;
use App\Models\Division;
use App\Models\DivisionCommission;
use App\Models\DivisionHead;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\WalletBalance;
use App\Services\DivisionCommissionService;
use App\Services\FirebaseMessagingService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Commission d'un chef de rubrique : % de la commission ASSO de la ligne, figé à
 * la vente, versée sur son Wallet quand la vente est acquise, annulée ou reprise
 * si elle est remboursée.
 */
class DivisionCommissionTest extends TestCase
{
    use RefreshDatabase;

    private Category $electronics;

    private Category $fashion;

    private Division $division;

    private User $chief;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(FirebaseMessagingService::class, fn ($mock) => $mock->shouldReceive('sendToUser')->andReturn([]));

        User::factory()->create(['email' => 'admin@asso.com', 'role' => 'admin']);
        $this->electronics = Category::create(['name' => 'Électronique', 'slug' => 'elec']);
        $this->fashion = Category::create(['name' => 'Mode', 'slug' => 'mode']);
        $this->division = Division::create(['name' => 'Électronique', 'type' => Division::TYPE_CATEGORY]);
        $this->division->categories()->attach($this->electronics->id);
        $this->chief = User::factory()->create(['role' => 'division_head', 'roles' => ['division_head']]);
        $this->appoint($this->chief, 20, now()->subMonth());
    }

    private function appoint(User $user, float $rate, $start): DivisionHead
    {
        return DivisionHead::create(['division_id' => $this->division->id, 'user_id' => $user->id,
            'commission_rate' => $rate, 'started_at' => $start]);
    }

    private function balance(User $user): float
    {
        return $user->fresh()->kpayAvailableFor('XAF');
    }

    /**
     * Commande payée : téléphone (vendeur 100 000, acheteur 110 000) + chemise
     * (vendeur 10 000, acheteur 11 000). Commission ASSO : 10 000 + 1 000.
     */
    private function paidOrder(?\DateTimeInterface $createdAt = null): array
    {
        $client = User::factory()->create();
        $seller = User::factory()->create(['role' => 'vendeur']);
        foreach ([$client, $seller] as $u) {
            WalletBalance::updateOrCreate(['user_id' => $u->id, 'currency' => 'XAF'], ['balance' => 0, 'locked_balance' => 0]);
        }
        $company = DelivererCompany::create(['user_id' => User::factory()->create()->id, 'name' => 'Livreur', 'is_active' => true]);
        $phone = Product::create(['user_id' => $seller->id, 'category_id' => $this->electronics->id, 'name' => 'Téléphone', 'price' => 100000, 'stock' => 5, 'status' => 'active']);
        $shirt = Product::create(['user_id' => $seller->id, 'category_id' => $this->fashion->id, 'name' => 'Chemise', 'price' => 10000, 'stock' => 5, 'status' => 'active']);

        $order = Order::create([
            'user_id' => $client->id, 'status' => 'pending', 'subtotal' => 121000, 'delivery_fee' => 0,
            'base_delivery_price' => 0, 'delivery_commission' => 0, 'total' => 121000,
            'delivery_company_id' => $company->id, 'payment_method' => 'kpay_direct', 'payment_status' => 'paid',
            'sale_commission_rate' => 10, 'sale_commission' => 11000, 'vendor_net_amount' => 110000,
        ]);
        if ($createdAt) {
            $order->forceFill(['created_at' => $createdAt])->saveQuietly();
        }
        $phoneLine = $order->items()->create(['product_id' => $phone->id, 'seller_id' => $seller->id, 'quantity' => 1,
            'unit_price' => 110000, 'total_price' => 110000, 'seller_unit_price' => 100000, 'seller_total_price' => 100000, 'commission_rate' => 10]);
        $shirtLine = $order->items()->create(['product_id' => $shirt->id, 'seller_id' => $seller->id, 'quantity' => 1,
            'unit_price' => 11000, 'total_price' => 11000, 'seller_unit_price' => 10000, 'seller_total_price' => 10000, 'commission_rate' => 10]);

        return compact('client', 'seller', 'order', 'phoneLine', 'shirtLine');
    }

    private function validate(User $seller, Order $order): void
    {
        $this->actingAs($seller, 'sanctum')->postJson("/api/v1/vendor/orders/{$order->id}/validate")->assertOk();
    }

    public function test_settlement_records_a_pending_share_of_the_asso_commission_only_for_the_division(): void
    {
        ['seller' => $seller, 'order' => $order, 'phoneLine' => $phoneLine] = $this->paidOrder();

        $this->validate($seller, $order);

        $commission = DivisionCommission::sole();
        $this->assertSame($phoneLine->id, $commission->source_id);
        // 20 % de la commission ASSO (10 000), pas du prix payé (110 000).
        $this->assertEquals(10000, (float) $commission->base_amount);
        $this->assertEquals(2000, (float) $commission->amount);
        $this->assertSame(DivisionCommission::STATUS_PENDING, $commission->status);
        $this->assertEquals(0, $this->balance($this->chief));
    }

    public function test_share_is_paid_to_the_wallet_when_vendor_funds_are_released(): void
    {
        ['seller' => $seller, 'order' => $order] = $this->paidOrder();
        $this->validate($seller, $order);
        $order->refresh()->update(['status' => 'delivered', 'delivered_at' => now()]);

        app(OrderService::class)->releaseVendorFunds($order->fresh(), 'auto');

        $commission = DivisionCommission::sole();
        $this->assertSame(DivisionCommission::STATUS_AVAILABLE, $commission->status);
        $this->assertNotNull($commission->wallet_transaction_id);
        $this->assertEquals(2000, $this->balance($this->chief));
        // Déduite des Revenus ASSO.
        $this->assertEquals(2000, app(\App\Services\PlatformRevenueService::class)->sourceTotal('division_commissions', 'all')['amount']);

        // Rejouer ne verse rien de plus.
        app(DivisionCommissionService::class)->sync();
        app(DivisionCommissionService::class)->reconcileOrder($order->id);
        $this->assertEquals(2000, $this->balance($this->chief));
        $this->assertSame(1, DivisionCommission::count());
    }

    public function test_open_dispute_keeps_the_share_pending_and_a_refund_cancels_it(): void
    {
        ['seller' => $seller, 'client' => $client, 'order' => $order, 'phoneLine' => $line] = $this->paidOrder();
        $this->validate($seller, $order);

        $dispute = Dispute::create(['order_id' => $order->id, 'order_item_id' => $line->id, 'product_id' => $line->product_id,
            'client_id' => $client->id, 'seller_id' => $seller->id, 'reason' => 'defective', 'description' => 'Ne s allume pas',
            'status' => Dispute::STATUS_NEW]);
        $order->refresh()->update(['vendor_funds_status' => Order::VENDOR_FUNDS_RELEASED]);

        $this->assertSame(DivisionCommission::STATUS_PENDING, DivisionCommission::sole()->status);

        $dispute->update(['status' => Dispute::STATUS_REFUNDED, 'refunded_at' => now(), 'refund_amount' => 110000]);

        $this->assertSame(DivisionCommission::STATUS_CANCELLED, DivisionCommission::sole()->status);
        $this->assertEquals(0, $this->balance($this->chief));
    }

    public function test_refund_after_payment_claws_the_share_back(): void
    {
        ['seller' => $seller, 'client' => $client, 'order' => $order, 'phoneLine' => $line] = $this->paidOrder();
        $this->validate($seller, $order);
        $order->refresh()->update(['vendor_funds_status' => Order::VENDOR_FUNDS_RELEASED]);
        $this->assertEquals(2000, $this->balance($this->chief));

        // Remboursement tardif de la moitié de la ligne.
        Dispute::create(['order_id' => $order->id, 'order_item_id' => $line->id, 'client_id' => $client->id,
            'seller_id' => $seller->id, 'reason' => 'defective', 'description' => 'Panne après coup',
            'status' => Dispute::STATUS_REFUNDED, 'refunded_at' => now(), 'refund_amount' => 55000]);

        $commission = DivisionCommission::sole();
        $this->assertEquals(1000, (float) $commission->amount);
        $this->assertEquals(1000, $this->balance($this->chief));
    }

    public function test_clawback_exceeding_the_balance_is_retained_on_the_next_commission(): void
    {
        ['seller' => $seller, 'client' => $client, 'order' => $order, 'phoneLine' => $line] = $this->paidOrder();
        $this->validate($seller, $order);
        $order->refresh()->update(['vendor_funds_status' => Order::VENDOR_FUNDS_RELEASED]);
        // Le chef a déjà retiré ses gains.
        WalletBalance::where('user_id', $this->chief->id)->update(['balance' => 0]);

        $order->refresh()->update(['refunded_at' => now(), 'payment_status' => Order::PAYMENT_REFUNDED]);
        $first = DivisionCommission::sole();
        $this->assertSame(DivisionCommission::STATUS_CLAWED_BACK, $first->status);
        $this->assertEquals(2000, (float) $first->clawback_due);

        // Vente suivante : 2 000 dus, retenus avant tout versement.
        ['seller' => $seller2, 'order' => $order2] = $this->paidOrder();
        $this->validate($seller2, $order2);
        $order2->refresh()->update(['vendor_funds_status' => Order::VENDOR_FUNDS_RELEASED]);

        $this->assertEquals(0, $this->balance($this->chief));
        $this->assertEquals(0, (float) $first->fresh()->clawback_due);
    }

    public function test_sale_goes_to_the_chief_and_rate_in_office_on_the_sale_date(): void
    {
        // Ancien chef jusqu'à hier, nouveau chef à 30 % depuis.
        DivisionHead::query()->update(['ended_at' => now()->subDay()]);
        $newChief = User::factory()->create(['role' => 'division_head', 'roles' => ['division_head']]);
        $this->appoint($newChief, 30, now()->subDay());

        ['seller' => $seller, 'order' => $old] = $this->paidOrder(now()->subDays(3));
        ['seller' => $seller2, 'order' => $recent] = $this->paidOrder();
        $this->validate($seller, $old);
        $this->validate($seller2, $recent);

        $this->assertEquals(2000, (float) DivisionCommission::where('order_id', $old->id)->sole()->amount);
        $this->assertSame($this->chief->id, DivisionCommission::where('order_id', $old->id)->sole()->user_id);
        $this->assertEquals(3000, (float) DivisionCommission::where('order_id', $recent->id)->sole()->amount);
        $this->assertSame($newChief->id, DivisionCommission::where('order_id', $recent->id)->sole()->user_id);
    }

    public function test_sales_before_the_first_appointment_earn_nothing(): void
    {
        ['seller' => $seller, 'order' => $order] = $this->paidOrder(now()->subMonths(2));
        $this->validate($seller, $order);

        $this->assertSame(0, DivisionCommission::count());
    }

    public function test_real_estate_chief_earns_on_the_asso_share_of_reservations(): void
    {
        $immo = Division::create(['name' => 'Immobilier', 'type' => Division::TYPE_REAL_ESTATE]);
        $estateChief = User::factory()->create(['role' => 'division_head', 'roles' => ['division_head']]);
        DivisionHead::create(['division_id' => $immo->id, 'user_id' => $estateChief->id, 'commission_rate' => 10, 'started_at' => now()->subDay()]);

        $listing = \App\Models\RealEstate\Listing::create(['type' => 'rent', 'title' => 'Studio', 'city' => 'Douala']);
        $make = fn (string $ref) => \App\Models\RealEstate\Reservation::create([
            'reference' => $ref, 'listing_id' => $listing->id, 'tenant_id' => User::factory()->create()->id,
            'payee_id' => User::factory()->create()->id, 'kind' => 'rent', 'status' => 'pending_payment',
            'total_amount' => 230000, 'platform_amount' => 30000,
        ]);

        $released = $make('IMMO-1');
        $released->update(['status' => 'paid', 'paid_at' => now()]);
        $row = DivisionCommission::where('source_id', $released->id)->sole();
        $this->assertSame(DivisionCommission::STATUS_PENDING, $row->status);
        $this->assertEquals(3000, (float) $row->amount);

        $released->update(['status' => 'released', 'released_at' => now()]);
        $this->assertSame(DivisionCommission::STATUS_AVAILABLE, $row->fresh()->status);
        $this->assertEquals(3000, $this->balance($estateChief));

        $refunded = $make('IMMO-2');
        $refunded->update(['status' => 'paid', 'paid_at' => now()]);
        $refunded->update(['status' => 'refunded']);
        $this->assertSame(DivisionCommission::STATUS_CANCELLED, DivisionCommission::where('source_id', $refunded->id)->sole()->status);
        $this->assertEquals(3000, $this->balance($estateChief));
    }
}
