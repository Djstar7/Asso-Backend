<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\RealEstate\Alert;
use App\Models\RealEstate\Amenity;
use App\Models\RealEstate\Cabinet;
use App\Models\RealEstate\Listing;
use App\Models\RealEstate\ProjectRequest;
use App\Models\User;
use App\Services\RealEstate\RealEstatePricingService;
use App\Services\SupportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Rubrique Immobilier côté acheteur / locataire : listes, fiches, cabinets,
 * favoris, critères enregistrés, « Confier mon projet » et contact ASSO.
 *
 * Les lectures sont publiques ; un jeton, s'il est présent, sert à marquer
 * les favoris et à lever le masquage des annonces déjà payées.
 */
class RealEstateController extends Controller
{
    public function __construct(private RealEstatePricingService $pricing)
    {
    }

    private function viewer(Request $request): ?User
    {
        return $request->user() ?? $request->user('sanctum');
    }

    private function favoriteIds(?User $user): array
    {
        if (! $user) {
            return [];
        }

        return DB::table('real_estate_favorites')->where('user_id', $user->id)
            ->pluck('listing_id')->map(fn ($id) => (int) $id)->all();
    }

    /** Données de référence : types, équipements, taux affichés. */
    public function meta()
    {
        $amenities = Amenity::where('is_active', true)->orderBy('position')->orderBy('id')->get();

        return response()->json([
            'success' => true,
            'data' => [
                'rent_kinds' => Listing::RENT_KINDS,
                'land_kinds' => Listing::LAND_KINDS,
                'room_kinds' => \App\Models\RealEstate\Room::KINDS,
                'amenities' => $amenities->map->toApi()->values(),
                'cities' => Listing::published()->select('city')->distinct()->orderBy('city')->pluck('city'),
                'rent_commission_rate' => RealEstatePricingService::setting('immo_rent_commission_rate'),
                'visit_window_days' => (int) RealEstatePricingService::setting('immo_visit_window_days'),
                'land_release_days' => (int) RealEstatePricingService::setting('immo_land_release_days'),
                'currency' => 'XAF',
            ],
        ]);
    }

