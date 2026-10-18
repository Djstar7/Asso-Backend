<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DiaspoIdentityDocument;
use App\Models\DiaspoOffer;
use App\Models\Document;
use App\Services\DiaspoVerificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DiaspoOfferController extends Controller
{
    /**
     * Catalogue public : offres publiées, y compris celles des profils non vérifiés
     * (drapeau profile_verified = false → mention « Profil non vérifié » côté app).
     */
    public function index(Request $request)
    {
        $query = DiaspoOffer::with(['user'])
            ->published()
            ->recent();

        // Filtres
        if ($request->departure_country) {
            $query->where('departure_country', $request->departure_country);
        }

        if ($request->arrival_country) {
            $query->where('arrival_country', $request->arrival_country);
        }

        if ($request->departure_city) {
            $query->where('departure_city', 'LIKE', "%{$request->departure_city}%");
        }

        if ($request->arrival_city) {
            $query->where('arrival_city', 'LIKE', "%{$request->arrival_city}%");
        }

        if ($request->min_date) {
            $query->where('departure_datetime', '>=', $request->min_date);
        }

        if ($request->max_date) {
            $query->where('departure_datetime', '<=', $request->max_date);
        }

        if ($request->max_price) {
            $query->where('price_per_kg', '<=', $request->max_price);
        }

        $offers = $query->paginate($request->per_page ?? 20);

        return response()->json([
            'success' => true,
            'data' => $offers,
        ]);
    }

    /**
     * Get user's own offers
     */
    public function myOffers(Request $request)
    {
        $offers = DiaspoOffer::with(['bookings'])
            ->where('user_id', auth()->id())
            ->recent()
            ->paginate($request->per_page ?? 20);

        return response()->json([
            'success' => true,
            'data' => $offers,
        ]);
    }

    /**
     * Get a single offer
     */
    public function show($id)
    {
        $offer = DiaspoOffer::with(['user', 'bookings'])
            ->findOrFail($id);

        // Increment views
        $offer->incrementViews();

        return response()->json([
            'success' => true,
            'data' => $offer,
        ]);
    }

    /**
     * Create a new offer
     */
    public function store(Request $request)
    {
        $user = auth()->user();

        $validated = $request->validate([
            'departure_country' => 'required|string|max:255',
            'departure_city' => 'required|string|max:255',
            'departure_datetime' => 'required|date|after:now',
            'arrival_country' => 'required|string|max:255',
            'arrival_city' => 'required|string|max:255',
            'arrival_datetime' => 'required|date|after:departure_datetime',
            'price_per_kg' => 'required|numeric|min:0',
            'available_kg' => 'required|numeric|min:0.1',
            'currency' => 'nullable|string|max:3',
            // Billet ou réservation justifiant le départ et la destination.
            'travel_proof' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);
        unset($validated['travel_proof']);

        // Une identité non validée ne bloque pas la publication : l'offre est en ligne
        // avec la mention « Profil non vérifié » (non réservable) jusqu'à la validation,
        // et retirée si l'identité n'est pas régularisée avant l'échéance fixée par ASSO.
        $isVerified = $user->canCreateDiaspoOffers();
        $offer = DiaspoOffer::create([
            'user_id' => auth()->id(),
            'status' => 'approved',
            'verification_status' => $isVerified ? 'verified' : 'pending',
            ...$validated,
        ]);

        if (!$isVerified) {
            app(DiaspoVerificationService::class)->publishUnverified($offer);
        }

        if ($request->hasFile('travel_proof')) {
            $this->storeIdentityFile($user, $request->file('travel_proof'), DiaspoIdentityDocument::TYPE_TRAVEL_PROOF, null, $offer->id);
        }

        // Refresh to get updated status from observer
        $offer->refresh();

        $message = $isVerified
            ? __('diaspo.offer_created_published')
            : __('diaspo.offer_published_unverified', [
                'date' => $offer->verification_deadline_at->format('d/m/Y'),
            ]);

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $offer,
        ], 201);
    }

    /**
     * Update an offer
     */
    public function update(Request $request, $id)
    {
        $offer = DiaspoOffer::findOrFail($id);

        // Vérifier que c'est bien l'utilisateur qui a créé l'offre
        if ($offer->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => __('common.unauthorized'),
            ], 403);
        }

        // Ne peut pas modifier si l'offre est complétée
        if ($offer->status === 'completed') {
            return response()->json([
                'success' => false,
                'message' => __('diaspo.offer_completed_cannot_update'),
            ], 400);
        }

        // Ne peut pas modifier si des réservations actives existent
        if ($offer->bookings()->whereIn('status', ['paid', 'completed'])->exists()) {
            return response()->json([
                'success' => false,
                'message' => __('diaspo.offer_update_has_bookings'),
            ], 400);
        }

        $validated = $request->validate([
            'departure_country' => 'sometimes|string|max:255',
            'departure_city' => 'sometimes|string|max:255',
            'departure_datetime' => 'sometimes|date|after:now',
            'arrival_country' => 'sometimes|string|max:255',
            'arrival_city' => 'sometimes|string|max:255',
            'arrival_datetime' => 'sometimes|date',
            'price_per_kg' => 'sometimes|numeric|min:0',
            'available_kg' => 'sometimes|numeric|min:0.1',
        ]);

        $offer->update($validated);

        return response()->json([
            'success' => true,
            'message' => __('diaspo.offer_updated'),
            'data' => $offer,
        ]);
    }

    /**
     * Delete an offer
     */
    public function destroy($id)
    {
        $offer = DiaspoOffer::findOrFail($id);

        // Vérifier que c'est bien l'utilisateur qui a créé l'offre
        if ($offer->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => __('common.unauthorized'),
            ], 403);
        }

        // Ne peut pas supprimer si des réservations existent
        if ($offer->bookings()->whereIn('status', ['paid', 'completed'])->exists()) {
            return response()->json([
                'success' => false,
                'message' => __('diaspo.offer_delete_has_active_bookings'),
            ], 400);
        }

        $offer->delete();

        return response()->json([
            'success' => true,
            'message' => __('diaspo.offer_deleted'),
        ]);
    }

    /**
     * POST /v1/diaspo/upload-verification — pièces du voyageur.
     *
     * Parcours renforcé (nationalité + pays de résidence envoyés) : pièces exigées
     * par DiaspoVerificationService::requiredDocuments, selfie pris en direct.
     * Sans nationalité (anciennes versions de l'app) : CNI ou passeport recto/verso.
     */
    public function uploadVerificationDocument(Request $request)
    {
        $user = auth()->user();
        $countries = array_keys(\App\Support\CountryCode::NAMES);
        $image = 'file|mimes:jpg,jpeg,png|max:5120';
        $reinforced = $request->filled('nationality');

        if ($reinforced) {
            $base = $request->validate([
                'nationality' => ['required', Rule::in($countries)],
                'residence_country' => ['required', Rule::in($countries)],
            ]);
            $required = DiaspoVerificationService::requiredDocuments($base['nationality'], $base['residence_country']);
            $data = $request->validate([
                'document_type' => ['required', Rule::in($required['identity'])],
                'document_front' => "required|{$image}",
                // Verso exigé pour une CNI ; le passeport n'a qu'une page utile.
                'document_back' => ($request->input('document_type') === DiaspoIdentityDocument::TYPE_CNI ? 'required|' : 'nullable|') . $image,
                'residence_document_type' => $required['residence'] ? ['required', Rule::in($required['residence'])] : 'nullable',
                'residence_document' => ($required['residence'] ? 'required|' : 'nullable|') . $image,
                'selfie' => "required|{$image}",
            ], [
                'document_type.in' => __('diaspo.passport_required_abroad'),
            ]);
        } else {
            $data = $request->validate([
                'document_front' => "required|{$image}",
                'document_back' => "required|{$image}",
                'document_type' => 'nullable|in:cni,passport',
            ]);
            $data['document_type'] = $data['document_type'] ?? DiaspoIdentityDocument::TYPE_CNI;
        }

        if ($user->isDiaspoVerified()) {
            return response()->json([
                'success' => false,
                'message' => __('diaspo.identity_already_verified'),
            ], 422);
        }
        $previousStatus = $user->diaspo_verification_status ?? 'unverified';

        DB::beginTransaction();
        try {
            $type = $data['document_type'];
            $front = $this->storeIdentityFile($user, $request->file('document_front'), $type, 'front');
            $back = $request->hasFile('document_back')
                ? $this->storeIdentityFile($user, $request->file('document_back'), $type, 'back')
                : null;
            if ($reinforced) {
                if ($request->hasFile('residence_document')) {
                    $this->storeIdentityFile($user, $request->file('residence_document'), $data['residence_document_type']);
                }
                $this->storeIdentityFile($user, $request->file('selfie'), DiaspoIdentityDocument::TYPE_SELFIE);
            }

            $user->update(array_filter([
                'diaspo_id_document_id' => $front->id,
                'diaspo_verification_status' => 'pending',
                'nationality' => $reinforced ? $base['nationality'] : null,
                'residence_country' => $reinforced ? $base['residence_country'] : null,
            ]));

            app(DiaspoVerificationService::class)->documentsSubmitted($user, $previousStatus);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => __('diaspo.documents_uploaded'),
                'data' => [
                    'document_front' => $front,
                    'document_back' => $back,
                    'verification_status' => 'pending',
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => __('diaspo.documents_upload_error', ['error' => $e->getMessage()]),
            ], 500);
        }
    }

    /**
     * Enregistre une pièce (fichier privé + Document restreint) et la rattache
     * au voyageur, et à l'offre pour un justificatif de voyage.
     */
    private function storeIdentityFile($user, \Illuminate\Http\UploadedFile $file, string $type, ?string $side = null, ?int $offerId = null): Document
    {
        $suffix = $type . ($side ? "_{$side}" : '');
        $fileName = 'diaspo_' . $user->id . '_' . $suffix . '_' . time() . '_' . Str::random(6) . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('documents/diaspo_verification', $fileName, 'public');

        // Titres repris de l'ancien format (« Document DIASPO (Recto) - CNI »),
        // que l'écran admin utilise encore pour retrouver le verso.
        $title = match (true) {
            $side === 'front' => 'Document DIASPO (Recto) - ' . strtoupper($type),
            $side === 'back' => 'Document DIASPO (Verso) - ' . strtoupper($type),
            default => 'Document DIASPO - ' . (DiaspoIdentityDocument::LABELS[$type] ?? $type),
        };

        $document = Document::create([
            'category_id' => null,
            'title' => $title,
            'description' => 'Pièce uploadée pour vérification DIASPO',
            'file_name' => $fileName,
            'file_path' => $path,
            'file_type' => $file->getClientOriginalExtension(),
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'uploaded_by' => $user->id,
            'visibility' => 'restricted',
            'allowed_users' => [$user->id],
        ]);

        DiaspoIdentityDocument::create([
            'user_id' => $user->id,
            'type' => $type,
            'side' => $side,
            'document_id' => $document->id,
            'diaspo_offer_id' => $offerId,
        ]);

        return $document;
    }

    /**
     * Get verification status
     */
    public function getVerificationStatus()
    {
        $user = auth()->user();
        $nextDeadline = DiaspoVerificationService::nextDeadlineFor($user);

        return response()->json([
            'success' => true,
            'data' => [
                'verification_status' => $user->diaspo_verification_status,
                'verified_at' => $user->diaspo_verified_at,
                'rejection_reason' => $user->diaspo_rejection_reason,
                // Historique : la création n'est plus bloquée, seul le badge change.
                'can_create_offers' => $user->canCreateDiaspoOffers(),
                'grace_days' => DiaspoVerificationService::graceDays(),
                // Échéance la plus proche avant retrait d'une offre non vérifiée.
                'next_deadline' => $nextDeadline?->toIso8601String(),
                'nationality' => $user->nationality,
                'residence_country' => $user->residence_country,
                'required_documents' => DiaspoVerificationService::requiredDocuments($user->nationality, $user->residence_country),
                'documents' => DiaspoVerificationService::latestIdentityDocuments($user)
                    ->map(fn ($d) => ['type' => $d->type, 'side' => $d->side, 'status' => $d->status])->values(),
            ],
        ]);
    }
}
