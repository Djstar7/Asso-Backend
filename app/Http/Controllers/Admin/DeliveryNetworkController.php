<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DelivererCompany;
use App\Models\DeliveryCityGrid;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DeliveryNetworkController extends Controller
{
    public function update(Request $request, DelivererCompany $partner)
    {
        $partner->update(['territorial_network_enabled' => $request->boolean('territorial_network_enabled')]);

        return back()->with('success', 'Mode territorial enregistré. Seuls les cercles, grilles et liaisons actifs sont proposés.');
    }

    public function updateGrid(Request $request, DelivererCompany $partner, DeliveryCityGrid $grid)
    {
        abort_unless($grid->deliverer_company_id === $partner->id && $grid->department_code, 404);
        $input = $request->validate([
            'zones' => 'required|array',
            'zones.*.center_latitude' => 'required|numeric|between:-90,90',
            'zones.*.center_longitude' => 'required|numeric|between:-180,180',
            'zones.*.radius_km' => 'required|numeric|min:0.1|max:1000',
            'zones.*.priority' => 'nullable|integer|min:0|max:1000',
            'zones.*.is_active' => 'nullable|boolean',
            'agency_zone' => 'required|integer|min:1',
            'vehicles' => 'required|array',
            'vehicles.*.label' => 'required|string|max:60',
            'vehicles.*.max_weight_kg' => 'required|numeric|min:0.1',
            'vehicles.*.lead_time' => 'nullable|string|max:60',
            'vehicles.*.prices' => 'required|array',
            'vehicles.*.prices.*' => 'nullable|numeric|min:0',
        ]);
        $zones = [];
        foreach ($grid->zones as $zone) {
            $data = $input['zones'][$zone['code']] ?? null;
            if (! $data) {
                throw ValidationException::withMessages(['zones' => 'Tous les arrondissements doivent être transmis.']);
            }
            $zones[] = array_replace($zone, [
                'center_latitude' => (float) $data['center_latitude'], 'center_longitude' => (float) $data['center_longitude'],
                'radius_km' => (float) $data['radius_km'], 'priority' => (int) ($data['priority'] ?? 0),
                'is_active' => ! empty($data['is_active']), 'provisional' => empty($data['is_active']),
            ]);
        }
        if (! collect($zones)->contains('code', (int) $input['agency_zone'])) {
            throw ValidationException::withMessages(['agency_zone' => 'Zone de relais inconnue.']);
        }
        $vehicles = [];
        foreach ($grid->vehicles as $vehicle) {
            $data = $input['vehicles'][$vehicle['code']] ?? null;
            if (! $data) {
                throw ValidationException::withMessages(['vehicles' => 'Véhicule manquant.']);
            }
            $vehicle = array_replace($vehicle, collect($data)->only(['label', 'max_weight_kg', 'lead_time'])->all());
            $vehicle['prices'] = collect($data['prices'])->reject(fn ($v) => $v === null || $v === '')->map(fn ($v) => (float) $v)->all();
            $vehicles[] = $vehicle;
        }
        $grid->update(['zones' => $zones, 'vehicles' => $vehicles,
            'agency_zone' => (int) $input['agency_zone'], 'is_active' => $request->boolean('is_active')]);

        return back()->with('success', 'Centres, rayons et tarifs enregistrés. Les quartiers ne conditionnent pas la couverture.');
    }
}
