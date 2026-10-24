<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DelivererCompany;
use App\Models\DeliveryAgency;
use App\Models\Order;
use App\Services\AssoShipmentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeliveryAgencyController extends Controller
{
    public function store(Request $request, DelivererCompany $partner)
    {
        DeliveryAgency::create($this->validateAgency($request) + ['deliverer_company_id' => $partner->id, 'is_active' => true]);

        return back()->with('success', 'Agence de retrait créée.');
    }

    public function update(Request $request, DelivererCompany $partner, DeliveryAgency $agency)
    {
        abort_unless($agency->deliverer_company_id === $partner->id, 404);
        $agency->update($this->validateAgency($request, $agency) + ['is_active' => $request->boolean('is_active'), 'source_dataset' => null]);

        return back()->with('success', 'Agence mise à jour.');
    }

    public function updateCollectionPricing(Request $request, DelivererCompany $partner, DeliveryAgency $agency)
    {
        abort_unless($agency->deliverer_company_id === $partner->id && preg_match('/solex/i', $partner->name), 404);
        $data = $request->validate([
            'collection_price' => 'required|numeric|min:0|max:100000000',
            'collection_max_kg' => 'required|numeric|min:0.001|max:100000',
            'collection_extra_per_kg' => 'required|numeric|min:0|max:100000000',
            'collection_price_per_km' => 'required|numeric|min:0|max:100000000',
        ]);
        $agency->update(['collection_pricing' => [
            'ranges' => [['min' => 0, 'max' => (float) $data['collection_max_kg'], 'price' => (float) $data['collection_price']]],
            'extra_per_kg' => (float) $data['collection_extra_per_kg'], 'price_per_km' => (float) $data['collection_price_per_km'],
        ]]);
        return back()->with('success', 'Tarif de collecte Solex enregistré. Il sera ajouté aux futurs devis DHL/FedEx de cette agence.');
    }

    public function assign(Request $request, Order $order, AssoShipmentService $shipments)
    {
        $data = $request->validate(['delivery_agency_id' => 'required|integer|exists:delivery_agencies,id']);
        $shipments->assign($order, DeliveryAgency::findOrFail($data['delivery_agency_id']), $request->user());

        return back()->with('success', 'Agence de retrait affectée.');
    }

    private function validateAgency(Request $request, ?DeliveryAgency $agency = null): array
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('delivery_agencies')->ignore($agency?->id)],
            'name' => 'required|string|max:150', 'city' => 'required|string|max:120',
            'address' => 'required|string|max:255', 'phone' => 'required|string|max:40',
            'country' => ['nullable', \Illuminate\Validation\Rule::in(array_keys(\App\Support\CountryCode::NAMES))],
            'latitude' => 'nullable|numeric|between:-90,90', 'longitude' => 'nullable|numeric|between:-180,180',
            'source_url' => 'nullable|url:http,https|max:512',
            'coordinate_precision' => ['nullable', Rule::in(['precise', 'published', 'street', 'city_center'])],
            'is_international_reference' => 'sometimes|boolean', 'is_provisional' => 'sometimes|boolean',
        ]);
        foreach (['is_international_reference', 'is_provisional'] as $field) {
            if ($request->has('reference_fields')) {
                $validated[$field] = $request->boolean($field);
            }
        }
        return $validated;
    }
}
