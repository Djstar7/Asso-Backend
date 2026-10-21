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
        $agency->update($this->validateAgency($request, $agency) + ['is_active' => $request->boolean('is_active')]);

        return back()->with('success', 'Agence mise à jour.');
    }

    public function assign(Request $request, Order $order, AssoShipmentService $shipments)
    {
        $data = $request->validate(['delivery_agency_id' => 'required|integer|exists:delivery_agencies,id']);
        $shipments->assign($order, DeliveryAgency::findOrFail($data['delivery_agency_id']), $request->user());

        return back()->with('success', 'Agence de retrait affectée.');
    }

    private function validateAgency(Request $request, ?DeliveryAgency $agency = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('delivery_agencies')->ignore($agency?->id)],
            'name' => 'required|string|max:150', 'city' => 'required|string|max:120',
            'address' => 'required|string|max:255', 'phone' => 'required|string|max:40',
        ]);
    }
}
