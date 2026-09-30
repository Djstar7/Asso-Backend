<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminShopEditTest extends TestCase
{
    use RefreshDatabase;

    private function shop(array $attributes = []): Shop
    {
        return Shop::create($attributes + [
            'user_id' => User::factory()->create()->id,
            'name' => 'Boutique Test',
            'slug' => 'boutique-test',
            'status' => 'active',
            'categories' => ['Mode'],
        ]);
    }

    private function payload(Shop $shop, array $overrides = []): array
    {
        return $overrides + [
            'user_id' => $shop->user_id,
            'name' => $shop->name,
            'status' => 'active',
            'verification_status' => 'pending',
        ];
    }

    public function test_edit_page_renders_every_section(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Category::create(['name' => 'Électronique', 'slug' => 'electronique']);
        $shop = $this->shop();

        $this->actingAs($admin)->get(route('admin.shops.edit', $shop))
            ->assertOk()
            ->assertSee('Contact')
            ->assertSee('Électronique')
            ->assertSee('Livraison gratuite')
            ->assertSee('name="city"', false);
    }

    public function test_update_saves_contact_location_categories_and_free_delivery(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shop = $this->shop();

        $this->actingAs($admin)->put(route('admin.shops.update', $shop), $this->payload($shop, [
            'phone' => '+237690000000',
            'email' => 'contact@boutique.test',
            'address' => 'Akwa, Douala, Cameroun',
            'city' => 'Douala',
            'country' => 'Cameroun',
            'categories' => ['Mode', 'Électronique'],
            'free_delivery' => '1',
        ]))->assertRedirect(route('admin.shops.show', $shop));

        $shop->refresh();
        $this->assertSame('+237690000000', $shop->phone);
        $this->assertSame('contact@boutique.test', $shop->email);
        $this->assertSame('Douala', $shop->city);
        $this->assertSame(['Mode', 'Électronique'], $shop->categories);
        $this->assertTrue($shop->free_delivery);
    }

    public function test_unchecking_everything_clears_categories_and_free_delivery(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shop = $this->shop(['free_delivery' => true]);

        $this->actingAs($admin)->put(route('admin.shops.update', $shop), $this->payload($shop, ['free_delivery' => '0']))
            ->assertRedirect();

        $shop->refresh();
        $this->assertSame([], $shop->categories);
        $this->assertFalse($shop->free_delivery);
    }

    public function test_resaving_a_verified_shop_keeps_its_original_verification(): void
    {
        $verifier = User::factory()->create(['role' => 'admin']);
        $shop = $this->shop(['verified_at' => now()->subMonth(), 'verified_by' => $verifier->id]);
        $originalDate = $shop->verified_at->toDateTimeString();

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->put(route('admin.shops.update', $shop), $this->payload($shop, ['verification_status' => 'verified']))
            ->assertRedirect();

        $shop->refresh();
        $this->assertSame($originalDate, $shop->verified_at->toDateTimeString());
        $this->assertSame($verifier->id, $shop->verified_by);
    }

    public function test_rejection_requires_a_reason(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shop = $this->shop();

        $this->actingAs($admin)->put(route('admin.shops.update', $shop), $this->payload($shop, ['verification_status' => 'rejected']))
            ->assertSessionHasErrors('rejection_reason');
    }
}