    public function index(Request $request)
    {
        $data = $request->validate([
            'type' => 'nullable|in:rent,land',
            'q' => 'nullable|string|max:100',
            'city' => 'nullable|string|max:100',
            'district' => 'nullable|string|max:100',
            'kind' => 'nullable|string|max:30',
            'price_min' => 'nullable|integer|min:0',
            'price_max' => 'nullable|integer|min:0',
            'rooms_min' => 'nullable|integer|min:0',
            'bedrooms_min' => 'nullable|integer|min:0',
            'surface_min' => 'nullable|integer|min:0',
            'surface_max' => 'nullable|integer|min:0',
            'furnished' => 'nullable|boolean',
            'serviced' => 'nullable|boolean',
            'titled' => 'nullable|boolean',
            'not_landlocked' => 'nullable|boolean',
            'roadside' => 'nullable|boolean',
            'available_by' => 'nullable|date',
            'lease_months' => 'nullable|integer|min:1',
            'cabinet_id' => 'nullable|integer',
            'featured' => 'nullable|boolean',
            'sort' => 'nullable|in:recent,price_asc,price_desc,featured',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        $type = $data['type'] ?? Listing::TYPE_RENT;
        $query = Listing::published()->where('type', $type)
            ->with(['units', 'media', 'tourRooms', 'amenities', 'cabinet']);

        $this->applyFilters($query, $data, $type);

        // Le prix affiché dépend d'un taux global : on filtre et trie sur le
        // prix net équivalent, ce qui garde l'ordre exact.
        $minNet = 'COALESCE((SELECT MIN(u.net_price) FROM real_estate_units u WHERE u.listing_id = real_estate_listings.id), real_estate_listings.net_price)';
        match ($data['sort'] ?? 'recent') {
            'price_asc' => $query->orderByRaw("$minNet asc"),
            'price_desc' => $query->orderByRaw("$minNet desc"),
            'featured' => $query->orderByDesc('is_featured')->orderByDesc('published_at'),
            default => $query->orderByDesc('published_at')->orderByDesc('id'),
        };

        $page = $query->paginate((int) ($data['per_page'] ?? 20));
        $viewer = $this->viewer($request);
        $favorites = $this->favoriteIds($viewer);

        return response()->json([
            'success' => true,
            'data' => collect($page->items())->map(fn (Listing $l) => $l->toCardApi($viewer, $favorites))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    private function applyFilters(Builder $query, array $data, string $type): void
    {
        $markup = 1 + $this->pricing->markupRate($type) / 100;
        $netBound = fn ($displayed) => (int) floor($displayed / $markup);

        if ($q = trim($data['q'] ?? '')) {
            $query->where(fn ($w) => $w->where('city', 'like', "%$q%")
                ->orWhere('district', 'like', "%$q%")
                ->orWhere('title', 'like', "%$q%")
                ->orWhere('reference', $q));
        }
        if (! empty($data['city'])) {
            $query->where('city', 'like', $data['city'].'%');
        }
        if (! empty($data['district'])) {
            $query->where('district', 'like', '%'.$data['district'].'%');
        }
        if (! empty($data['kind'])) {
            $query->where('property_kind', $data['kind']);
        }
        if (isset($data['price_min'])) {
            $min = $netBound($data['price_min']);
            $query->where(fn ($w) => $w->whereHas('units', fn ($u) => $u->where('net_price', '>=', $min))
                ->orWhere(fn ($x) => $x->doesntHave('units')->where('net_price', '>=', $min)));
        }
        if (isset($data['price_max'])) {
            $max = (int) ceil($data['price_max'] / $markup);
            $query->where(fn ($w) => $w->whereHas('units', fn ($u) => $u->where('net_price', '<=', $max))
                ->orWhere(fn ($x) => $x->doesntHave('units')->where('net_price', '<=', $max)));
        }
        if (! empty($data['rooms_min'])) {
            $query->where('rooms', '>=', $data['rooms_min']);
        }
        if (! empty($data['bedrooms_min'])) {
            $query->where('bedrooms', '>=', $data['bedrooms_min']);
        }
        if (isset($data['surface_min'])) {
            $s = (int) $data['surface_min'];
            $query->where(fn ($w) => $w->where('surface_m2', '>=', $s)
                ->orWhereHas('units', fn ($u) => $u->whereRaw('COALESCE(surface_max, surface_min) >= ?', [$s])));
        }
        if (isset($data['surface_max'])) {
            $s = (int) $data['surface_max'];
            $query->where(fn ($w) => $w->where('surface_m2', '<=', $s)
                ->orWhereHas('units', fn ($u) => $u->where('surface_min', '<=', $s)));
        }
        if (! empty($data['furnished'])) {
            $query->where(fn ($w) => $w->where('furnished', true)->orWhereHas('units', fn ($u) => $u->where('furnished', true)));
        }
        if (! empty($data['serviced'])) {
            $query->where(fn ($w) => $w->where('serviced', true)->orWhereHas('units', fn ($u) => $u->where('serviced', true)));
        }
        if (! empty($data['titled'])) {
            $query->where('land_titled', true);
        }
        if (! empty($data['not_landlocked'])) {
            $query->where('is_landlocked', false);
        }
        if (! empty($data['roadside'])) {
            $query->where('road_access', 'roadside');
        }
        if (! empty($data['available_by'])) {
            $query->where(fn ($w) => $w->whereNull('available_from')->orWhere('available_from', '<=', $data['available_by']));
        }
        if (! empty($data['lease_months'])) {
            $query->where(fn ($w) => $w->whereNull('min_lease_months')->orWhere('min_lease_months', '<=', $data['lease_months']));
        }
        if (! empty($data['cabinet_id'])) {
            $query->where('cabinet_id', $data['cabinet_id']);
        }
        if (! empty($data['featured'])) {
            $query->where('is_featured', true);
        }
    }

    public function show(Request $request, int $id)
    {
        $viewer = $this->viewer($request);
        $listing = Listing::with(['units', 'media', 'tourRooms', 'amenities', 'cabinet', 'owner'])->findOrFail($id);

        // Une annonce non publiée n'est visible que de son bailleur.
        if ($listing->status !== Listing::STATUS_PUBLISHED && $viewer?->id !== $listing->owner_id) {
            abort(404);
        }

        if ($viewer?->id !== $listing->owner_id) {
            $listing->increment('views_count');
        }

        return response()->json([
            'success' => true,
            'data' => $listing->toDetailApi($viewer, $this->favoriteIds($viewer)),
        ]);
    }

    /** Aperçu du paiement : montants exacts avant de réserver. */
    public function quote(Request $request, int $id)
    {
        $data = $request->validate([
            'unit_id' => 'nullable|integer',
            'advance_months' => 'nullable|integer|min:1|max:36',
        ]);
        $listing = Listing::published()->with('units')->findOrFail($id);
        $unit = isset($data['unit_id']) ? $listing->units->firstWhere('id', $data['unit_id']) : $listing->units->sortBy('net_price')->first();

        $quote = $this->pricing->quote($listing, $unit, $data['advance_months'] ?? null);
        unset($quote['owner_amount'], $quote['platform_amount']);

        return response()->json(['success' => true, 'data' => $quote + ['unit_id' => $unit?->id, 'currency' => 'XAF']]);
    }

    public function cabinet(Request $request, int $id)
    {
        $cabinet = Cabinet::where('is_active', true)->findOrFail($id);
        $viewer = $this->viewer($request);
        $favorites = $this->favoriteIds($viewer);

        $listings = $cabinet->listings()->published()
            ->with(['units', 'media', 'tourRooms', 'amenities', 'cabinet'])
            ->orderByDesc('is_featured')->orderBy('district')->get();

        return response()->json([
            'success' => true,
            'data' => $cabinet->toApi($viewer, true) + [
                'listings' => $listings->map(fn (Listing $l) => $l->toCardApi($viewer, $favorites))->values(),
            ],
        ]);
    }

    // ---------------------------------------------------------------- favoris

    public function favorites(Request $request)
    {
        $user = $request->user();
        $listings = Listing::published()
            ->whereIn('id', $this->favoriteIds($user))
            ->with(['units', 'media', 'tourRooms', 'amenities', 'cabinet'])
            ->latest('published_at')->get();
        $ids = $listings->pluck('id')->all();

        return response()->json([
            'success' => true,
            'data' => $listings->map(fn (Listing $l) => $l->toCardApi($user, $ids))->values(),
        ]);
    }

    public function addFavorite(Request $request, int $id)
    {
        $listing = Listing::published()->findOrFail($id);
        $listing->favoritedBy()->syncWithoutDetaching([$request->user()->id]);

        return response()->json(['success' => true, 'is_favorite' => true]);
    }

    public function removeFavorite(Request $request, int $id)
    {
        DB::table('real_estate_favorites')
            ->where('user_id', $request->user()->id)->where('listing_id', $id)->delete();

        return response()->json(['success' => true, 'is_favorite' => false]);
    }

    // ---------------------------------------------------------------- critères

    public function alert(Request $request)
    {
        $alert = Alert::where('user_id', $request->user()->id)->first();

        return response()->json(['success' => true, 'data' => $alert?->toApi()]);
    }

    public function saveAlert(Request $request)
    {
        $data = $request->validate([
            'type' => 'nullable|in:rent,land',
            'applicant_status' => 'nullable|in:student,employee,self_employed,other',
            'school' => 'nullable|string|max:150',
            'city' => 'nullable|string|max:100',
            'move_in_date' => 'nullable|date',
            'lease_months' => 'nullable|integer|min:0|max:120',
            'budget_min' => 'nullable|integer|min:0',
            'budget_max' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        $alert = Alert::updateOrCreate(
            ['user_id' => $request->user()->id],
            $data + ['type' => 'rent', 'is_active' => true]
        );

        return response()->json(['success' => true, 'data' => $alert->toApi()]);
    }

    // ---------------------------------------------------------------- projet / contact

    public function storeProjectRequest(Request $request)
    {
        $data = $request->validate([
            'has_land' => 'required|boolean',
            'city' => 'nullable|string|max:100',
            'district' => 'nullable|string|max:100',
            'surface_m2' => 'nullable|integer|min:1',
            'budget' => 'nullable|integer|min:0',
            'name' => 'nullable|string|max:150',
            'phone' => 'nullable|string|max:30',
            'message' => 'nullable|string|max:2000',
        ]);

        $user = $request->user();
        $project = ProjectRequest::create($data + [
            'user_id' => $user->id,
            'name' => $data['name'] ?? trim($user->first_name.' '.$user->last_name),
            'phone' => $data['phone'] ?? $user->phone,
        ]);

        return response()->json(['success' => true, 'data' => ['id' => $project->id]], 201);
    }

    /**
     * « Contacter ASSO » depuis une annonce ou une fiche cabinet : ouvre (ou
     * reprend) la conversation avec le compte support, l'annonce en contexte.
     */
    public function contact(Request $request, int $id)
    {
        $listing = Listing::findOrFail($id);
        $support = SupportService::user();
        $userId = $request->user()->id;

        if (! $support) {
            return response()->json(['success' => false, 'message' => __('real_estate.errors.support_unavailable')], 503);
        }
        if ($support->id === $userId) {
            return response()->json(['success' => false, 'message' => __('conversations.cannot_message_self')], 422);
        }

        $conversation = Conversation::query()
            ->where(fn ($q) => $q->where(fn ($a) => $a->where('user1_id', $userId)->where('user2_id', $support->id))
                ->orWhere(fn ($b) => $b->where('user1_id', $support->id)->where('user2_id', $userId)))
            ->where('real_estate_listing_id', $listing->id)
            ->first()
            ?? Conversation::create([
                'user1_id' => $userId,
                'user2_id' => $support->id,
                'real_estate_listing_id' => $listing->id,
                'last_message_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'conversation' => [
                'id' => $conversation->id,
                'other_user' => [
                    'id' => $support->id,
                    'name' => $support->name,
                    'avatar' => $support->avatar,
                ],
                'real_estate_listing' => $listing->toConversationApi(),
            ],
        ]);
    }
}
