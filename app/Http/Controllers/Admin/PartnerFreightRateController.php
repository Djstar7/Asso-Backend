<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DelivererCompany;
use App\Models\PartnerFreightRate;
use App\Support\CountryCode;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PartnerFreightRateController extends Controller
{
    public function store(Request $request, DelivererCompany $partner)
    {
        abort_unless($partner->service_type === DelivererCompany::SERVICE_MARITIME, 404);
        $data = $this->validated($request);
        PartnerFreightRate::updateOrCreate([
            'deliverer_company_id' => $partner->id,
            ...array_intersect_key($data, array_flip(['origin_country', 'destination_country', 'transport_mode', 'cargo_category'])),
        ], $data + ['is_active' => true]);

        return back()->with('success', 'Tarif de transport enregistré.');
    }

    public function update(Request $request, DelivererCompany $partner, PartnerFreightRate $rate)
    {
        abort_unless($partner->service_type === DelivererCompany::SERVICE_MARITIME && $rate->deliverer_company_id === $partner->id, 404);
        // La route, le mode et la catégorie identifient le tarif ; seuls les montants et conditions changent.
        $request->merge($rate->only(['origin_country', 'destination_country', 'transport_mode', 'cargo_category']));
        $rate->update($this->validated($request) + ['is_active' => $request->boolean('is_active')]);

        return back()->with('success', 'Tarif de transport mis à jour.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'origin_country' => ['required', Rule::in(array_keys(CountryCode::NAMES))],
            'destination_country' => ['required', 'different:origin_country', Rule::in(array_keys(CountryCode::NAMES))],
            'transport_mode' => 'required|in:sea,air',
            'cargo_category' => 'required|in:general,sensitive',
            'rate_unit' => 'required|in:cbm,kg',
            'rate_amount' => 'required|numeric|min:0.01|max:1000000000000',
            'public_rate_amount' => 'nullable|numeric|gte:rate_amount|max:1000000000000',
            'frequency_days' => 'nullable|integer|min:1|max:365|prohibited_if:origin_country,CN',
            'currency' => 'required|in:XAF,EUR,USD,CNY,CAD,GBP',
            'transit_days_min' => 'nullable|required_with:transit_days_max|integer|min:1|max:730',
            'transit_days_max' => 'nullable|required_with:transit_days_min|integer|gte:transit_days_min|max:730',
            'freight_payment_timing' => 'required|in:departure,arrival',
            'notes' => 'nullable|string|max:3000',
        ]);
    }
}
