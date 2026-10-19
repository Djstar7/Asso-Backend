<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RealEstate\LandlordVerification;
use App\Models\RealEstate\Listing;
use App\Models\RealEstate\Media;
use App\Services\RealEstate\LandlordVerificationService;
use App\Services\RealEstate\ListingEditor;
use App\Services\RealEstate\RealEstatePricingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Espace bailleur : vérification du profil, puis publication des maisons à
 * louer. Une annonce est relue par ASSO avant d'être visible ; la modifier
 * une fois publiée la renvoie en relecture.
 */
class RealEstateLandlordController extends Controller
{
    public function __construct(
        private LandlordVerificationService $verifications,
        private ListingEditor $editor,
        private RealEstatePricingService $pricing,
    ) {
    }

    public function verification(Request $request)
    {
        $user = $request->user();
        $v = LandlordVerification::where('user_id', $user->id)->first();

        return response()->json(['success' => true, 'data' => [
            'status' => $user->landlord_verification_status ?: 'none',
            'rejection_reason' => $v?->rejection_reason,
            'submitted_at' => $v?->created_at?->toIso8601String(),
            'can_publish' => in_array($user->landlord_verification_status, ['pending', 'verified'], true),
            'proof_types' => LandlordVerification::PROOF_TYPES,
        ]]);
    }

    public function submitVerification(Request $request)
    {
        $data = $request->validate([
            'id_document_type' => ['required', Rule::in(['cni', 'passport'])],
            'id_front' => ['required', 'image', 'max:8192'],
            'id_back' => ['nullable', 'image', 'max:8192'],
            'ownership_proof_type' => ['required', Rule::in(LandlordVerification::PROOF_TYPES)],
            'ownership_proof' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
            'video' => ['required', 'file', 'mimetypes:video/mp4,video/quicktime,video/3gpp,video/webm', 'max:102400'],
        ]);
        $user = $request->user();
        if ($user->landlord_verification_status === 'verified') {
            return response()->json(['success' => false, 'message' => __('real_estate.errors.already_verified')], 422);
        }

        $this->verifications->submit($user, $data['id_document_type'], $data['ownership_proof_type'], [
            'id_front' => $request->file('id_front'),
            'id_back' => $request->file('id_back'),
            'ownership_proof' => $request->file('ownership_proof'),
            'video' => $request->file('video'),
        ]);

        return $this->verification($request);
    }

    // ---------------------------------------------------------------- annonces

    private function ownListing(Request $request, int $id): Listing
    {
        return Listing::where('owner_id', $request->user()->id)->where('type', Listing::TYPE_RENT)->findOrFail($id);
    }

    private function payload(Listing $listing, Request $request): array
    {
        $listing->loadMissing(['units', 'media', 'tourRooms', 'amenities', 'owner', 'cabinet']);

        return $listing->toDetailApi($request->user()) + [
            'owner_description' => $listing->owner_description,
            'address' => $listing->address,
            'latitude' => $listing->latitude,
            'longitude' => $listing->longitude,
            'net_price' => $listing->net_price,
            'old_net_price' => $listing->old_net_price,
            'amenity_ids' => $listing->amenities->pluck('id')->values(),
            'units_net' => $listing->units->map(fn ($u) => [
                'id' => $u->id, 'label' => $u->label, 'surface_min' => $u->surface_min, 'surface_max' => $u->surface_max,
                'net_price' => $u->net_price, 'old_net_price' => $u->old_net_price, 'capacity' => $u->capacity,
                'kitchen' => $u->kitchen, 'furnished' => $u->furnished, 'units_total' => $u->units_total,
                'units_available' => $u->units_available, 'available_from' => $u->available_from?->toDateString(),
            ])->values(),
            'rejection_reason' => $listing->rejection_reason,
            'missing' => $this->editor->missingForSubmission($listing),
        ];
    }

    public function listings(Request $request)
    {
        $listings = Listing::where('owner_id', $request->user()->id)
            ->with(['units', 'media', 'tourRooms', 'amenities', 'cabinet'])
            ->latest()->get();

        return response()->json(['success' => true, 'data' => $listings->map(fn (Listing $l) => $l->toCardApi($request->user()) + [
            'status' => $l->status,
            'rejection_reason' => $l->rejection_reason,
            'views_count' => $l->views_count,
        ])->values()]);
    }

