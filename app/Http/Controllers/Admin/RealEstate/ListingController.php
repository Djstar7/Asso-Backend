<?php

namespace App\Http\Controllers\Admin\RealEstate;

use App\Http\Controllers\Controller;
use App\Models\RealEstate\Amenity;
use App\Models\RealEstate\Cabinet;
use App\Models\RealEstate\Listing;
use App\Models\RealEstate\Media;
use App\Services\RealEstate\LandlordVerificationService;
use App\Services\RealEstate\ListingEditor;
use App\Services\RealEstate\RealEstatePricingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Annonces immobilières : relecture des maisons des bailleurs, saisie des
 * terrains des cabinets partenaires, mise à la une, photos.
 */
class ListingController extends Controller
{
    public function __construct(
        private ListingEditor $editor,
        private LandlordVerificationService $reviews,
        private RealEstatePricingService $pricing,
    ) {}

    public function index(Request $request)
    {
        $type = $request->query('type');
        $status = $request->query('status');
        $q = trim((string) $request->query('q'));

        $listings = Listing::with(['owner', 'cabinet', 'units', 'media'])
            ->when($request->filled('cabinet_id'), fn ($w) => $w->where('cabinet_id', $request->integer('cabinet_id')))
            ->when($type, fn ($w) => $w->where('type', $type))
            ->when($status, fn ($w) => $w->where('status', $status))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('title', 'like', "%$q%")
                ->orWhere('reference', 'like', "%$q%")->orWhere('city', 'like', "%$q%")->orWhere('district', 'like', "%$q%")))
            ->orderByRaw("CASE status WHEN 'pending_review' THEN 0 ELSE 1 END")
            ->latest()
            ->paginate(25)->withQueryString();

        $counts = Listing::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return view('admin.real_estate.listings.index', [
            'listings' => $listings,
            'counts' => $counts,
            'cabinets' => Cabinet::orderBy('name')->get(),
            'pricing' => $this->pricing,
        ]);
    }

    public function create(Request $request)
    {
        $request->validate(['type' => ['nullable', Rule::in([Listing::TYPE_RENT, Listing::TYPE_SALE, Listing::TYPE_LAND])]]);
        $type = $request->query('type', Listing::TYPE_LAND);

        return view('admin.real_estate.listings.form', $this->formData(new Listing([
            'type' => $type,
            'cabinet_id' => $request->integer('cabinet_id') ?: Cabinet::defaultPartner()?->id,
            'property_kind' => $type === Listing::TYPE_LAND ? 'building_land' : 'house',
            'land_titled' => true,
            'country' => 'CM',
        ])));
    }

    public function store(Request $request)
    {
        $request->validate($this->uploadRules());
        $request->validate(['type' => ['required', Rule::in([Listing::TYPE_RENT, Listing::TYPE_SALE, Listing::TYPE_LAND])]]);
        $type = $request->input('type');
        $data = $request->validate(ListingEditor::rules($type) + [
            'cabinet_id' => ['required', Rule::exists('real_estate_cabinets', 'id')->where(fn ($query) => $query->where('is_active', true))],
        ]);
        $data['units'] = $this->unitsFromRequest($request);
        $listing = $this->editor->save(new Listing([
            'type' => $type, 'status' => Listing::STATUS_DRAFT,
        ]), $data);
        $listing->syncTranslations($request->input('translations'));
        $this->storeUploads($request, $listing);

        return redirect()->route('admin.real-estate.listings.edit', $listing)
            ->with('success', 'Annonce « '.$listing->title.' » créée. Ajoutez ses photos puis publiez-la.');
    }

    /** Saisie rapide : plusieurs localités d'un cabinet en une fois. */
    public function bulkLands(Request $request)
    {
        $data = $request->validate([
            'cabinet_id' => ['required', Rule::exists('real_estate_cabinets', 'id')->where(fn ($query) => $query->where('is_active', true))],
            'city' => ['required', 'string', 'max:100'],
            'deposit_percent' => ['nullable', 'numeric', 'min:1', 'max:100'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.district' => ['nullable', 'string', 'max:100'],
            'rows.*.surfaces' => ['nullable', 'string', 'max:255'],
            'rows.*.price' => ['nullable', 'integer', 'min:0'],
            'rows.*.price_mode' => ['nullable', Rule::in(['per_m2', 'per_lot'])],
            'rows.*.titled' => ['nullable', 'boolean'],
        ]);
        $created = 0;
        foreach ($data['rows'] as $row) {
            $district = trim((string) ($row['district'] ?? ''));
            // « 300, 400, 1 200 » : séparateurs , ; ou /, espaces de milliers tolérés.
            $surfaces = collect(preg_split('/[,;\/]+/', (string) ($row['surfaces'] ?? '')))
                ->map(fn ($s) => (int) preg_replace('/[^0-9]/', '', $s))
                ->filter()->unique()->sort()->values();
            if ($district === '' || $surfaces->isEmpty() || empty($row['price'])) {
                continue;
            }
            $perM2 = ($row['price_mode'] ?? 'per_m2') === 'per_m2';
            $this->editor->save(new Listing([
                'type' => Listing::TYPE_LAND, 'status' => Listing::STATUS_DRAFT,
            ]), [
                'cabinet_id' => $data['cabinet_id'],
                'property_kind' => 'building_land',
                'title' => "Terrains à $district",
                'city' => $data['city'],
                'district' => $district,
                'land_titled' => (bool) ($row['titled'] ?? false),
                'deposit_percent' => $data['deposit_percent'] ?? null,
                'units' => $surfaces->map(fn ($s) => [
                    'label' => number_format($s, 0, ',', ' ').' m²',
                    'surface_min' => $s,
                    'surface_max' => $s,
                    'net_price' => $perM2 ? $s * (int) $row['price'] : (int) $row['price'],
                    'units_total' => 1,
                ])->all(),
            ]);
            $created++;
        }

        return redirect()->route('admin.real-estate.listings.index', ['type' => 'land', 'status' => 'draft'])
            ->with('success', "$created terrain(s) créé(s) en brouillon. Ajoutez les photos puis publiez.");
    }

    public function edit(Listing $listing)
    {
        $listing->load(['owner', 'cabinet', 'units', 'tourRooms.media', 'media', 'amenities', 'reservations']);

        return view('admin.real_estate.listings.form', $this->formData($listing) + [
            'missing' => $this->editor->missingForSubmission($listing),
            'quote' => $listing->isSale() ? null : $this->pricing->quote($listing, $listing->units->sortBy('net_price')->first()),
        ]);
    }

    public function update(Request $request, Listing $listing)
    {
        $request->validate($this->uploadRules());
        $request->validate(['type' => ['sometimes', Rule::in([Listing::TYPE_RENT, Listing::TYPE_SALE, Listing::TYPE_LAND])]]);
        $type = $request->input('type', $listing->type);
        $rules = ListingEditor::rules($type, true);
        $rules['type'] = ['sometimes', Rule::in([Listing::TYPE_RENT, Listing::TYPE_SALE, Listing::TYPE_LAND])];
        $cabinetRule = Rule::exists('real_estate_cabinets', 'id')->where(fn ($query) => $query->where('is_active', true));
        $rules['cabinet_id'] = ['required', $cabinetRule];
        $data = $request->validate($rules);
        $data['amenity_ids'] = $request->input('amenity_ids', []);
        foreach (['furnished', 'is_landlocked', 'has_parking', 'has_fence', 'has_guard', 'land_titled', 'serviced'] as $flag) {
            $data[$flag] = $request->boolean($flag);
        }
        $data['units'] = $this->unitsFromRequest($request);
        $listing = $this->editor->save($listing, $data);
        $listing->syncTranslations($request->input('translations'));
        $this->storeUploads($request, $listing);

        return back()->with('success', 'Annonce enregistrée.');
    }

    public function approve(Listing $listing)
    {
        if (! $listing->isManagedByCabinet() && $listing->owner?->landlord_verification_status !== 'verified') {
            return back()->with('error', "Le profil du bailleur n'est pas encore vérifié : validez d'abord ses pièces.");
        }
        if (! $this->pricing->commissionCabinet($listing)?->user || ($listing->isManagedByCabinet() && ! $listing->cabinet?->user)) {
            return back()->with('error', 'Un cabinet partenaire actif avec un compte bénéficiaire est obligatoire.');
        }
        $missing = $this->editor->missingForSubmission($listing);
        if ($missing) {
            return back()->with('error', 'Annonce incomplète : '.implode(', ', array_map(fn ($m) => __("real_estate.missing.$m"), $missing)).'.');
        }
        $this->reviews->reviewListing($listing, true);

        return back()->with('success', 'Annonce publiée.');
    }

    public function reject(Request $request, Listing $listing)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->reviews->reviewListing($listing, false, $data['reason']);

        return back()->with('success', 'Annonce refusée, le bailleur est prévenu.');
    }

    public function toggleFeatured(Listing $listing)
    {
        $listing->update(['is_featured' => ! $listing->is_featured]);

        return back()->with('success', $listing->is_featured ? 'Annonce mise à la une.' : 'Annonce retirée de la une.');
    }

    public function archive(Listing $listing)
    {
        $listing->update(['status' => $listing->status === Listing::STATUS_ARCHIVED ? Listing::STATUS_DRAFT : Listing::STATUS_ARCHIVED]);

        return back()->with('success', 'Statut mis à jour.');
    }

    public function destroy(Listing $listing)
    {
        $this->deleteListings([$listing->id]);

        return redirect()->route('admin.real-estate.listings.index')
            ->with('success', 'Annonce supprimée. Les réservations et paiements existants sont conservés.');
    }

    public function bulkDestroy(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'integer', 'distinct', Rule::exists('real_estate_listings', 'id')->whereNull('deleted_at')],
        ]);
        $count = $this->deleteListings($data['ids']);

        return redirect()->route('admin.real-estate.listings.index')
            ->with('success', "$count annonce(s) supprimée(s). Les réservations et paiements existants sont conservés.");
    }

    private function deleteListings(array $ids): int
    {
        return DB::transaction(function () use ($ids) {
            $listings = Listing::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            if ($listings->count() !== count($ids)) {
                throw ValidationException::withMessages(['ids' => 'Certaines annonces ne sont plus disponibles. Actualisez la liste.']);
            }
            foreach ($listings as $listing) {
                $listing->update(['status' => Listing::STATUS_ARCHIVED]);
                // La suppression logique garde les pièces et les liens des réservations.
                $listing->delete();
            }

            return $listings->count();
        });
    }

    public function destroyMedia(Listing $listing, Media $media)
    {
        abort_unless($media->listing_id === $listing->id, 404);
        $this->editor->deleteMedia($media);

        return back()->with('success', 'Média supprimé.');
    }

    public function coverMedia(Listing $listing, Media $media)
    {
        abort_unless($media->listing_id === $listing->id && $media->type === 'image', 404);
        $listing->media()->update(['is_cover' => false]);
        $media->update(['is_cover' => true]);

        return back()->with('success', 'Photo principale changée.');
    }

    private function formData(Listing $listing): array
    {
        return [
            'listing' => $listing,
            'cabinets' => Cabinet::orderBy('name')->get(),
            'amenities' => Amenity::orderBy('kind')->orderBy('position')->get(),
            'translations' => $listing->exists ? $listing->translationsPayload() : [],
        ];
    }

    /** Lignes du tableau des logements / lots ; une ligne sans libellé est ignorée. */
    private function unitsFromRequest(Request $request): array
    {
        return collect($request->input('units', []))
            ->filter(fn ($u) => filled($u['label'] ?? null) && filled($u['net_price'] ?? null))
            ->map(fn ($u) => array_filter([
                'id' => $u['id'] ?? null,
                'label' => $u['label'],
                'surface_min' => $u['surface_min'] ?? null,
                'surface_max' => $u['surface_max'] ?? ($u['surface_min'] ?? null),
                'net_price' => (int) $u['net_price'],
                'old_net_price' => $u['old_net_price'] ?? null,
                'capacity' => $u['capacity'] ?? null,
                'kitchen' => $u['kitchen'] ?? null,
                'units_total' => (int) ($u['units_total'] ?? 1),
                'units_available' => (int) ($u['units_available'] ?? ($u['units_total'] ?? 1)),
            ], fn ($v) => $v !== null && $v !== '') + [
                'furnished' => ! empty($u['furnished']),
                'serviced' => ! empty($u['serviced']),
            ])
            ->values()->all();
    }

    public function uploadVideo(Request $request, Listing $listing)
    {
        $request->validate(['video' => ListingEditor::videoRules(true)]);
        $media = $this->editor->addMedia($listing, $request->file('video'), field: 'video');

        return response()->json(['success' => true, 'media' => $media->toApi()], 201);
    }

    private function uploadRules(): array
    {
        return [
            'photos' => ['nullable', 'array', 'max:20'],
            'photos.*' => ['image', 'max:10240'],
            'photo_room_kind' => ['nullable', Rule::in(\App\Models\RealEstate\Room::KINDS)],
            'video' => ListingEditor::videoRules(),
        ];
    }

    private function storeUploads(Request $request, Listing $listing): void
    {
        $room = ! $listing->isLand() && $request->filled('photo_room_kind') && $request->hasFile('photos')
            ? $listing->tourRooms()->firstOrCreate(['kind' => $request->input('photo_room_kind')])
            : null;
        foreach ($request->file('photos', []) as $photo) {
            $this->editor->addMedia($listing, $photo, $room?->id);
        }
        if ($request->hasFile('video')) {
            $this->editor->addMedia($listing, $request->file('video'), field: 'video');
        }
    }
}
