<?php

namespace Tests\Feature;

use App\Models\ImportCountry;
use App\Models\ImportShippingOption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Vérifie l'écran admin de gestion des pays importés (rendu + CRUD).
 */
class AdminImportCountryTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_index_page_renders_with_countries(): void
    {
        ImportCountry::create(['code' => 'CN', 'name' => 'Chine', 'flag' => '🇨🇳', 'sort_order' => 1]);

        $res = $this->actingAs($this->admin())->get('/admin/import-countries');

        $res->assertOk();
        $res->assertSee('Pays importés');
        $res->assertSee('Chine');
    }

    public function test_admin_can_add_country(): void
    {
        $res = $this->actingAs($this->admin())->post('/admin/import-countries', [
            'code' => 'in',
            'name' => 'Inde',
            'flag' => '🇮🇳',
            'sort_order' => 4,
        ]);

        $res->assertRedirect(route('admin.import-countries.index'));
        $this->assertDatabaseHas('import_countries', ['code' => 'IN', 'name' => 'Inde', 'is_active' => true]);
    }

    public function test_duplicate_code_is_rejected(): void
    {
        ImportCountry::create(['code' => 'TR', 'name' => 'Turquie', 'sort_order' => 1]);

        $res = $this->actingAs($this->admin())->post('/admin/import-countries', [
            'code' => 'TR', 'name' => 'Turquie bis',
        ]);

        $res->assertSessionHasErrors('code');
        $this->assertSame(1, ImportCountry::where('code', 'TR')->count());
    }

    public function test_admin_can_toggle_and_delete(): void
    {
        $c = ImportCountry::create(['code' => 'AE', 'name' => 'Dubaï', 'sort_order' => 3, 'is_active' => true]);

        $this->actingAs($this->admin())->patch("/admin/import-countries/{$c->id}/toggle-status");
        $this->assertFalse($c->fresh()->is_active);

        $this->actingAs($this->admin())->delete("/admin/import-countries/{$c->id}");
        $this->assertDatabaseMissing('import_countries', ['id' => $c->id]);
    }

    private function shippingOption(ImportCountry $country): ImportShippingOption
    {
        return ImportShippingOption::create([
            'country_code' => $country->code, 'mode' => 'air', 'rate_type' => 'per_kg',
            'rate_amount' => 5000, 'currency' => 'XAF', 'lead_time_days' => 7, 'is_active' => true,
        ]);
    }

    public function test_admin_can_toggle_update_and_delete_shipping_option(): void
    {
        $c = ImportCountry::create(['code' => 'CN', 'name' => 'Chine', 'sort_order' => 1]);
        $o = $this->shippingOption($c);
        $base = "/admin/import-countries/{$c->id}/shipping-options/{$o->id}";
        $admin = $this->admin();

        $this->actingAs($admin)->patchJson("{$base}/toggle-status")->assertOk()->assertJson(['is_active' => false]);
        $this->assertFalse($o->fresh()->is_active);

        $this->actingAs($admin)->putJson($base, [
            'mode' => 'air', 'rate_type' => 'flat', 'rate_amount' => 20000, 'lead_time_days' => 10,
        ])->assertOk();
        $this->assertSame('flat', $o->fresh()->rate_type);

        $this->actingAs($admin)->deleteJson($base)->assertOk();
        $this->assertDatabaseMissing('import_shipping_options', ['id' => $o->id]);
    }

    public function test_shipping_option_of_another_country_is_not_found(): void
    {
        $cn = ImportCountry::create(['code' => 'CN', 'name' => 'Chine', 'sort_order' => 1]);
        $tr = ImportCountry::create(['code' => 'TR', 'name' => 'Turquie', 'sort_order' => 2]);
        $o = $this->shippingOption($cn);

        $this->actingAs($this->admin())
            ->deleteJson("/admin/import-countries/{$tr->id}/shipping-options/{$o->id}")
            ->assertNotFound();
        $this->assertDatabaseHas('import_shipping_options', ['id' => $o->id]);
    }
}
