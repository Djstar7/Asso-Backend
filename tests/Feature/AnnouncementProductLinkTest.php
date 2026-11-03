<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Category;
use App\Models\NotificationDigestItem;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnnouncementProductLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function product(array $attributes = [], bool $verifiedShop = true): Product
    {
        $owner = User::factory()->create();
        $shop = Shop::create([
            'user_id' => $owner->id,
            'name' => 'Boutique '.uniqid(),
            'status' => 'active',
            'verified_at' => $verifiedShop ? now() : null,
        ]);
        $category = Category::create(['name' => 'Catégorie '.uniqid(), 'slug' => uniqid('cat-')]);

        return Product::create($attributes + [
            'user_id' => $owner->id,
            'shop_id' => $shop->id,
            'category_id' => $category->id,
            'name' => 'Article '.uniqid(),
            'price' => 10000,
            'stock' => 5,
            'status' => 'active',
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'title' => 'Promo',
            'message' => 'Nouveauté à découvrir',
            'channel' => 'push',
            'target_type' => 'all',
        ];
    }

    public function test_push_announcement_carries_its_product_to_the_digest(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin)
            ->post(route('admin.announcements.store'), $this->payload(['product_id' => $product->id]))
            ->assertRedirect(route('admin.announcements.index'));

        $announcement = Announcement::firstOrFail();
        $this->assertSame($product->id, $announcement->product_id);

        $this->actingAs($this->admin)->post(route('admin.announcements.send', $announcement));

        $data = NotificationDigestItem::firstOrFail()->data;
        $this->assertSame('announcement', $data['type']);
        $this->assertSame((string) $product->id, $data['product_id']);
    }

    public function test_announcement_without_product_stays_a_plain_message(): void
    {
        $this->actingAs($this->admin)->post(route('admin.announcements.store'), $this->payload());

        $announcement = Announcement::firstOrFail();
        $this->assertNull($announcement->product_id);

        $this->actingAs($this->admin)->post(route('admin.announcements.send', $announcement));

        $this->assertArrayNotHasKey('product_id', NotificationDigestItem::firstOrFail()->data);
    }

    public function test_two_announcements_on_the_same_product_are_both_queued(): void
    {
        $product = $this->product();

        foreach (['Première', 'Seconde'] as $message) {
            $announcement = Announcement::create($this->payload([
                'message' => $message,
                'product_id' => $product->id,
                'status' => 'draft',
            ]));
            $this->actingAs($this->admin)->post(route('admin.announcements.send', $announcement));
        }

        $this->assertSame(2, NotificationDigestItem::count());
    }

    public function test_other_channels_never_keep_a_product(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin)
            ->post(route('admin.announcements.store'), $this->payload(['channel' => 'sms', 'product_id' => $product->id]));

        $this->assertNull(Announcement::firstOrFail()->product_id);
    }

    public function test_a_product_the_app_cannot_open_is_refused(): void
    {
        $unopenable = [
            'masqué' => $this->product(['status' => 'inactive']),
            'boutique non vérifiée' => $this->product([], false),
            'importé hors gros' => $this->product(['origin_country' => 'CN', 'is_wholesale' => false]),
        ];

        foreach ($unopenable as $case => $product) {
            $this->actingAs($this->admin)
                ->post(route('admin.announcements.store'), $this->payload(['product_id' => $product->id]))
                ->assertSessionHasErrors('product_id');
            $this->assertSame(0, Announcement::count(), $case);
        }
    }

    public function test_sending_is_blocked_when_the_product_was_withdrawn_since(): void
    {
        $product = $this->product();
        $announcement = Announcement::create($this->payload(['product_id' => $product->id, 'status' => 'draft']));
        $product->update(['status' => 'inactive']);

        $this->actingAs($this->admin)
            ->post(route('admin.announcements.send', $announcement))
            ->assertSessionHas('error');

        $this->assertSame('draft', $announcement->fresh()->status);
        $this->assertSame(0, NotificationDigestItem::count());
    }

    public function test_search_only_offers_products_the_app_can_open(): void
    {
        $local = $this->product(['name' => 'Sac local']);
        $wholesale = $this->product(['name' => 'Sac gros', 'origin_country' => 'CN', 'is_wholesale' => true]);
        $this->product(['name' => 'Sac masqué', 'status' => 'inactive']);

        $ids = $this->actingAs($this->admin)
            ->getJson(route('admin.announcements.product-search', ['q' => 'Sac']))
            ->assertOk()
            ->json('products.*.id');

        $this->assertEqualsCanonicalizing([$local->id, $wholesale->id], $ids);

        $this->actingAs($this->admin)
            ->getJson(route('admin.announcements.product-search', ['q' => (string) $local->id]))
            ->assertJsonPath('products.0.id', $local->id);
    }

    public function test_forms_render_with_the_linked_product(): void
    {
        $product = $this->product(['name' => 'Sac rouge']);
        $announcement = Announcement::create($this->payload(['product_id' => $product->id, 'status' => 'draft']));

        $this->actingAs($this->admin)->get(route('admin.announcements.create'))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.announcements.edit', $announcement))->assertOk()->assertSee('Sac rouge');
        $this->actingAs($this->admin)->get(route('admin.announcements.show', $announcement))->assertOk()->assertSee('Sac rouge');
    }
}