    public function show(Request $request, int $id)
    {
        return response()->json(['success' => true, 'data' => $this->payload($this->ownListing($request, $id), $request)]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (! in_array($user->landlord_verification_status, ['pending', 'verified'], true)) {
            return response()->json(['success' => false, 'message' => __('real_estate.errors.verification_required')], 403);
        }
        $data = $request->validate(ListingEditor::rules(Listing::TYPE_RENT));
        $listing = $this->editor->save(new Listing([
            'type' => Listing::TYPE_RENT, 'owner_id' => $user->id, 'status' => Listing::STATUS_DRAFT,
        ]), $data);

        return response()->json(['success' => true, 'data' => $this->payload($listing, $request)], 201);
    }

    public function update(Request $request, int $id)
    {
        $listing = $this->ownListing($request, $id);
        $data = $request->validate(ListingEditor::rules(Listing::TYPE_RENT, true));
        // Une annonce déjà relue repart en relecture après modification.
        if (in_array($listing->status, [Listing::STATUS_PUBLISHED, Listing::STATUS_REJECTED], true)) {
            $data['status'] = Listing::STATUS_PENDING;
        }
        $listing = $this->editor->save($listing, $data);

        return response()->json(['success' => true, 'data' => $this->payload($listing, $request)]);
    }

    public function destroy(Request $request, int $id)
    {
        $listing = $this->ownListing($request, $id);
        if ($listing->reservations()->whereIn('status', ['paid', 'disputed'])->exists()) {
            return response()->json(['success' => false, 'message' => __('real_estate.errors.has_active_reservation')], 422);
        }
        $listing->update(['status' => Listing::STATUS_ARCHIVED]);
        $listing->delete();

        return response()->json(['success' => true]);
    }

    public function submit(Request $request, int $id)
    {
        $listing = $this->ownListing($request, $id);
        $missing = $this->editor->missingForSubmission($listing);
        if ($missing) {
            return response()->json([
                'success' => false,
                'message' => __('real_estate.errors.incomplete'),
                'missing' => $missing,
            ], 422);
        }
        $listing->update(['status' => Listing::STATUS_PENDING, 'rejection_reason' => null]);

        return response()->json(['success' => true, 'data' => $this->payload($listing, $request)]);
    }

    public function uploadMedia(Request $request, int $id)
    {
        $listing = $this->ownListing($request, $id);
        $request->validate([
            'file' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp,video/mp4,video/quicktime,video/3gpp,video/webm', 'max:102400'],
            'room_id' => ['nullable', 'integer'],
            'is_cover' => ['nullable', 'boolean'],
        ]);
        $file = $request->file('file');
        if (str_starts_with((string) $file->getMimeType(), 'image/') && $file->getSize() > 10 * 1024 * 1024) {
            return response()->json(['success' => false, 'message' => __('real_estate.errors.image_too_large')], 422);
        }
        $media = $this->editor->addMedia($listing, $file, $request->integer('room_id') ?: null, $request->boolean('is_cover'));

        return response()->json(['success' => true, 'data' => $media->toApi()], 201);
    }

    public function deleteMedia(Request $request, int $id, int $mediaId)
    {
        $listing = $this->ownListing($request, $id);
        $media = Media::where('listing_id', $listing->id)->findOrFail($mediaId);
        $this->editor->deleteMedia($media);

        return response()->json(['success' => true]);
    }

    /** Aperçu des montants : prix affiché et ce que touche le bailleur. */
    public function pricePreview(Request $request)
    {
        $data = $request->validate([
            'net_price' => ['required', 'integer', 'min:0'],
            'advance_months' => ['nullable', 'integer', 'min:1', 'max:36'],
        ]);
        $displayed = $this->pricing->displayed($data['net_price'], Listing::TYPE_RENT);
        $months = $data['advance_months'] ?? 1;
        $rate = RealEstatePricingService::setting('immo_rent_commission_rate');

        return response()->json(['success' => true, 'data' => [
            'displayed_price' => $displayed,
            'owner_receives_monthly' => $data['net_price'],
            'owner_receives_advance' => $data['net_price'] * $months,
            'tenant_pays_advance' => (int) round($displayed * $months * (1 + $rate / 100)),
            'visit_window_days' => (int) RealEstatePricingService::setting('immo_visit_window_days'),
        ]]);
    }
}
