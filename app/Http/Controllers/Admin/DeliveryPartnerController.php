<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DelivererCompany;
use App\Models\DeliveryCityGrid;
use App\Models\DeliveryRoute;
use App\Models\Setting;
use App\Services\DeliveryQuoteService;
use App\Support\CountryCode;
use App\Support\DeliveryDelay;
use App\Support\Translation\ContentLocale;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * P4 — Partenaires logistiques : réglages de livraison (rayon des zones, TVA),
 * conditions de chaque partenaire et grilles au poids par trajet
 * (SOLEX interurbain, DHL / FedEx international vers le Cameroun).
 * Seul endroit où se configure la livraison : grille urbaine (zones, quartiers, véhicules) et trajets.
 */
class DeliveryPartnerController extends Controller
{
    public function index()
    {
        $partners = DelivererCompany::withCount(['deliveryZones', 'deliveryRoutes', 'cityGrids'])
            ->with(['cityGrids:id,deliverer_company_id,city', 'deliveryRoutes:id,deliverer_company_id,origin_country,destination_country', 'deliveryZones:id,deliverer_company_id'])
            ->orderByRaw("CASE service_type WHEN 'intercity' THEN 0 WHEN 'international' THEN 1 ELSE 2 END")
            ->orderBy('name')
            ->get();

        return view('admin.delivery_partners.index', [
            'partners' => $partners,
            'radiusKm' => DeliveryQuoteService::radiusKm(),
            'vatRate' => DeliveryQuoteService::vatRate(),
            'defaultWeightKg' => DeliveryQuoteService::defaultProductWeightKg(),
            'commissionRate' => DeliveryQuoteService::commissionRate(),
            'defaultDelay' => DeliveryDelay::defaults(),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $validated = $request->validate([
            'delivery_zone_radius_km' => 'required|numeric|min:0.5|max:500',
            'delivery_vat_rate' => 'required|numeric|min:0|max:100',
            'delivery_default_weight_kg' => 'required|numeric|min:0.001|max:100000',
        ] + DeliveryDelay::rules());

        Setting::set('delivery_zone_radius_km', $validated['delivery_zone_radius_km'], 'string', 'delivery', "Rayon de couverture autour du centre d'une zone de livraison (km)");
        Setting::set('delivery_vat_rate', $validated['delivery_vat_rate'], 'string', 'delivery', 'TVA ajoutée aux grilles de livraison hors taxe (%)');
        Setting::set('delivery_default_weight_kg', $validated['delivery_default_weight_kg'], 'string', 'delivery', "Poids retenu pour un article sans poids renseigné (kg)");
        if (isset($validated['delivery_days_min'], $validated['delivery_days_max'])) {
            DeliveryDelay::setDefaults((int) $validated['delivery_days_min'], (int) $validated['delivery_days_max']);
        }

        return back()->with('success', 'Réglages de livraison enregistrés.');
    }

    public function store(Request $request)
    {
        $validated = $this->validatePartner($request);
        $company = DelivererCompany::create($validated + ['is_active' => false]);
        $company->syncTranslations($validated['translations'] ?? null);

        return redirect()->route('admin.delivery-partners.edit', $company)
            ->with('success', "Partenaire « {$company->name} » créé. Ajoutez ses trajets puis activez-le.");
    }

    public function edit(DelivererCompany $partner)
    {
        $partner->load(['deliveryRoutes' => fn ($q) => $q->orderBy('origin_city')->orderBy('destination_city'), 'cityGrids', 'deliveryZones']);

        return view('admin.delivery_partners.edit', [
            'partner' => $partner,
            'countries' => CountryCode::NAMES,
            'vatRate' => DeliveryQuoteService::vatRate(),
        ]);
    }

    public function update(Request $request, DelivererCompany $partner)
    {
        $validated = $this->validatePartner($request, $partner);
        $partner->update($validated + [
            'is_active' => $request->boolean('is_active'),
        ]);
        $partner->syncTranslations($validated['translations'] ?? null);

        return back()->with('success', 'Partenaire mis à jour.');
    }

    public function storeRoute(Request $request, DelivererCompany $partner)
    {
        $route = $partner->deliveryRoutes()->create($this->validateRoute($request));
        $route->syncTranslations($request->validate(ContentLocale::rules(['lead_time' => 'string|max:60']))['translations'] ?? null);

        return back()->with('success', 'Trajet ajouté.');
    }

    public function updateRoute(Request $request, DelivererCompany $partner, DeliveryRoute $route)
    {
        abort_unless($route->deliverer_company_id === $partner->id, 404);
        $route->update($this->validateRoute($request));
        $route->syncTranslations($request->validate(ContentLocale::rules(['lead_time' => 'string|max:60']))['translations'] ?? null);

        return back()->with('success', 'Trajet « ' . $route->label() . ' » mis à jour.');
    }

    public function destroyRoute(DelivererCompany $partner, DeliveryRoute $route)
    {
        abort_unless($route->deliverer_company_id === $partner->id, 404);
        $route->delete();

        return back()->with('success', 'Trajet supprimé.');
    }

    /** Nouvelle ville couverte en zone à zone : zones vides + véhicules standards à tarifer. */
    public function storeCityGrid(Request $request, DelivererCompany $partner)
    {
        $validated = $request->validate([
            'city' => 'required|string|max:120',
            'zones_count' => 'required|integer|min:1|max:30',
            'template_grid_id' => 'nullable|integer',
        ]);

        if ($partner->cityGrids()->get()->contains(fn ($g) => $g->coversCity($validated['city']))) {
            return back()->withErrors(['city' => "{$partner->name} a déjà une grille pour {$validated['city']}."]);
        }

        // Même modèle qu'une ville existante : véhicules, poids max., délais et prix zone à zone
        // (prix repris pour les zones présentes dans les deux villes). Les quartiers restent propres à la ville.
        $template = !empty($validated['template_grid_id'])
            ? $partner->cityGrids()->find($validated['template_grid_id'])
            : null;
        $count = (int) $validated['zones_count'];
        $vehicles = $template
            ? collect($template->vehicles)->map(fn ($v) => array_merge($v, [
                'prices' => collect($v['prices'] ?? [])->filter(function ($price, $key) use ($count) {
                    [$from, $to] = array_map('intval', explode('-', (string) $key) + [1 => 0]);

                    return $from >= 1 && $from <= $count && $to >= 1 && $to <= $count;
                })->all(),
            ]))->all()
            : [
                ['code' => 'moto', 'label' => 'Moto', 'max_weight_kg' => 30, 'lead_time' => null, 'prices' => []],
                ['code' => 'tricycle', 'label' => 'Tricycle', 'max_weight_kg' => 300, 'lead_time' => null, 'prices' => []],
                ['code' => '600kg', 'label' => 'Camionnette 600 kg', 'max_weight_kg' => 600, 'lead_time' => null, 'prices' => []],
                ['code' => '1t', 'label' => 'Camion 1 tonne', 'max_weight_kg' => 1000, 'lead_time' => null, 'prices' => []],
            ];

        $partner->cityGrids()->create([
            'city' => trim($validated['city']),
            'country' => 'CM',
            'zones' => collect(range(1, $count))
                ->map(fn ($code) => ['code' => $code, 'label' => "Zone {$code}", 'quarters' => []])->all(),
            'vehicles' => $vehicles,
            'is_active' => false,
        ]);

        return back()->with('success', "Grille {$validated['city']} créée (inactive) : ajoutez et placez les quartiers, saisissez les prix, puis activez-la.");
    }

    /**
     * Grille urbaine zone à zone (ex. SOLEX Douala) : quartiers par zone, véhicules
     * (poids max., délai) et prix zone de départ → zone d'arrivée.
     */
    public function updateCityGrid(Request $request, DelivererCompany $partner, DeliveryCityGrid $grid)
    {
        abort_unless($grid->deliverer_company_id === $partner->id, 404);

        $validated = $request->validate([
            'zones' => 'required|array|min:1',
            'zones.*.quarters' => 'nullable|array',
            'zones.*.quarters.*.name' => 'nullable|string|max:120',
            'zones.*.quarters.*.lat' => 'nullable|numeric|between:-90,90',
            'zones.*.quarters.*.lng' => 'nullable|numeric|between:-180,180',
            'vehicles' => 'required|array|min:1',
            'vehicles.*.label' => 'required|string|max:60',
            'vehicles.*.max_weight_kg' => 'nullable|numeric|min:0.1',
            'vehicles.*.lead_time' => 'nullable|string|max:60',
            'vehicles.*.prices' => 'nullable|array',
            'vehicles.*.prices.*' => 'nullable|numeric|min:0',
            'translations.*.vehicles' => 'nullable|array',
            'translations.*.vehicles.*.label' => 'nullable|string|max:60',
            'translations.*.vehicles.*.lead_time' => 'nullable|string|max:60',
            'agency_zone' => 'nullable|integer|min:1',
        ]);

        $zones = collect($grid->zones)->map(function ($zone) use ($validated) {
            // Quartiers géolocalisés : nom + position sur la carte.
            $zone['quarters'] = collect($validated['zones'][$zone['code']]['quarters'] ?? [])
                ->filter(fn ($q) => trim((string) ($q['name'] ?? '')) !== '')
                ->map(fn ($q) => [
                    'name' => trim($q['name']),
                    'lat' => isset($q['lat']) && $q['lat'] !== '' ? round((float) $q['lat'], 6) : null,
                    'lng' => isset($q['lng']) && $q['lng'] !== '' ? round((float) $q['lng'], 6) : null,
                ])
                ->values()
                ->all();

            return $zone;
        })->all();

        $vehicles = collect($grid->vehicles)->map(function ($vehicle) use ($validated) {
            $input = $validated['vehicles'][$vehicle['code']] ?? null;
            if (!$input) {
                return $vehicle;
            }

            return [
                'code' => $vehicle['code'],
                'label' => $input['label'],
                'max_weight_kg' => isset($input['max_weight_kg']) && $input['max_weight_kg'] !== '' ? (float) $input['max_weight_kg'] : null,
                'lead_time' => $input['lead_time'] ?? null,
                'prices' => collect($input['prices'] ?? [])
                    ->filter(fn ($price) => $price !== null && $price !== '')
                    ->map(fn ($price) => (float) $price)
                    ->all(),
            ];
        })->all();

        // Ville → zones → quartiers : nouvelle zone vide à la suite.
        if ($request->boolean('add_zone')) {
            $next = (int) collect($zones)->max('code') + 1;
            $zones[] = ['code' => $next, 'label' => "Zone {$next}", 'quarters' => []];
        }

        $grid->update([
            'zones' => $zones,
            'vehicles' => $vehicles,
            'agency_zone' => $validated['agency_zone'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ]);
        // Libellés et délais des véhicules dans les autres langues : {code: {label, lead_time}}.
        foreach (ContentLocale::targets() as $locale) {
            if (isset($validated['translations'][$locale]['vehicles'])) {
                $grid->setTranslation('vehicles', $locale, collect($validated['translations'][$locale]['vehicles'])
                    ->map(fn ($v) => array_filter(['label' => $v['label'] ?? null, 'lead_time' => $v['lead_time'] ?? null], 'filled'))
                    ->filter()
                    ->all());
            }
        }

        // Lien avec les vendeurs : quartier de chaque boutique de la ville recalculé.
        $linked = 0;
        \App\Models\Shop::whereNotNull('latitude')->each(function (\App\Models\Shop $shop) use ($grid, &$linked) {
            if (!$grid->coversCity($shop->city ?: \App\Support\LocationFormatter::parse($shop->address)[0])) {
                return;
            }
            $before = $shop->quarter;
            $shop->assignDeliveryQuarter();
            if ($shop->isDirty('quarter')) {
                $shop->saveQuietly();
            }
            $linked += $shop->quarter ? 1 : 0;
        });

        return back()->with('success', "Grille {$grid->city} enregistrée. {$linked} boutique(s) rattachée(s) à un quartier.");
    }

    /**
     * Supprime la livraison urbaine d'une ville. Refusé tant qu'une commande en cours en dépend
     * (sa livraison à domicile perdrait sa grille) : désactiver la grille en attendant.
     */
    public function destroyCityGrid(DelivererCompany $partner, DeliveryCityGrid $grid)
    {
        abort_unless($grid->deliverer_company_id === $partner->id, 404);

        $pending = \App\Models\Order::where('delivery_city_grid_id', $grid->id)
            ->whereNotIn('status', ['delivered', 'cancelled'])
            ->count();
        if ($pending > 0) {
            return back()->withErrors(['grid' => "Impossible de supprimer la livraison urbaine à {$grid->city} : {$pending} commande(s) en cours l'utilisent. Décochez « Grille active » pour ne plus la proposer, puis supprimez-la une fois ces commandes livrées."]);
        }

        $city = $grid->city;
        $grid->delete();

        // Boutiques de la ville : quartier retiré, puis recalculé si une autre grille couvre la ville.
        \App\Models\Shop::whereNotNull('quarter')->each(function (\App\Models\Shop $shop) use ($city) {
            if (!\App\Support\CountryCode::sameCity($city, $shop->city ?: \App\Support\LocationFormatter::parse($shop->address)[0])) {
                return;
            }
            $shop->quarter = null;
            $shop->assignDeliveryQuarter();
            $shop->saveQuietly();
        });

        return back()->with('success', "Livraison urbaine à {$city} supprimée.");
    }

    private function validatePartner(Request $request, ?DelivererCompany $partner = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('deliverer_companies', 'name')->ignore($partner?->id)],
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255',
            'description' => 'nullable|string|max:1000',
            'service_type' => ['required', Rule::in(array_keys(DelivererCompany::SERVICE_TYPES))],
            'service_mode' => ['required', Rule::in(array_keys(DelivererCompany::SERVICE_MODES))],
            'conditions' => 'nullable|string|max:3000',
            'max_weight_kg' => 'nullable|numeric|min:0.1',
            'tracking_url_template' => 'nullable|string|max:255',
        ] + ContentLocale::rules(['description' => 'string|max:1000', 'conditions' => 'string|max:3000']));

        return $validated + ['prices_exclude_vat' => $request->boolean('prices_exclude_vat')];
    }

