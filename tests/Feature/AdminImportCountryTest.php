<?php

namespace Tests\Feature;

use App\Models\ImportCountry;
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
}
