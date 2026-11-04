<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ImportCountry;
use App\Models\ImportShippingOption;
use App\Support\Translation\ContentLocale;
use Illuminate\Http\Request;

class ImportShippingOptionController extends Controller
{
    /**
     * Liste des options d'expédition d'un pays (AJAX)
     */
    public function index(ImportCountry $importCountry)
    {
        $options = ImportShippingOption::where('country_code', $importCountry->code)
            ->whereIn('mode', ['air', 'sea'])
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'success' => true,
            'shipping_options' => $options->map(fn ($option) => $this->present($option))->values(),
            'rules' => $importCountry->only(['air_max_weight_kg', 'air_volumetric_divisor']),
        ]);
    }

    /** Seuil avion et diviseur volumétrique du pays. */
    public function updateRules(Request $request, ImportCountry $importCountry)
    {
        $importCountry->update($request->validate([
            'air_max_weight_kg' => 'required|numeric|min:0',
            'air_volumetric_divisor' => 'required|integer|min:1000|max:10000',
        ]));

        return response()->json(['success' => true]);
    }

    /**
     * Crée (ou met à jour si le mode existe déjà) une option d'expédition
     */
    public function store(Request $request, ImportCountry $importCountry)
    {
        $validated = $this->validated($request);

        // updateOrCreate sur (country_code, mode) : empêche les doublons de mode
        // pour un même pays — si "air" existe déjà, on le met à jour au lieu de dupliquer.
        $option = ImportShippingOption::updateOrCreate(
            ['country_code' => $importCountry->code, 'mode' => $validated['mode']],
            [
                'rate_type'       => $validated['rate_type'],
                'rate_amount'     => $validated['rate_amount'],
                'cost_amount'     => $validated['cost_amount'],
                'margin_type'     => $validated['margin_type'],
                'margin_value'    => $validated['margin_value'],
                'lead_time_days'  => $validated['lead_time_days'],
                'expedition_note' => $validated['expedition_note'] ?? null,
                'carrier'         => $validated['carrier'] ?? null,
                'tracking_url_template' => $validated['tracking_url_template'] ?? null,
                'sort_order'      => $validated['sort_order'] ?? 0,
                'currency'        => 'XAF',
                'is_active'       => true,
                'destinations'    => [\App\Support\ImportHub::CITY],
            ]
        );

        $option->syncTranslations($validated['translations'] ?? null);

        return response()->json(['success' => true, 'shipping_option' => $this->present($option)]);
    }

    /**
     * Met à jour une option existante par son id
     */
    public function update(Request $request, ImportCountry $importCountry, ImportShippingOption $shippingOption)
    {
        $this->ensureBelongsTo($importCountry, $shippingOption);

        $validated = $this->validated($request);

        $shippingOption->update($validated);
        $shippingOption->syncTranslations($validated['translations'] ?? null);

        return response()->json(['success' => true, 'shipping_option' => $this->present($shippingOption)]);
    }

    /**
     * Active/désactive une option sans la supprimer
     */
    public function toggleStatus(ImportCountry $importCountry, ImportShippingOption $shippingOption)
    {
        $this->ensureBelongsTo($importCountry, $shippingOption);

        $shippingOption->update(['is_active' => !$shippingOption->is_active]);

        return response()->json(['success' => true, 'is_active' => $shippingOption->is_active]);
    }

    /**
     * Supprime définitivement une option
     */
    public function destroy(ImportCountry $importCountry, ImportShippingOption $shippingOption)
    {
        $this->ensureBelongsTo($importCountry, $shippingOption);

        $shippingOption->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Le bateau se facture au CBM et l'avion au kilo ; le tarif client est le tarif
     * d'achat saisi plus la marge ASSO.
     */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'mode'             => 'required|in:air,sea',
            'cost_amount'      => 'required|numeric|min:0',
            'margin_type'      => 'nullable|in:percent,amount',
            'margin_value'     => 'nullable|required_with:margin_type|numeric|min:0',
            'lead_time_days'   => 'required|integer|min:1',
            'expedition_note'  => 'nullable|string|max:255',
            'sort_order'       => 'nullable|integer|min:0',
            // Transporteur (DHL, FedEx…) et lien de suivi ({number} = n° saisi à l'expédition)
            'carrier'          => 'nullable|string|max:100',
            'tracking_url_template' => 'nullable|string|max:255',
        ] + ContentLocale::rules(['expedition_note' => 'string|max:255']));

        $validated['margin_type'] = $validated['margin_type'] ?? null;
        $validated['margin_value'] = $validated['margin_type'] ? (float) $validated['margin_value'] : null;
        $validated['rate_type'] = $validated['mode'] === 'sea' ? 'per_cbm' : 'per_kg';
        $validated['rate_amount'] = ImportShippingOption::clientRate((float) $validated['cost_amount'], $validated['margin_type'], $validated['margin_value']);

        return $validated;
    }

    /**
     * L'option doit appartenir au pays de l'URL : sinon 404.
     */
    private function ensureBelongsTo(ImportCountry $importCountry, ImportShippingOption $shippingOption): void
    {
        abort_unless($shippingOption->country_code === $importCountry->code, 404);
    }

    /** Option + ses traductions, pour pré-remplir le formulaire. */
    private function present(ImportShippingOption $option): array
    {
        return $option->toArray() + ['translations' => $option->translationsPayload()];
    }
}
