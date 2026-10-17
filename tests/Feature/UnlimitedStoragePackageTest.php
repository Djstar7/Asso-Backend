<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\User;
use App\Services\PackageSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Recette du 16/10/2026 (E6) : forfait de stockage « taille illimitée ». */
class UnlimitedStoragePackageTest extends TestCase
{
    use RefreshDatabase;

    private function package(array $attrs = []): Package
    {
        return Package::create(array_merge([
            'type' => 'storage',
            'name' => 'Stockage',
            'price' => 3500,
            'duration_days' => 30,
            'storage_size_mb' => 500,
            'is_active' => true,
        ], $attrs));
    }

    public function test_unlimited_package_has_no_ceiling_but_counts_usage(): void
    {
        $vendor = User::factory()->create(['role' => 'vendeur']);
        $unlimited = $this->package(['storage_size_mb' => null, 'is_unlimited_storage' => true]);
        $service = app(PackageSubscriptionService::class);

        $service->assertSubscribable($vendor, $unlimited);
        $vp = $service->applyPackage($vendor, $unlimited)['vendor_package'];

        $this->assertTrue($vp->is_unlimited_storage);
        $this->assertTrue($vp->hasEnoughStorage(10_000));
        $vp->deductStorage(1.4);
        $this->assertEquals(1.4, (float) $vp->fresh()->storage_used_mb);

        Sanctum::actingAs($vendor);
        $this->getJson('/api/v1/vendor/package/current')
            ->assertOk()
            ->assertJsonPath('vendor_package.is_unlimited_storage', true);
    }

    public function test_stacking_onto_an_unlimited_package_stays_unlimited(): void
    {
        $vendor = User::factory()->create(['role' => 'vendeur']);
        $service = app(PackageSubscriptionService::class);

        $service->applyPackage($vendor, $this->package(['storage_size_mb' => null, 'is_unlimited_storage' => true]));
        $vp = $service->applyPackage($vendor, $this->package())['vendor_package'];

        $this->assertTrue($vp->is_unlimited_storage);
    }

    public function test_admin_creates_an_unlimited_package(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post('/admin/packages', [
            'type' => 'storage',
            'name' => 'Illimité',
            'price' => 10000,
            'duration_days' => 30,
            'storage_size_mb' => 500,
            'is_unlimited_storage' => '1',
            'is_active' => '1',
        ])->assertRedirect();

        $package = Package::where('name', 'Illimité')->firstOrFail();
        $this->assertTrue($package->is_unlimited_storage);
        $this->assertNull($package->storage_size_mb);
    }
}
