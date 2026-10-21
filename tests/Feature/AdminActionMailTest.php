<?php

namespace Tests\Feature;

use App\Mail\AdminActionDigestMail;
use App\Models\Category;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Order;
use App\Models\Product;
use App\Models\RealEstate\LandlordVerification;
use App\Models\RealEstate\Listing;
use App\Models\Shop;
use App\Models\User;
use App\Services\AdminActionCatalog;
use App\Services\AdminActionMailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminActionMailTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Mail::fake();
        $this->admin = User::factory()->create(['email' => 'admin@asso.com', 'role' => 'admin']);
    }

    private function manager(array $permissions): User
    {
        return User::factory()->create([
            'role' => 'product_manager', 'roles' => ['product_manager'], 'admin_permissions' => $permissions,
        ]);
    }

    private function listing(): Listing
    {
        return Listing::create([
            'type' => 'rent', 'title' => 'Maison à vérifier', 'city' => 'Douala',
            'status' => 'pending_review', 'net_price' => 100000,
        ]);
    }

    private function sendPending(): array
    {
        return app(AdminActionMailService::class)->sendPending();
    }

    public function test_pending_listing_and_landlord_notify_only_habilitated_real_staff(): void
    {
        $estateManager = $this->manager(['real_estate']);
        $otherManager = $this->manager(['disputes']);
        $ordinaryUser = User::factory()->create(['admin_permissions' => ['real_estate']]);
        $technical = User::factory()->create(['email' => 'system@asso.app', 'role' => 'admin']);
        $listing = $this->listing();
        LandlordVerification::create([
            'user_id' => $ordinaryUser->id, 'status' => 'pending',
            'id_front_path' => 'private/front.jpg', 'ownership_proof_type' => 'land_title',
            'ownership_proof_path' => 'private/proof.pdf', 'video_path' => 'private/video.mp4',
        ]);

        $this->assertSame(2, $this->sendPending()['sent']);
        foreach ([$this->admin, $estateManager] as $recipient) {
            Mail::assertSent(AdminActionDigestMail::class, function ($mail) use ($recipient, $listing) {
                return $mail->hasTo($recipient->email)
                    && $mail->groups['estate_listings']['items'][0]['url'] === route('admin.real-estate.listings.edit', $listing)
                    && $mail->groups['landlords']['count'] === 1;
            });
        }
        foreach ([$otherManager, $ordinaryUser, $technical] as $recipient) {
            Mail::assertNotSent(AdminActionDigestMail::class, fn ($mail) => $mail->hasTo($recipient->email));
        }
        $this->actingAs($estateManager)->get(route('admin.real-estate.listings.edit', $listing))->assertOk();
    }

    public function test_unchanged_tasks_are_not_resent_and_daily_reminders_stop_when_resolved(): void
    {
        $listing = $this->listing();
        $this->assertSame(1, $this->sendPending()['sent']);
        $this->assertSame(0, $this->sendPending()['sent']);
        $this->travel(23)->hours();
        $this->assertSame(0, $this->sendPending()['sent']);
        $this->travel(1)->hours();
        $this->assertSame(1, $this->sendPending()['sent']);
        Mail::assertSent(AdminActionDigestMail::class, fn ($mail) => $mail->reminder);

        $listing->update(['status' => 'published']);
        $this->travel(1)->days();
        $this->assertSame(0, $this->sendPending()['sent']);
        $this->assertDatabaseCount('admin_action_mail_states', 0);
        // Une nouvelle soumission du même dossier déclenche une nouvelle alerte.
        $listing->update(['status' => 'pending_review']);
        $this->assertSame(1, $this->sendPending()['sent']);
        Mail::assertSentCount(3);
    }

    public function test_managers_do_not_receive_private_vendor_data_even_with_section_permissions(): void
    {
        $manager = $this->manager(['shops', 'local_orders']);
        $vendor = User::factory()->create(['role' => 'vendeur']);
        Shop::create(['user_id' => $this->admin->id, 'name' => 'Boutique ASSO', 'slug' => 'asso', 'status' => 'active']);
        Shop::create(['user_id' => $vendor->id, 'name' => 'VENDEUR PRIVE', 'slug' => 'prive', 'status' => 'active']);
        $privateOrder = $this->orderFor($vendor);
        $groups = app(AdminActionCatalog::class)->forUser($manager);
        $this->assertSame(1, $groups['shops']['count']);
        $this->assertArrayNotHasKey('local', $groups);
        $this->assertStringNotContainsString('VENDEUR PRIVE', json_encode($groups));
        $adminGroups = app(AdminActionCatalog::class)->forUser($this->admin);
        $this->assertSame(2, $adminGroups['shops']['count']);
        $this->assertSame(route('admin.local-orders.show', $privateOrder), $adminGroups['local']['items'][0]['url']);
    }

    private function orderFor(User $seller, array $attributes = []): Order
    {
        $product = Product::create([
            'user_id' => $seller->id,
            'category_id' => Category::create(['name' => 'Maison', 'slug' => 'maison-'.uniqid()])->id,
            'name' => 'Produit', 'slug' => 'produit-'.uniqid(), 'price' => 1000,
            'stock' => 10, 'currency' => 'XAF', 'status' => 'active',
        ]);
        $order = Order::create($attributes + [
            'user_id' => User::factory()->create()->id,
            'status' => 'pending', 'payment_status' => 'paid', 'has_local_products' => true,
            'subtotal' => 1000, 'delivery_fee' => 0, 'total' => 1000,
        ]);
        $order->items()->create([
            'product_id' => $product->id, 'seller_id' => $seller->id,
            'quantity' => 1, 'unit_price' => 1000, 'total_price' => 1000,
        ]);

        return $order;
    }

    public function test_order_stage_changes_trigger_an_alert_even_for_bulk_database_updates(): void
    {
        $manager = $this->manager(['asso_orders']);
        $order = $this->orderFor($this->admin);
        $this->assertSame(2, $this->sendPending()['sent']);
        DB::table('orders')->where('id', $order->id)->update(['status' => 'preparing']);
        $this->assertSame(2, $this->sendPending()['sent']);
        Mail::assertSent(AdminActionDigestMail::class, fn ($mail) => $mail->hasTo($manager->email)
            && str_contains($mail->groups['asso']['items'][0]['label'], 'préparation'));
        DB::table('orders')->where('id', $order->id)->update(['status' => 'shipped', 'tracking_status' => 'in_transit']);
        $this->assertSame(2, $this->sendPending()['sent']);
        DB::table('orders')->where('id', $order->id)->update(['tracking_status' => 'ready_for_pickup']);
        $this->assertSame(2, $this->sendPending()['sent']);
        $order->update(['status' => 'delivered']);
        $this->assertSame(0, $this->sendPending()['sent']);
    }

    public function test_revoked_permissions_stop_notifications_and_links_still_require_authorization(): void
    {
        $manager = $this->manager(['real_estate']);
        $listing = $this->listing();
        $this->sendPending();
        $url = route('admin.real-estate.listings.edit', $listing);
        $manager->update(['admin_permissions' => []]);
        $this->travel(1)->days();
        $this->assertSame(1, $this->sendPending()['sent']); // admin seulement
        $this->assertCount(1, Mail::sent(AdminActionDigestMail::class, fn ($m) => $m->hasTo($manager->email)));
        $this->actingAs($manager)->get($url)->assertForbidden();
    }

    public function test_mail_failure_is_retried_without_marking_the_digest_sent(): void
    {
        $this->listing();
        $mailFake = Mail::getFacadeRoot();
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('SMTP unavailable'));
        $this->assertSame(1, $this->sendPending()['failed']);
        $this->assertDatabaseCount('admin_action_mail_states', 0);
        Mail::swap($mailFake);
        $this->assertSame(1, $this->sendPending()['sent']);
        $this->assertDatabaseCount('admin_action_mail_states', 1);
    }

    public function test_secondary_staff_role_is_included_but_no_email_is_sent_when_nothing_is_pending(): void
    {
        $secondary = User::factory()->create([
            'role' => 'client', 'roles' => ['client', 'product_manager'], 'admin_permissions' => ['real_estate'],
        ]);
        $this->assertSame(0, $this->sendPending()['sent']);
        $this->listing();
        $this->sendPending();
        Mail::assertSent(AdminActionDigestMail::class, fn ($mail) => $mail->hasTo($secondary->email));
    }

    public function test_dry_run_never_sends_email_and_log_mailer_is_not_marked_delivered(): void
    {
        $this->listing();
        $this->artisan('admin:notify-actions --dry-run')->assertSuccessful();
        Mail::assertNothingSent();
        config(['mail.default' => 'log']);
        $this->artisan('admin:notify-actions')->assertFailed();
        $this->assertDatabaseCount('admin_action_mail_states', 0);
    }

    public function test_digest_renders_escaped_task_labels_and_localized_content(): void
    {
        $this->listing()->update(['title' => '<script>alert(1)</script>']);
        $groups = app(AdminActionCatalog::class)->forUser($this->admin);
        $mail = new AdminActionDigestMail($this->admin, $groups);
        $html = $mail->render();
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('mail.admin_actions.', $html);
        $this->admin->forceFill(['locale' => 'en']);
        $english = (new AdminActionDigestMail($this->admin, $groups))->render();
        $this->assertStringContainsString('Your team has pending actions', $english);
        $this->assertStringContainsString('Property listings awaiting review', $english);
    }

    public function test_support_message_alerts_exclude_system_messages_and_private_conversations(): void
    {
        $manager = $this->manager(['messages']);
        $support = User::factory()->create(['email' => 'support@asso.app', 'role' => 'admin']);
        $client = User::factory()->create();
        $conversation = Conversation::withoutEvents(fn () => Conversation::create([
            'user1_id' => $support->id, 'user2_id' => $client->id,
        ]));
        $private = Conversation::withoutEvents(fn () => Conversation::create([
            'user1_id' => $this->admin->id, 'user2_id' => $client->id,
        ]));
        Message::create(['conversation_id' => $private->id, 'sender_id' => $client->id, 'message' => 'Privé', 'is_read' => false]);
        Message::create(['conversation_id' => $conversation->id, 'sender_id' => $support->id,
            'message' => 'Automatique', 'is_system' => true, 'is_read' => false]);
        $this->assertSame(0, $this->sendPending()['sent']);

        $incoming = Message::create(['conversation_id' => $conversation->id, 'sender_id' => $client->id,
            'message' => 'Besoin de support', 'is_read' => false]);
        $this->assertSame(2, $this->sendPending()['sent']);
        Mail::assertSent(AdminActionDigestMail::class, fn ($m) => $m->hasTo($manager->email)
            && $m->groups['messages']['count'] === 1
            && $m->groups['messages']['items'][0]['url'] === route('admin.messages.show', $conversation));
        $incoming->update(['is_read' => true]);
        $this->assertSame(0, $this->sendPending()['sent']);
    }

    public function test_scheduled_command_sends_through_configured_transport(): void
    {
        config(['mail.default' => 'smtp']); // Mail::fake empêche tout envoi réel.
        $this->listing();
        $this->artisan('admin:notify-actions')->assertSuccessful();
        Mail::assertSentCount(1);
    }

    public function test_concurrent_passes_do_not_send_twice(): void
    {
        $this->listing();
        $lock = Cache::lock('admin-action-emails', 600);
        $this->assertTrue($lock->get());
        try {
            $this->assertSame(0, $this->sendPending()['sent']);
            Mail::assertNothingSent();
        } finally {
            $lock->release();
        }
        $this->assertSame(1, $this->sendPending()['sent']);
    }
}
