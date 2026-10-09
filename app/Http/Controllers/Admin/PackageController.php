<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Support\Translation\ContentLocale;
use Illuminate\Http\Request;

class PackageController extends Controller
{
    /**
     * Afficher la liste des packages
     */
    public function index(Request $request)
    {
        $query = Package::query();

        // Filtre par statut
        if ($request->filled('status')) {
            $query->where('is_active', $request->status === 'active');
        }

        // Filtre par type
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        // Si un filtre de type est appliqué, utiliser la pagination normale
        if ($request->filled('type')) {
            $packages = $query->ordered()->paginate(12);
            $packagesByType = null;
        } else {
            // Sinon, grouper par type
            $packages = null;
            $packagesByType = [
                'storage' => Package::ofType('storage')
                    ->when($request->filled('status'), function($q) use ($request) {
                        $q->where('is_active', $request->status === 'active');
                    })
                    ->ordered()
                    ->get(),
                'boost' => Package::ofType('boost')
                    ->when($request->filled('status'), function($q) use ($request) {
                        $q->where('is_active', $request->status === 'active');
                    })
                    ->ordered()
                    ->get(),
                'certification' => Package::ofType('certification')
                    ->when($request->filled('status'), function($q) use ($request) {
                        $q->where('is_active', $request->status === 'active');
                    })
                    ->ordered()
                    ->get(),
            ];
        }

        // Statistiques
        $stats = [
            'total' => Package::count(),
            'active' => Package::active()->count(),
            'popular' => Package::popular()->count(),
            'storage' => Package::ofType('storage')->count(),
            'boost' => Package::ofType('boost')->count(),
            'certification' => Package::ofType('certification')->count(),
        ];

        return view('admin.packages.index', compact('packages', 'packagesByType', 'stats'));
    }

    /**
     * Afficher le formulaire de création
     */
    public function create()
    {
        return view('admin.packages.create');
    }

    /**
     * Enregistrer un nouveau package
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'type' => 'required|in:storage,boost,certification',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'duration_days' => 'required|integer|min:1|max:365',
            'storage_size_mb' => 'nullable|integer|min:1',
            // Obligatoire pour un boost : un forfait sans quota d'audience se
            // vendrait puis échouerait à l'activation, côté client.
            'reach_users' => 'required_if:type,boost|nullable|integer|min:1|max:10000000',
            'benefits' => 'nullable|array',
            'benefits.*' => 'nullable|string|max:500',
            'is_active' => 'boolean',
            'is_popular' => 'boolean',
            'order' => 'nullable|integer|min:0',
        ] + ContentLocale::rules(self::TRANSLATION_RULES) + ['translations.*.benefits.*' => 'nullable|string|max:500']);

        $validated['is_active'] = $request->has('is_active');
        $validated['is_popular'] = $request->has('is_popular');

        // Filtrer les bénéfices vides
        if (isset($validated['benefits'])) {
            $validated['benefits'] = array_values(array_filter($validated['benefits'], fn($b) => !empty(trim($b))));
            if (empty($validated['benefits'])) {
                $validated['benefits'] = null;
            }
        }

        // Nettoyer les champs non pertinents selon le type
        if ($validated['type'] !== 'storage') {
            $validated['storage_size_mb'] = null;
        }
        if ($validated['type'] !== 'boost') {
            $validated['reach_users'] = null;
        }
        if ($validated['type'] !== 'certification') {
            $validated['benefits'] = null;
        }

        $package = Package::create($validated);
        $package->syncTranslations($this->translations($validated));

        return redirect()->route('admin.packages.index')
            ->with('success', 'Package créé avec succès.');
    }

    /**
     * Afficher les détails d'un package
     */
    public function show(Package $package)
    {
        return view('admin.packages.show', compact('package'));
    }

    /**
     * Afficher le formulaire d'édition
     */
    public function edit(Package $package)
    {
        return view('admin.packages.edit', compact('package'));
    }

    /**
     * Mettre à jour un package
     */
    public function update(Request $request, Package $package)
    {
        $validated = $request->validate([
            'type' => 'required|in:storage,boost,certification',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'duration_days' => 'required|integer|min:1|max:365',
            'storage_size_mb' => 'nullable|integer|min:1',
            // Obligatoire pour un boost : un forfait sans quota d'audience se
            // vendrait puis échouerait à l'activation, côté client.
            'reach_users' => 'required_if:type,boost|nullable|integer|min:1|max:10000000',
            'benefits' => 'nullable|array',
            'benefits.*' => 'nullable|string|max:500',
            'is_active' => 'boolean',
            'is_popular' => 'boolean',
            'order' => 'nullable|integer|min:0',
        ] + ContentLocale::rules(self::TRANSLATION_RULES) + ['translations.*.benefits.*' => 'nullable|string|max:500']);

        $validated['is_active'] = $request->has('is_active');
        $validated['is_popular'] = $request->has('is_popular');

        // Filtrer les bénéfices vides
        if (isset($validated['benefits'])) {
            $validated['benefits'] = array_values(array_filter($validated['benefits'], fn($b) => !empty(trim($b))));
            if (empty($validated['benefits'])) {
                $validated['benefits'] = null;
            }
        }

        // Nettoyer les champs non pertinents selon le type
        if ($validated['type'] !== 'storage') {
            $validated['storage_size_mb'] = null;
        }
        if ($validated['type'] !== 'boost') {
            $validated['reach_users'] = null;
        }
        if ($validated['type'] !== 'certification') {
            $validated['benefits'] = null;
        }

        $package->update($validated);
        $package->syncTranslations($this->translations($validated));

        return redirect()->route('admin.packages.index')
            ->with('success', 'Package mis à jour avec succès.');
    }

    private const TRANSLATION_RULES = [
        'name' => 'string|max:255',
        'description' => 'string',
        'benefits' => 'array',
    ];

    /** Traductions du formulaire ; les avantages ne valent que pour une certification. */
    private function translations(array $validated): array
    {
        $translations = $validated['translations'] ?? [];
        if ($validated['type'] !== 'certification') {
            foreach (ContentLocale::targets() as $locale) {
                $translations[$locale]['benefits'] = null;
            }
        }

        return $translations;
    }

    /**
     * Supprimer un package
     */
    public function destroy(Package $package)
    {
        $package->delete();

        return redirect()->route('admin.packages.index')
            ->with('success', 'Package supprimé avec succès.');
    }

    /**
     * Activer/désactiver un package
     */
    public function toggleActive(Package $package)
    {
        $package->update(['is_active' => !$package->is_active]);

        return redirect()->back()
            ->with('success', 'Statut du package mis à jour.');
    }

    /**
     * Marquer/démarquer comme populaire
     */
    public function togglePopular(Package $package)
    {
        $package->update(['is_popular' => !$package->is_popular]);

        return redirect()->back()
            ->with('success', 'Package ' . ($package->is_popular ? 'marqué' : 'démarqué') . ' comme populaire.');
    }
}
