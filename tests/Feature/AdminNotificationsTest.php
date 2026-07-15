<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Vérifie que le badge de notifications du back-office reflète de vraies
 * tâches en attente (au lieu d'un compteur codé en dur).
 */
class AdminNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_no_notifications_when_nothing_pending(): void
    {
        $res = $this->actingAs($this->admin())->get('/admin/import-countries');

        $res->assertOk();
        $res->assertSee('Aucune nouvelle notification');
    }

    public function test_pending_shop_produces_a_notification(): void
    {
        $vendor = User::factory()->create(['role' => 'vendeur']);
        // Boutique en attente : ni vérifiée ni rejetée
        Shop::create([
            'user_id' => $vendor->id,
            'name' => 'Boutique en attente',
            'slug' => 'boutique-attente-' . $vendor->id,
            'status' => 'active',
        ]);

        $res = $this->actingAs($this->admin())->get('/admin/import-countries');

        $res->assertOk();
        $res->assertSee('en attente de vérification');
        $res->assertDontSee('Aucune nouvelle notification');
    }
}
