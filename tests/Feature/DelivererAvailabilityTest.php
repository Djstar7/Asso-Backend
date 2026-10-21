<?php

namespace Tests\Feature;

use App\Models\DelivererCompany;
use App\Models\DelivererCodeSync;
use App\Models\DelivererSyncCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DelivererAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_starts_online_and_manual_choice_survives_dashboard_reloads(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $company = DelivererCompany::create(['name' => 'SOLEX', 'is_active' => true]);
        $code = DelivererSyncCode::create([
            'company_id' => $company->id, 'sync_code' => 'SOLX-0000-0001',
            'sent_via' => 'email', 'sent_at' => now(), 'expires_at' => now()->addDays(30),
        ]);
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/deliverer/sync-profile', [
            'sync_code' => $code->sync_code,
        ])->assertOk();
        $this->assertTrue(DelivererCodeSync::where('user_id', $user->id)->firstOrFail()->is_online);
        $this->getJson('/api/v1/delivery/dashboard')->assertOk()->assertJsonPath('is_online', true);
        $this->putJson('/api/v1/delivery/availability', ['is_online' => false])
            ->assertOk()->assertJsonPath('is_online', false);
        $this->getJson('/api/v1/delivery/dashboard')->assertOk()->assertJsonPath('is_online', false);
        $this->getJson('/api/v1/delivery/dashboard')->assertOk()->assertJsonPath('is_online', false);
        $this->putJson('/api/v1/delivery/availability', ['is_online' => true])->assertOk();
        $this->getJson('/api/v1/delivery/dashboard')->assertJsonPath('is_online', true);
    }

    public function test_unsynced_user_cannot_change_availability(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->putJson('/api/v1/delivery/availability', ['is_online' => true])->assertForbidden();
    }
}
