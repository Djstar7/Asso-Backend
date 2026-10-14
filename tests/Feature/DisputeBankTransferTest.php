<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Currency;
use App\Models\Dispute;
use App\Models\ExchangeRate;
use App\Models\Order;
use App\Models\PlatformWithdrawal;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Shop;
use App\Models\User;
use App\Models\WalletBalance;
use App\Services\DisputeService;
use App\Services\FirebaseMessagingService;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Litige sur une commande payée par carte : ASSO vire, depuis le back-office, le
 * remboursement du client (ou la part du vendeur) sur l'IBAN Stripe Connect que
 * le bénéficiaire a synchronisé dans son Wallet. Acheteur comme vendeur.
 */
class DisputeBankTransferTest extends TestCase
{
    use RefreshDatabase;

    private User $client;
    private User $seller;
    private Shop $shop;
    private Category $category;
    private int $payouts = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->mock(FirebaseMessagingService::class, fn ($mock) => $mock->shouldReceive('sendToUser')->andReturn([]));
        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('assertPayoutPossible');
            $mock->shouldReceive('payoutToVendor')->andReturnUsing(function () {
                $this->payouts++;

                return ['transfer_id' => 'tr_' . $this->payouts, 'payout_id' => 'po_' . $this->payouts];
            });
        });

        Currency::firstOrCreate(['code' => 'XAF'], ['name' => 'Franc CFA', 'symbol' => 'FCFA', 'is_active' => true]);
        Currency::firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'symbol' => '€', 'is_active' => true]);
        ExchangeRate::create([
            'from_currency' => 'XAF', 'to_currency' => 'EUR', 'rate' => 0.0015,
            'effective_date' => now()->toDateString(), 'is_active' => true, 'source' => 'test',
        ]);
        Setting::set('min_stripe_withdrawal_amount', 1, 'integer');

        $this->client = User::factory()->create();
        $this->seller = User::factory()->create();
        User::factory()->create(['email' => 'admin@asso.com']);
        $this->category = Category::create(['name' => 'Mode', 'slug' => 'mode-' . uniqid()]);
        $this->shop = Shop::create([
            'user_id' => $this->seller->id, 'name' => 'Boutique', 'slug' => 'boutique-' . uniqid(), 'status' => 'active',
        ]);
    }

    private function withIban(User $user, string $status = 'approved'): User
    {
        $user->forceFill([
            'stripe_account_id' => 'acct_' . $user->id,
            'stripe_account_status' => $status,
            'stripe_bank_country' => 'FR',
            'stripe_external_last4' => '3000',
            'stripe_account_holder_name' => $user->name,
        ])->saveQuietly();

        return $user;
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'roles' => ['admin']]);
    }

    /** Litige clôturé sur une commande (2 000 FCFA, part vendeur 1 800). */
    private function closedDispute(string $paymentMethod, string $status): Dispute
    {
        $product = Product::create([
            'user_id' => $this->seller->id, 'shop_id' => $this->shop->id, 'category_id' => $this->category->id,
            'name' => 'Sac', 'slug' => 'sac-' . uniqid(), 'price' => 1800, 'currency' => 'XAF', 'stock' => 5, 'status' => 'active',
        ]);
        $order = Order::create([
            'user_id' => $this->client->id, 'status' => 'delivered', 'subtotal' => 2000, 'vendor_net_amount' => 1800,
            'total' => 2000, 'delivery_address' => 'Bonapriso, Douala',
            'payment_method' => $paymentMethod, 'payment_status' => 'paid',
            'vendor_funds_holder_id' => $this->seller->id,
        ]);
        $item = $order->items()->create([
            'product_id' => $product->id, 'seller_id' => $this->seller->id, 'quantity' => 1,
            'unit_price' => 2000, 'total_price' => 2000, 'seller_unit_price' => 1800, 'seller_total_price' => 1800,
        ]);

        return Dispute::create([
            'order_id' => $order->id, 'order_item_id' => $item->id, 'product_id' => $product->id,
            'client_id' => $this->client->id, 'seller_id' => $this->seller->id,
            'reason' => 'damaged', 'description' => 'Déchiré', 'status' => $status,
            'refund_amount' => $status === Dispute::STATUS_REFUNDED ? 2000 : 0,
            'refunded_at' => $status === Dispute::STATUS_REFUNDED ? now() : null,
            'closed_at' => now(),
        ]);
    }

    private function setBalance(User $user, float $balance): void
    {
        WalletBalance::updateOrCreate(['user_id' => $user->id, 'currency' => 'XAF'], ['balance' => $balance, 'locked_balance' => 0]);
    }

    public function test_buyer_refund_is_transferred_once_to_the_synced_iban(): void
    {
        $this->withIban($this->client);
        $this->setBalance($this->client, 2000); // remboursement crédité sur son Wallet
        $dispute = $this->closedDispute('stripe_direct', Dispute::STATUS_REFUNDED);
        $admin = $this->admin();

        $this->actingAs($admin)->get("/admin/disputes/{$dispute->id}")->assertOk()->assertSee("Virer sur l'IBAN", false);

        $this->actingAs($admin)
            ->post("/admin/disputes/{$dispute->id}/bank-transfer", ['beneficiary' => 'client'])
            ->assertSessionHas('success');

        $withdrawal = PlatformWithdrawal::where('user_id', $this->client->id)->firstOrFail();
        $this->assertSame($admin->id, $withdrawal->admin_id);
        $this->assertSame('EUR', $withdrawal->payout_currency);
        $this->assertEqualsWithDelta(3.0, (float) $withdrawal->payout_amount, 0.001); // 2 000 × 0,0015
        $this->assertSame($withdrawal->id, $dispute->fresh()->client_bank_withdrawal_id);
        $this->assertEqualsWithDelta(0.0, $this->client->fresh()->kpayBalanceFor('XAF'), 0.001);
        $this->assertDatabaseHas('dispute_events', ['dispute_id' => $dispute->id, 'type' => 'bank_transfer_client']);

        // Un second clic ne vire pas deux fois.
        $this->setBalance($this->client, 2000);
        $this->actingAs($admin)
            ->post("/admin/disputes/{$dispute->id}/bank-transfer", ['beneficiary' => 'client'])
            ->assertSessionHas('error');
        $this->assertSame(1, $this->payouts);
    }

    public function test_transfer_requires_a_validated_iban(): void
    {
        $this->withIban($this->client, 'pending');
        $this->setBalance($this->client, 2000);
        $dispute = $this->closedDispute('stripe_direct', Dispute::STATUS_REFUNDED);

        $this->actingAs($this->admin())
            ->post("/admin/disputes/{$dispute->id}/bank-transfer", ['beneficiary' => 'client'])
            ->assertSessionHas('error', __('disputes.bank_transfer.iban_missing'));

        $this->assertSame(0, $this->payouts);
        $this->assertEqualsWithDelta(2000.0, $this->client->fresh()->kpayBalanceFor('XAF'), 0.001);
    }

    public function test_no_bank_transfer_when_the_order_was_not_paid_by_card(): void
    {
        $this->withIban($this->client);
        $this->setBalance($this->client, 2000);
        $dispute = $this->closedDispute('kpay_direct', Dispute::STATUS_REFUNDED);

        $this->assertSame([], app(DisputeService::class)->bankTransferOptions($dispute));
        $this->actingAs($this->admin())
            ->post("/admin/disputes/{$dispute->id}/bank-transfer", ['beneficiary' => 'client'])
            ->assertSessionHas('error');
        $this->assertSame(0, $this->payouts);
    }

    public function test_vendor_share_can_be_transferred_after_an_unfounded_claim(): void
    {
        $this->withIban($this->seller);
        $this->setBalance($this->seller, 1800);
        $dispute = $this->closedDispute('stripe_direct', Dispute::STATUS_REJECTED);

        $options = app(DisputeService::class)->bankTransferOptions($dispute);
        $this->assertSame(['vendor'], array_keys($options));
        $this->assertSame(1800.0, $options['vendor']['amount']);

        $this->actingAs($this->admin())
            ->post("/admin/disputes/{$dispute->id}/bank-transfer", ['beneficiary' => 'vendor'])
            ->assertSessionHas('success');

        $this->assertNotNull($dispute->fresh()->vendor_bank_withdrawal_id);
        $this->assertEqualsWithDelta(0.0, $this->seller->fresh()->kpayBalanceFor('XAF'), 0.001);
    }
}
