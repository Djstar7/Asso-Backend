<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\ContactClick;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `name` n'est pas une colonne de `users` (accesseur first_name + last_name) :
 * un tri SQL dessus faisait tomber ces pages en 500 sous PostgreSQL. SQLite
 * l'accepte sans trier, d'où la vérification de l'ordre plutôt que du seul statut.
 */
class AdminUserSortingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin', 'first_name' => 'Mireille', 'last_name' => 'Admin']);
        User::factory()->create(['first_name' => 'Zoe', 'last_name' => 'Ngono']);
        User::factory()->create(['first_name' => 'Aline', 'last_name' => 'Tchoua']);
        User::factory()->create(['first_name' => 'Aline', 'last_name' => 'Biya']);
    }

    private function expectedNames(): array
    {
        return ['Aline Biya', 'Aline Tchoua', 'Mireille Admin', 'Zoe Ngono'];
    }

    public function test_announcement_create_lists_users_by_name(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/announcements/create');

        $response->assertOk();
        $this->assertSame($this->expectedNames(), $response->viewData('users')->pluck('name')->all());
    }

    public function test_announcement_edit_lists_users_by_name(): void
    {
        $announcement = Announcement::create(['message' => 'Bonjour', 'channel' => 'email']);

        $response = $this->actingAs($this->admin)->get("/admin/announcements/{$announcement->id}/edit");

        $response->assertOk();
        $this->assertSame($this->expectedNames(), $response->viewData('users')->pluck('name')->all());
    }

    public function test_contact_clicks_lists_sellers_by_name(): void
    {
        $zoe = User::where('first_name', 'Zoe')->first();
        $aline = User::where('last_name', 'Tchoua')->first();

        foreach ([$zoe, $aline] as $seller) {
            ContactClick::create(['user_id' => $this->admin->id, 'seller_id' => $seller->id, 'contact_type' => 'call']);
        }

        $response = $this->actingAs($this->admin)->get('/admin/exchanges/contact-clicks');

        $response->assertOk();
        $this->assertSame(['Aline Tchoua', 'Zoe Ngono'], $response->viewData('sellers')->pluck('name')->all());
    }
}