    /**
     * Trajet + grille au poids. Vente de produits : l'international est dans un seul
     * sens, de l'étranger vers le Cameroun.
     */
    private function validateRoute(Request $request): array
    {
        $countries = array_keys(CountryCode::NAMES);
        $validated = $request->validate([
            'origin_country' => ['required', Rule::in($countries)],
            'origin_city' => 'nullable|string|max:120',
            'destination_country' => ['required', Rule::in($countries)],
            'destination_city' => 'nullable|string|max:120',
            'lead_time' => 'nullable|string|max:60',
            'extra_per_kg' => 'nullable|numeric|min:0',
            'ranges' => 'required|array|min:1',
            'ranges.*.label' => 'nullable|string|max:60',
            'ranges.*.label_en' => 'nullable|string|max:60',
            'ranges.*.min' => 'nullable|numeric|min:0',
            'ranges.*.max' => 'nullable|numeric|min:0.001',
            'ranges.*.price' => 'nullable|numeric|min:0',
        ]);

        $ranges = collect($validated['ranges'])
            ->filter(fn ($r) => isset($r['max'], $r['price']) && $r['max'] !== '' && $r['price'] !== '')
            ->map(fn ($r) => [
                'min' => (float) ($r['min'] ?? 0),
                'max' => (float) $r['max'],
                'price' => (float) $r['price'],
                'label' => trim((string) ($r['label'] ?? '')),
                'label_en' => trim((string) ($r['label_en'] ?? '')),
            ])
            ->sortBy('max')
            ->values()
            ->all();

        if ($ranges === []) {
            throw \Illuminate\Validation\ValidationException::withMessages(['ranges' => 'Renseignez au moins une tranche (poids max et prix).']);
        }

        $international = $validated['origin_country'] !== $validated['destination_country'];
        if ($international && $validated['destination_country'] !== 'CM') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'destination_country' => "L'international se fait de l'étranger vers le Cameroun : la destination doit être le Cameroun.",
            ]);
        }

        return [
            'origin_country' => $validated['origin_country'],
            'origin_city' => ($validated['origin_city'] ?? null) ?: null,
            'destination_country' => $validated['destination_country'],
            'destination_city' => ($validated['destination_city'] ?? null) ?: null,
            // « Et vice versa » uniquement à l'intérieur du Cameroun.
            'bidirectional' => !$international && $request->boolean('bidirectional'),
            'lead_time' => $validated['lead_time'] ?? null,
            'pricing_data' => [
                'ranges' => $ranges,
                'extra_per_kg' => (float) ($validated['extra_per_kg'] ?? 0),
            ],
            'is_active' => $request->boolean('is_active', true),
        ];
    }
}
