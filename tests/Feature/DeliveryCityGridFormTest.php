<?php

namespace Tests\Feature;

use App\Models\DelivererCompany;
use App\Models\DeliveryCityGrid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryCityGridFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_activate_a_grid_without_quarters_and_remove_the_last_quarter(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $partner = DelivererCompany::create([
            'name' => 'SOLEX',
            'service_type' => DelivererCompany::SERVICE_INTERCITY,
            'service_mode' => DelivererCompany::MODE_AGENCY,
            'is_active' => true,
        ]);
        $zones = collect(range(1, 7))->map(fn ($code) => [
            'code' => $code, 'label' => "Zone {$code}", 'quarters' => [],
        ])->all();
        $vehicle = ['code' => 'moto', 'label' => 'Moto', 'max_weight_kg' => 30, 'prices' => ['1-1' => 1000, '1-2' => 1000]];
        $grid = DeliveryCityGrid::create([
            'deliverer_company_id' => $partner->id,
            'city' => 'Yaoundé', 'country' => 'CM',
            'zones' => $zones, 'vehicles' => [$vehicle], 'is_active' => false,
        ]);

        $page = $this->actingAs($admin)->get(route('admin.delivery-partners.edit', $partner))->assertOk();
        // Les zones vides doivent exister dans les données réellement envoyées par le formulaire.
        $dom = new \DOMDocument;
        @$dom->loadHTML($page->getContent());
        $xpath = new \DOMXPath($dom);
        $fields = [];
        foreach ($xpath->query('//input[@type="hidden" and starts-with(@name, "zones[")]') as $input) {
            $fields[$input->getAttribute('name')] = $input->getAttribute('value');
        }
        parse_str(http_build_query($fields), $payload);
        $this->assertCount(7, $payload['zones'] ?? []);
        $payload += ['vehicles' => ['moto' => $vehicle], 'agency_zone' => '1', 'is_active' => '1'];

        $url = route('admin.delivery-partners.city-grids.update', [$partner, $grid]);
        $this->put($url, $payload)->assertRedirect()->assertSessionHasNoErrors();
        $grid->refresh();
        $this->assertTrue($grid->is_active);
        $this->assertSame(1, $grid->agency_zone);
        $this->assertSame($zones, $grid->zones);
        $this->assertEquals($vehicle['prices'], $grid->vehicles[0]['prices']);

        // Retirer le dernier quartier laisse le champ caché de la zone dans le formulaire.
        $withQuarter = $zones;
        $withQuarter[0]['quarters'] = [['name' => 'Bastos', 'lat' => 3.9, 'lng' => 11.5]];
        $grid->update(['zones' => $withQuarter]);
        $this->put($url, $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($zones, $grid->fresh()->zones);
    }
}
