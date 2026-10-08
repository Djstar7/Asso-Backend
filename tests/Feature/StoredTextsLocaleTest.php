<?php

namespace Tests\Feature;

use App\Console\Commands\BackfillWalletTransactionLabels;
use App\Models\Notification;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\FirebaseMessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Textes enregistrés en base (notifications, portefeuille), relus dans la langue courante. */
class StoredTextsLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_stored_in_french_is_read_back_in_english(): void
    {
        $user = User::factory()->create(['locale' => 'fr']);

        app(FirebaseMessagingService::class)->sendToUser(
            $user,
            $user->localized('notifications.order_paid.title'),
            $user->localized('notifications.order_paid.body', ['order_number' => 'CMD-42']),
            ['type' => 'order_paid', 'order_id' => '1']
        );

        $stored = Notification::firstOrFail();
        $this->assertSame('Paiement confirmé', $stored->getRawOriginal('title'));
        $this->assertSame('notifications.order_paid.body', $stored->data['i18n']['body']['key']);

        $user->update(['locale' => 'en']);
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/notifications', ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertJsonPath('notifications.0.title', 'Payment confirmed')
            ->assertJsonPath('notifications.0.body', __('notifications.order_paid.body', ['order_number' => 'CMD-42'], 'en'));

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/notifications', ['Accept-Language' => 'fr'])
            ->assertJsonPath('notifications.0.title', 'Paiement confirmé');
    }

    public function test_nested_values_and_plain_texts(): void
    {
        $user = User::factory()->create(['locale' => 'fr']);

        app(FirebaseMessagingService::class)->sendToUser(
            $user,
            $user->localized('notifications.order_tracking.title', ['order_number' => 'CMD-7']),
            $user->localized('notifications.order_tracking.body_location', [
                'step' => $user->localized('tracking.steps.handed_to_carrier'),
                'location' => 'Douala',
            ]),
            ['type' => 'order_tracking']
        );
        app(FirebaseMessagingService::class)->sendToUser($user, 'Support', 'Bonjour', ['type' => 'new_message']);

        $this->assertSame('Remis au transporteur — Douala.', Notification::where('type', 'order_tracking')->first()->getRawOriginal('body'));

        app()->setLocale('en');
        $this->assertSame('Handed over to the carrier — Douala.', Notification::where('type', 'order_tracking')->first()->body);
        $plain = Notification::where('type', 'new_message')->first();
        $this->assertSame('Bonjour', $plain->body);
        $this->assertArrayNotHasKey('i18n', $plain->data);
    }

    public function test_wallet_label_keeps_french_column_and_is_read_in_english(): void
    {
        $user = User::factory()->create();

        $transaction = WalletTransaction::create([
            'user_id' => $user->id,
            'type' => 'credit',
            'amount' => 1000,
            'balance_before' => 0,
            'balance_after' => 1000,
            'description' => WalletTransaction::label('sale_released', ['order_number' => 'CMD-9']),
            'metadata' => ['order_id' => 9],
            'status' => 'completed',
            'provider' => 'kpay',
        ]);

        $fresh = $transaction->fresh();
        $this->assertSame('Vente commande #CMD-9 — fonds débloqués', $fresh->getRawOriginal('description'));
        $this->assertSame(9, $fresh->metadata['order_id']);
        $this->assertSame('wallet.transactions.sale_released', $fresh->metadata['label']['key']);

        app()->setLocale('en');
        $this->assertSame('Sale — order #CMD-9 — funds released', $fresh->description);
        app()->setLocale('fr');
        $this->assertSame('Vente commande #CMD-9 — fonds débloqués', $fresh->description);
    }

    public function test_backfill_recognizes_old_french_labels(): void
    {
        $patterns = BackfillWalletTransactionLabels::patterns();

        $this->assertSame(
            ['key' => 'wallet.transactions.sale_released', 'params' => ['order_number' => 'CMD-1']],
            BackfillWalletTransactionLabels::match('Vente commande #CMD-1 — fonds débloqués', $patterns)
        );
        $this->assertSame(
            ['key' => 'wallet.transactions.sale', 'params' => ['order_number' => 'CMD-1']],
            BackfillWalletTransactionLabels::match('Vente commande #CMD-1', $patterns)
        );
        $this->assertSame(
            ['key' => 'wallet.transactions.dispute_refund', 'params' => ['number' => 'LIT-3', 'order_number' => 'CMD-2']],
            BackfillWalletTransactionLabels::match('Remboursement — Litige LIT-3 (commande #CMD-2)', $patterns)
        );
        $this->assertSame('wallet.transactions.bank_transfer_converted',
            BackfillWalletTransactionLabels::match('Virement IBAN ****1234 (10,00 EUR)', $patterns)['key']);
        $this->assertNull(BackfillWalletTransactionLabels::match('Retrait test', $patterns));

        $user = User::factory()->create();
        $old = WalletTransaction::create([
            'user_id' => $user->id, 'type' => 'credit', 'amount' => 500,
            'balance_before' => 0, 'balance_after' => 500,
            'description' => 'Commission livraison #CMD-5',
            'status' => 'completed', 'provider' => 'kpay',
        ]);

        $this->artisan('wallet:backfill-labels', ['--dry-run' => true])->assertSuccessful();
        $this->assertNull($old->fresh()->metadata);

        $this->artisan('wallet:backfill-labels')->assertSuccessful();
        app()->setLocale('en');
        $this->assertSame('Delivery commission #CMD-5', $old->fresh()->description);
        $this->assertSame('Commission livraison #CMD-5', $old->fresh()->getRawOriginal('description'));
    }
}
