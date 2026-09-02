<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ImportCountry;
use App\Models\ImportShippingOption;
use Illuminate\Http\Request;

class ImportShippingOptionController extends Controller
{
    /**
     * Liste des options d'expédition d'un pays (AJAX)
     */
    public function index(ImportCountry $importCountry)
    {
        $options = ImportShippingOption::where('country_code', $importCountry->code)
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'success' => true,
            'shipping_options' => $options,
        ]);
    }

    /**
     * Crée (ou met à jour si le mode existe déjà) une option d'expédition
     */
    public function store(Request $request, ImportCountry $importCountry)
    {
        $validated = $request->validate([
            'mode'             => 'required|in:air,sea,express',
            'rate_type'        => 'required|in:per_kg,flat',
            'rate_amount'      => 'required|numeric|min:0',
            'lead_time_days'   => 'required|integer|min:1',
            'expedition_note'  => 'nullable|string|max:255',
            'sort_order'       => 'nullable|integer|min:0',
        ]);

        // updateOrCreate sur (country_code, mode) : empêche les doublons de mode
        // pour un même pays — si "air" existe déjà, on le met à jour au lieu de dupliquer.
        $option = ImportShippingOption::updateOrCreate(
            ['country_code' => $importCountry->code, 'mode' => $validated['mode']],
            [
                'rate_type'       => $validated['rate_type'],
                'rate_amount'     => $validated['rate_amount'],
                'lead_time_days'  => $validated['lead_time_days'],
                'expedition_note' => $validated['expedition_note'] ?? null,
                'sort_order'      => $validated['sort_order'] ?? 0,
                'currency'        => 'XAF',
                'is_active'       => true,
                'destinations'    => ['Afrique', 'Europe', 'Canada', 'USA'],
            ]
        );

        return response()->json(['success' => true, 'shipping_option' => $option]);
    }

    /**
     * Met à jour une option existante par son id
     */
    public function update(Request $request, ImportShippingOption $shippingOption)
    {
        $validated = $request->validate([
            'mode'             => 'required|in:air,sea,express',
            'rate_type'        => 'required|in:per_kg,flat',
            'rate_amount'      => 'required|numeric|min:0',
            'lead_time_days'   => 'required|integer|min:1',
            'expedition_note'  => 'nullable|string|max:255',
            'sort_order'       => 'nullable|integer|min:0',
        ]);

        $shippingOption->update($validated);

        return response()->json(['success' => true, 'shipping_option' => $shippingOption]);
    }

    /**
     * Active/désactive une option sans la supprimer
     */
    public function toggleStatus(ImportShippingOption $shippingOption)
    {
        $shippingOption->update(['is_active' => !$shippingOption->is_active]);

        return response()->json(['success' => true, 'is_active' => $shippingOption->is_active]);
    }

    /**
     * Supprime définitivement une option
     */
    public function destroy(ImportShippingOption $shippingOption)
    {
        $shippingOption->delete();

        return response()->json(['success' => true]);
    }
}
