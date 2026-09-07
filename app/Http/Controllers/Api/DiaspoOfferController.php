<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DiaspoOffer;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DiaspoOfferController extends Controller
{
    /**
     * Get all approved and available offers
     */
    public function index(Request $request)
    {
        $query = DiaspoOffer::with(['user'])
            ->available()
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
        ]);

        // Une identité incomplète ne bloque plus la saisie de l'offre. L'offre reste
        // invisible du catalogue public jusqu'à la validation de l'identité et peut
        // ensuite être rejetée/supprimée par l'équipe depuis l'administration.
        $isVerified = $user->canCreateDiaspoOffers();
        $offer = DiaspoOffer::create([
            'user_id' => auth()->id(),
            'status' => $isVerified ? 'approved' : 'pending',
            'verification_status' => $isVerified ? 'verified' : 'pending',
            ...$validated,
        ]);

        // Refresh to get updated status from observer
        $offer->refresh();

        $message = $offer->status === 'approved'
            ? 'Offre créée et publiée avec succès!'
            : 'Offre enregistrée. Elle sera publiée après validation de votre identité. Notre équipe vous demandera un complément si nécessaire.';

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
                'message' => 'Non autorisé',
            ], 403);
        }

        // Ne peut pas modifier si l'offre est complétée
        if ($offer->status === 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'Cette offre est terminée et ne peut plus être modifiée',
            ], 400);
        }

        // Ne peut pas modifier si des réservations actives existent
        if ($offer->bookings()->whereIn('status', ['paid', 'completed'])->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cette offre ne peut pas être modifiée car elle a des réservations actives',
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
            'message' => 'Offre mise à jour',
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
                'message' => 'Non autorisé',
            ], 403);
        }

        // Ne peut pas supprimer si des réservations existent
        if ($offer->bookings()->whereIn('status', ['paid', 'completed'])->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cette offre ne peut pas être supprimée car elle a des réservations actives',
            ], 400);
        }

        $offer->delete();

        return response()->json([
            'success' => true,
            'message' => 'Offre supprimée',
        ]);
    }

    /**
     * Upload verification document (CNI ou Passeport) - Recto/Verso
     */
    public function uploadVerificationDocument(Request $request)
    {
        $request->validate([
            'document_front' => 'required|file|mimes:jpg,jpeg,png|max:5120',
            'document_back' => 'required|file|mimes:jpg,jpeg,png|max:5120',
            'document_type' => 'nullable|in:cni,passport',
        ]);

        $user = auth()->user();

        DB::beginTransaction();
        try {
            // Upload front image
            $frontFile = $request->file('document_front');
            $frontFileName = 'diaspo_' . $user->id . '_front_' . time() . '.' . $frontFile->getClientOriginalExtension();
            $frontFilePath = $frontFile->storeAs('documents/diaspo_verification', $frontFileName, 'public');

            $frontDocument = Document::create([
                'category_id' => null,
                'title' => 'Document DIASPO (Recto) - ' . strtoupper($request->document_type ?? 'CNI'),
                'description' => 'Recto du document uploadé pour vérification DIASPO',
                'file_name' => $frontFileName,
                'file_path' => $frontFilePath,
                'file_type' => $frontFile->getClientOriginalExtension(),
                'file_size' => $frontFile->getSize(),
                'mime_type' => $frontFile->getMimeType(),
                'uploaded_by' => $user->id,
                'visibility' => 'restricted',
                'allowed_users' => [$user->id],
            ]);

            // Upload back image
            $backFile = $request->file('document_back');
            $backFileName = 'diaspo_' . $user->id . '_back_' . time() . '.' . $backFile->getClientOriginalExtension();
            $backFilePath = $backFile->storeAs('documents/diaspo_verification', $backFileName, 'public');

            $backDocument = Document::create([
                'category_id' => null,
                'title' => 'Document DIASPO (Verso) - ' . strtoupper($request->document_type ?? 'CNI'),
                'description' => 'Verso du document uploadé pour vérification DIASPO',
                'file_name' => $backFileName,
                'file_path' => $backFilePath,
                'file_type' => $backFile->getClientOriginalExtension(),
                'file_size' => $backFile->getSize(),
                'mime_type' => $backFile->getMimeType(),
                'uploaded_by' => $user->id,
                'visibility' => 'restricted',
                'allowed_users' => [$user->id],
            ]);

            // Update user with front document ID (primary reference)
            $user->update([
                'diaspo_id_document_id' => $frontDocument->id,
                'diaspo_verification_status' => 'pending',
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Documents uploadés avec succès. Votre profil sera vérifié sous 24-48h.',
                'data' => [
                    'document_front' => $frontDocument,
                    'document_back' => $backDocument,
                    'verification_status' => 'pending',
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'upload des documents: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get verification status
     */
    public function getVerificationStatus()
    {
        $user = auth()->user();

        return response()->json([
            'success' => true,
            'data' => [
                'verification_status' => $user->diaspo_verification_status,
                'verified_at' => $user->diaspo_verified_at,
                'rejection_reason' => $user->diaspo_rejection_reason,
                'can_create_offers' => $user->canCreateDiaspoOffers(),
            ],
        ]);
    }
}
