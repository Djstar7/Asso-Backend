<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DelivererCompany;
use App\Models\DeliveryAgency;
use App\Models\Dispute;
use App\Models\DisputeResolutionTask;
use App\Models\DisputeShipment;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderDeliveryClaim;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\DisputeResolutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DisputeReminderTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private User $seller;

    private Order $order;

    private DisputeResolutionService $resolution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->client = User::factory()->create();
        $this->seller = User::factory()->create();
        $category = Category::create(['name' => 'Sacs', 'slug' => 'sacs']);
        $shop = Shop::create(['user_id' => $this->seller->id, 'name' => 'Boutique', 'slug' => 'boutique', 'status' => 'active']);
        $product = Product::create(['user_id' => $this->seller->id, 'shop_id' => $shop->id, 'category_id' => $category->id,
            'name' => 'Sac', 'slug' => 'sac', 'price' => 1000, 'currency' => 'XAF', 'stock' => 10, 'status' => 'active']);
        $this->order = Order::create(['user_id' => $this->client->id, 'status' => 'delivered', 'subtotal' => 1100,
            'delivery_fee' => 0, 'total' => 1100, 'payment_method' => 'kpay_direct', 'payment_status' => 'paid']);
        $this->order->items()->create(['product_id' => $product->id, 'seller_id' => $this->seller->id,
            'quantity' => 1, 'unit_price' => 1100, 'total_price' => 1100]);
        $this->resolution = app(DisputeResolutionService::class);
    }

    private function dispute(array $attributes = []): Dispute
    {
        $item = $this->order->items()->first();

        return Dispute::create($attributes + ['order_id' => $this->order->id, 'order_item_id' => $item->id,
            'product_id' => $item->product_id, 'client_id' => $this->client->id, 'seller_id' => $this->seller->id,
            'status' => 'return', 'decision' => 'founded', 'reason' => 'damaged', 'description' => 'Produit abîmé.',
            'asso_managed' => false, 'held_amount' => 1000]);
    }

    private function shipment(Dispute $dispute, array $attributes = []): DisputeShipment
    {
        return $dispute->shipments()->create($attributes + ['type' => 'return', 'status' => 'awaiting_payment',
            'payer' => 'vendor', 'payment_status' => 'pending']);
    }

    private function admin(array $attributes = []): User
    {
        return User::factory()->create($attributes + ['role' => 'admin', 'roles' => ['admin']]);
    }

    private function reminders()
    {
        return Notification::where('data->task_id', '!=', null)
            ->where('data->type', 'dispute_resolution_reminder');
    }

    public function test_payment_reminder_is_sent_once_before_deadline_with_email_and_no_money_movement(): void
    {
        $dispute = $this->dispute();
        $this->shipment($dispute);
        $this->resolution->process();
        $task = DisputeResolutionTask::firstOrFail();
        $deadline = $task->due_at->copy();
        $this->travel(23)->hours();
        $this->resolution->process();
        $this->assertSame(0, $this->reminders()->count());
        $this->travel(2)->hours();
        $this->resolution->process();
        $this->resolution->process();
        $this->assertSame(1, $this->reminders()->where('user_id', $this->seller->id)->count());
        $this->assertSame(0, Notification::where('user_id', $this->client->id)->count());
        $this->assertNotNull($task->fresh()->reminded_at);
        $this->assertTrue($task->fresh()->due_at->equalTo($deadline));
        $this->assertSame(1, $dispute->events()->where('type', 'resolution_reminder')->count());
        $this->assertNull($dispute->fresh()->refunded_at);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $emails = Mail::mailer()->getSymfonyTransport()->messages();
        $this->assertCount(1, $emails);
        $this->assertSame($this->seller->email, $emails->first()->getOriginalMessage()->getTo()[0]->getAddress());
    }

    public function test_completed_step_and_closed_dispute_do_not_send_stale_reminders(): void
    {
        $dispute = $this->dispute();
        $shipment = $this->shipment($dispute);
        $this->resolution->process();
        $old = DisputeResolutionTask::firstOrFail();
        $this->travel(12)->hours();
        $shipment->update(['payment_status' => 'paid', 'status' => 'courier_selected']);
        $this->resolution->process();
        $this->travel(13)->hours();
        $this->resolution->process();
        $this->assertNotNull($old->fresh()->completed_at);
        $this->assertSame(0, Notification::count());
        $dispute->update(['status' => 'refunded', 'refunded_at' => now(), 'closed_at' => now()]);
        $this->travel(12)->hours();
        $this->resolution->process();
        $this->assertSame(0, Notification::count());
        $this->assertSame(0, DisputeResolutionTask::whereNull('completed_at')->count());
    }

    public function test_english_admin_receives_localized_review_reminder_with_backoffice_link(): void
    {
        $admin = $this->admin(['locale' => 'en']);
        $this->dispute(['status' => 'in_review', 'decision' => null]);
        $this->resolution->process();
        $this->travel(25)->hours();
        $this->resolution->process();
        $notice = $this->reminders()->where('user_id', $admin->id)->firstOrFail();
        $this->assertStringContainsString('ASSO reminder', $notice->getRawOriginal('title'));
        $this->assertStringContainsString('ASSO decision', $notice->getRawOriginal('body'));
        $this->assertStringContainsString('/admin/disputes/resolution', $notice->getRawOriginal('body'));
        $this->assertSame('staff', $notice->data['role']);
        $this->assertSame(0, Notification::whereIn('user_id', [$this->client->id, $this->seller->id])->count());
    }

    public function test_local_carrier_receives_one_reminder_even_when_owner_is_also_assigned_courier(): void
    {
        $courier = User::factory()->create();
        $company = DelivererCompany::create(['name' => 'Transporteur', 'is_active' => true, 'user_id' => $courier->id, 'email' => $courier->email]);
        $dispute = $this->dispute();
        $this->shipment($dispute, ['status' => 'in_transit', 'payment_status' => 'paid',
            'deliverer_company_id' => $company->id, 'delivery_person_id' => $courier->id]);
        $this->resolution->process();
        $this->travel(145)->hours();
        $this->resolution->process();
        $this->resolution->process();
        $this->assertSame(1, Notification::where('user_id', $courier->id)->count());
        $this->assertSame('dispute_return_course', Notification::firstOrFail()->data['type']);
        $this->assertSame(0, Notification::whereIn('user_id', [$this->seller->id, $this->client->id])->count());
        $this->assertCount(1, Mail::mailer()->getSymfonyTransport()->messages());
    }

    public function test_international_replacement_collection_reminds_solex_instead_of_dhl(): void
    {
        $dhlOwner = User::factory()->create();
        $solexOwner = User::factory()->create();
        $dhl = DelivererCompany::create(['name' => 'DHL', 'user_id' => $dhlOwner->id, 'is_active' => true]);
        $solex = DelivererCompany::create(['name' => 'Solex', 'user_id' => $solexOwner->id, 'is_active' => true]);
        $agency = DeliveryAgency::create(['deliverer_company_id' => $solex->id, 'code' => 'SLX-REMINDER',
            'name' => 'Solex Douala', 'city' => 'Douala', 'address' => 'Akwa', 'phone' => '237670000001', 'is_active' => true]);
        $dispute = $this->dispute(['status' => 'replacement']);
        $this->shipment($dispute, ['type' => 'replacement', 'status' => 'courier_selected', 'payment_status' => 'paid',
            'deliverer_company_id' => $dhl->id, 'quote' => ['international_dispute' => true, 'solex_agency' => ['id' => $agency->id]]]);
        $this->resolution->process();
        $this->travel(25)->hours();
        $this->resolution->process();
        $this->assertSame(1, Notification::where('user_id', $solexOwner->id)->count());
        $this->assertSame(0, Notification::where('user_id', $dhlOwner->id)->count());
    }

    public function test_undelivered_claim_reminds_assigned_admin_and_stops_after_resolution(): void
    {
        $admin = $this->admin();
        $this->order->update(['status' => 'confirmed']);
        $claim = OrderDeliveryClaim::create(['order_id' => $this->order->id, 'reason' => 'not_received',
            'description' => 'Commande non reçue après paiement.']);
        $this->resolution->process();
        $this->travel(25)->hours();
        $this->resolution->process();
        $notice = $this->reminders()->where('user_id', $admin->id)->firstOrFail();
        $this->assertSame((string) $this->order->id, $notice->data['order_id']);
        $this->assertArrayNotHasKey('dispute_id', $notice->data);
        $claim->update(['status' => 'rejected', 'resolved_at' => now()]);
        $this->travel(24)->hours();
        $this->resolution->process();
        $this->assertSame(1, Notification::count());
    }

    public function test_reassigned_admin_gets_a_new_reminder_without_repeating_the_previous_one(): void
    {
        $first = $this->admin();
        $second = $this->admin();
        $this->dispute(['status' => 'in_review', 'decision' => null]);
        $this->resolution->process();
        $this->travel(25)->hours();
        $this->resolution->process();
        $this->travel(24)->hours();
        $this->resolution->process();
        $task = DisputeResolutionTask::firstOrFail();
        $this->assertSame(1, $task->escalations);
        $this->assertSame($second->id, $task->assigned_to);
        $this->travel(25)->hours();
        $this->resolution->process();
        $this->resolution->process();
        $this->assertSame(1, $this->reminders()->where('user_id', $first->id)->count());
        $this->assertSame(1, $this->reminders()->where('user_id', $second->id)->count());
    }

    public function test_existing_task_gets_reminder_without_moving_its_deadline(): void
    {
        $this->shipment($this->dispute());
        $this->resolution->process();
        $task = DisputeResolutionTask::firstOrFail();
        $deadline = $task->due_at->copy();
        $task->update(['reminder_due_at' => null]);
        $this->travel(25)->hours();
        $this->resolution->process();
        $this->assertTrue($task->fresh()->due_at->equalTo($deadline));
        $this->assertNotNull($task->fresh()->reminded_at);
        $this->assertSame(1, $this->reminders()->count());
    }

    public function test_overdue_task_is_escalated_directly_without_an_obsolete_reminder(): void
    {
        $this->admin();
        $this->shipment($this->dispute());
        $this->resolution->process();
        $this->travel(49)->hours();
        $this->resolution->process();
        $this->assertSame(0, $this->reminders()->count());
        $this->assertSame(1, DisputeResolutionTask::firstOrFail()->escalations);
    }

    public function test_missing_recipient_is_reported_and_can_be_retried_when_an_admin_becomes_available(): void
    {
        $this->dispute(['status' => 'in_review', 'decision' => null]);
        $this->resolution->process();
        $this->travel(25)->hours();
        $this->resolution->process();
        $task = DisputeResolutionTask::firstOrFail();
        $this->assertNull($task->reminded_at);
        $this->assertStringContainsString('aucun destinataire', $task->last_error);
        $admin = $this->admin();
        $this->resolution->process();
        $this->resolution->process();
        $this->assertNotNull($task->fresh()->reminded_at);
        $this->assertNull($task->fresh()->last_error);
        $this->assertSame(1, $this->reminders()->where('user_id', $admin->id)->count());
    }
}
