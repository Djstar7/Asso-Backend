<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ImportCountry;
use App\Support\ImportHub;
use Illuminate\Http\Request;

/**
 * Gestion des pays d'origine des produits importés (Chine, Turquie, Dubaï…).
 * Alimente la section "Produits importés" de l'app mobile via
 * l'endpoint public GET /v1/import-countries.
 */
class ImportCountryController extends Controller
{
    public function index()
    {
        $countries = ImportCountry::orderBy('sort_order')->orderBy('name')->paginate(20);
        // Entrepôt de réception à Douala, d'où SOLEX livre le client.
        $hub = ImportHub::shop();
        $hubZone = $hub ? ImportHub::deliveryZoneLabel($hub) : null;

        return view('admin.import_countries.index', compact('countries', 'hub', 'hubZone'));
    }

    /** Crée la boutique « ASSO Import Douala » et y rattache les produits en gros. */
    public function createHub()
    {
        $hub = ImportHub::ensureShop();
        if (!$hub) {
            return back()->with('error', 'Compte plateforme ASSO introuvable : impossible de créer la boutique de réception.');
        }
        $moved = ImportHub::attachWholesaleProducts($hub);

        return redirect()->route('admin.import-countries.index')
            ->with('success', "Boutique « {$hub->name} » prête ({$moved} produit(s) en gros rattaché(s)). Placez-la sur la carte pour le calcul SOLEX.");
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|size:2|unique:import_countries,code',
            'name' => 'required|string|max:255',
            'flag' => 'nullable|string|max:16',
            'sort_order' => 'nullable|integer|min:0',
        ], [
            'code.size' => 'Le code pays doit faire 2 lettres (ISO2, ex. CN, TR, AE).',
            'code.unique' => 'Ce code pays existe déjà.',
        ]);

        ImportCountry::create([
            'code' => strtoupper($validated['code']),
            'name' => $validated['name'],
            'flag' => $validated['flag'] ?? null,
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_active' => true,
        ]);

        return redirect()->route('admin.import-countries.index')
            ->with('success', 'Pays ajouté avec succès.');
    }

    public function update(Request $request, ImportCountry $importCountry)
    {
        $validated = $request->validate([
            'code' => 'required|string|size:2|unique:import_countries,code,' . $importCountry->id,
            'name' => 'required|string|max:255',
            'flag' => 'nullable|string|max:16',
            'sort_order' => 'nullable|integer|min:0',
        ], [
            'code.size' => 'Le code pays doit faire 2 lettres (ISO2, ex. CN, TR, AE).',
            'code.unique' => 'Ce code pays existe déjà.',
        ]);

        $importCountry->update([
            'code' => strtoupper($validated['code']),
            'name' => $validated['name'],
            'flag' => $validated['flag'] ?? null,
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        return redirect()->route('admin.import-countries.index')
            ->with('success', 'Pays mis à jour avec succès.');
    }

    public function toggleStatus(ImportCountry $importCountry)
    {
        $importCountry->update(['is_active' => !$importCountry->is_active]);

        return redirect()->route('admin.import-countries.index')
            ->with('success', 'Statut mis à jour.');
    }

    public function destroy(ImportCountry $importCountry)
    {
        $importCountry->delete();

        return redirect()->route('admin.import-countries.index')
            ->with('success', 'Pays supprimé.');
    }
}
