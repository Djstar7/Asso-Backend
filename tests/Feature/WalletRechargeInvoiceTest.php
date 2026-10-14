<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Facture PDF d'une recharge Wallet : téléchargeable par son propriétaire
 * depuis « Mes factures », jamais par un autre utilisateur.
 */
class WalletRechargeInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private function paidRecharge(User $user): WalletTransaction
    {
        // La table historique `transactions` exige un produit et un vendeur.
        $shop = Shop::create([
            'user_id' => $user->id,
            'name' => 'Boutique ' . $user->id,
            'slug' => 'boutique-' . uniqid(),
            'status' => 'active',
        ]);
        $product = Product::create([
            'user_id' => $user->id,
            'shop_id' => $shop->id,
            'category_id' => Category::create(['name' => 'Test', 'slug' => 'test-' . uniqid()])->id,
            'name' => 'Produit',
            'slug' => 'produit-' . uniqid(),
            'price' => 1000,
            'currency' => 'XAF',
            'stock' => 1,
            'status' => 'active',
        ]);

        $payment = Transaction::create([
            'reference' => 'TXN-TEST-' . uniqid(),
            'buyer_id' => $user->id,
            'seller_id' => $user->id,
            'product_id' => $product->id,
            'amount' => 15000,
            'net_amount' => 15000,
            'currency' => 'XAF',
            'status' => 'completed',
            'type' => 'purchase',
            'payment_method' => 'mobile',
        ]);

        return WalletTransaction::create([
            'user_id' => $user->id,
            'type' => 'credit',
            'amount' => 15000,
            'balance_before' => 0,
            'balance_after' => 15000,
            'description' => 'Recharge test',
            'status' => 'completed',
            'provider' => 'kpay',
            'payment_id' => $payment->id,
        ]);
    }

    public function test_listed_recharge_has_a_pdf_link(): void
    {
        $user = User::factory()->create();
        $tx = $this->paidRecharge($user);
        Sanctum::actingAs($user);

        $invoice = collect($this->getJson('/api/v1/invoices?type=wallet')
            ->assertOk()
            ->json('invoices.data'))
            ->firstWhere('id', $tx->id);

        $this->assertNotNull($invoice);
        $this->assertStringEndsWith("/api/v1/invoices/wallet/{$tx->id}/pdf", $invoice['pdf_url']);
    }

    public function test_owner_downloads_the_recharge_invoice_pdf(): void
    {
        $user = User::factory()->create();
        $tx = $this->paidRecharge($user);
        Sanctum::actingAs($user);

        $response = $this->get("/api/v1/invoices/wallet/{$tx->id}/pdf");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_other_user_cannot_download_the_invoice(): void
    {
        $owner = User::factory()->create();
        $tx = $this->paidRecharge($owner);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/invoices/wallet/{$tx->id}/pdf")->assertNotFound();
    }
}
