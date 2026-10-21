<?php

namespace App\Http\Controllers\Admin\RealEstate;

use App\Http\Controllers\Controller;
use App\Models\RealEstate\Amenity;
use App\Models\RealEstate\Cabinet;
use App\Models\RealEstate\ProjectRequest;
use App\Models\Setting;
use App\Services\RealEstate\RealEstatePricingService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Réglages de la rubrique, catalogue d'équipements et demandes de projet. */
class SettingsController extends Controller
{
    public const LABELS = [
        'immo_asso_markup_rate' => ['Part ASSO (%)', 'Taux sur le prix de référence : détermine le prix affiché et les frais ASSO ajoutés à l’avance. Le bénéficiaire reçoit toute l’avance affichée.'],
        'immo_partner_commission_rate' => ['Commission du cabinet partenaire (%)', 'Frais calculés sur le prix de référence, ajoutés à l’avance et versés au cabinet partenaire.'],
        'immo_visit_window_days' => ['Délai de visite (jours)', 'Part du bailleur bloquée pendant ce délai ; débloquée à la confirmation ou à son terme sans litige.'],
        'immo_land_default_deposit_percent' => ['Acompte terrain par défaut (%)', 'Utilisé quand le terrain n\'a pas son propre pourcentage.'],
        'immo_land_release_days' => ['Délai de confirmation terrain (jours)', 'Part du cabinet bloquée pendant ce délai.'],
        'immo_low_stock_threshold' => ['Seuil « Plus que N places »', 'Le badge s\'affiche à partir de ce nombre de places restantes.'],
        'immo_popular_views' => ['Vues pour le badge « Populaire »', ''],
    ];

    public function index()
    {
        $values = [];
        foreach (array_keys(self::LABELS) as $key) {
            $values[$key] = RealEstatePricingService::setting($key);
        }

        return view('admin.real_estate.settings', [
            'values' => $values,
            'labels' => self::LABELS,
            'defaultCabinetId' => Cabinet::defaultPartner()?->id,
            'cabinets' => Cabinet::where('is_active', true)->orderBy('name')->get(),
            'amenities' => Amenity::orderBy('kind')->orderBy('position')->get(),
            'projects' => ProjectRequest::with('user')->latest()->limit(100)->get(),
        ]);
    }

    public function update(Request $request)
    {
        $rules = [];
        foreach (array_keys(self::LABELS) as $key) {
            $rules[$key] = ['required', 'numeric', 'min:0', 'max:'.(str_ends_with($key, '_days') || str_ends_with($key, '_views') || str_ends_with($key, '_threshold') ? 100000 : 100)];
        }
        $rules['immo_default_cabinet_id'] = [
            'required',
            'integer',
            Rule::exists('real_estate_cabinets', 'id')->where(fn ($query) => $query->where('is_active', true)),
        ];
        $data = $request->validate($rules);
        foreach ($data as $key => $value) {
            if (! array_key_exists($key, self::LABELS)) {
                continue;
            }
            Setting::set($key, (string) $value, 'string', 'real_estate', self::LABELS[$key][0]);
        }
        Setting::set('immo_default_cabinet_id', (int) ($data['immo_default_cabinet_id'] ?? 0), 'integer', 'real_estate', 'Cabinet partenaire par défaut');

        return back()->with('success', 'Réglages immobiliers enregistrés.');
    }

    public function storeAmenity(Request $request)
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(Amenity::KINDS)],
            'name' => ['required', 'string', 'max:100'],
            'name_en' => ['nullable', 'string', 'max:100'],
            'icon' => ['nullable', 'string', 'max:60'],
        ]);
        $amenity = Amenity::create([
            'kind' => $data['kind'], 'name' => $data['name'], 'icon' => $data['icon'] ?? null,
            'code' => Str::slug($data['name'], '_').'_'.Str::lower(Str::random(4)),
            'position' => (int) Amenity::max('position') + 1,
        ]);
        $amenity->setTranslation('name', 'en', $data['name_en'] ?? null);

        return back()->with('success', 'Équipement ajouté.');
    }

    public function updateAmenity(Request $request, Amenity $amenity)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'name_en' => ['nullable', 'string', 'max:100'],
            'icon' => ['nullable', 'string', 'max:60'],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);
        $amenity->update([
            'name' => $data['name'], 'icon' => $data['icon'] ?? null,
            'position' => $data['position'] ?? $amenity->position, 'is_active' => $request->boolean('is_active'),
        ]);
        $amenity->setTranslation('name', 'en', $data['name_en'] ?? null);

        return back()->with('success', 'Équipement mis à jour.');
    }

    public function updateProject(Request $request, ProjectRequest $project)
    {
        $data = $request->validate(['status' => ['required', Rule::in(['new', 'contacted', 'closed'])]]);
        $project->update($data);

        return back()->with('success', 'Demande mise à jour.');
    }
}
