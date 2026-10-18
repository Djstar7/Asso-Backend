<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ParcelNature;
use App\Models\Setting;
use App\Support\Translation\ContentLocale;
use Illuminate\Http\Request;

/**
 * Natures de colis Diaspo et taux d'assurance (avion et maritime), plus le
 * seuil indicatif avion / maritime affiché dans l'app.
 */
class ParcelNatureController extends Controller
{
    public function index()
    {
        $natures = ParcelNature::orderBy('sort_order')->orderBy('name')->get();
        $airMaxKgHint = (float) Setting::get('diaspo_air_max_kg_hint', 23);

        return view('admin.diaspo.parcel_natures.index', compact('natures', 'airMaxKgHint'));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $nature = ParcelNature::create($data + ['is_active' => true]);
        $nature->syncTranslations($request->input('translations'));

        return back()->with('success', 'Nature ajoutée.');
    }

    public function update(Request $request, ParcelNature $parcelNature)
    {
        $parcelNature->update($this->validated($request));
        $parcelNature->syncTranslations($request->input('translations'));

        return back()->with('success', 'Nature mise à jour.');
    }

    public function toggleStatus(ParcelNature $parcelNature)
    {
        $parcelNature->update(['is_active' => !$parcelNature->is_active]);

        return back()->with('success', 'Statut mis à jour.');
    }

    public function destroy(ParcelNature $parcelNature)
    {
        // Les réservations gardent leur supplément ; leur nature passe à null.
        $parcelNature->delete();

        return back()->with('success', 'Nature supprimée.');
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate(['air_max_kg_hint' => 'required|numeric|min:1|max:1000']);
        Setting::set('diaspo_air_max_kg_hint', $data['air_max_kg_hint'], 'string', 'diaspo', 'Seuil indicatif avion / maritime (kg)');

        return back()->with('success', 'Seuil enregistré.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'insurance_rate' => 'required|numeric|min:0|max:100',
            'sort_order' => 'nullable|integer|min:0',
        ] + ContentLocale::rules(['name' => 'string|max:255']));

        return [
            'name' => $data['name'],
            'insurance_rate' => $data['insurance_rate'],
            'sort_order' => $data['sort_order'] ?? 0,
        ];
    }
}
