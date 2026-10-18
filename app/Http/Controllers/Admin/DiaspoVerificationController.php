<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Document;
use App\Models\DiaspoOffer;
use App\Models\DiaspoVerificationEvent;
use App\Services\DiaspoVerificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DiaspoVerificationController extends Controller
{
    public function __construct(private DiaspoVerificationService $verifications)
    {
    }

    /**
     * Display a listing of users pending DIASPO verification
     */
    public function index(Request $request)
    {
        $query = User::with(['diaspoIdDocument'])
            ->whereNotNull('diaspo_id_document_id');

        // Filter by status
        if ($request->filled('status')) {
            $query->where('diaspo_verification_status', $request->status);
        } else {
            // Default: only pending
            $query->where('diaspo_verification_status', 'pending');
        }

        $users = $query->latest('updated_at')->paginate(20)->withQueryString();

        return response()->json([
            'success' => true,
            'verifications' => $users->map(function ($user) {
                return [
                    'id' => $user->id,
                    'user' => [
                        'id' => $user->id,
                        'first_name' => $user->first_name,
                        'last_name' => $user->last_name,
                        'phone' => $user->phone,
                        'email' => $user->email,
                    ],
                    'verification_status' => $user->diaspo_verification_status,
                    'document_id' => $user->diaspo_id_document_id,
                    'verified_at' => $user->diaspo_verified_at?->toIso8601String(),
                    'rejection_reason' => $user->diaspo_rejection_reason,
                    'submitted_at' => $user->updated_at->toIso8601String(),
                ];
            }),
            'pagination' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
            'counts' => [
                'pending' => User::where('diaspo_verification_status', 'pending')->count(),
                'verified' => User::where('diaspo_verification_status', 'verified')->count(),
                'rejected' => User::where('diaspo_verification_status', 'rejected')->count(),
            ],
        ]);
    }

    /**
     * Display a specific user's verification documents
     */
    public function show(Request $request, $userId)
    {
        $user = User::with(['diaspoIdDocument'])->findOrFail($userId);

        if (!$user->diaspo_id_document_id) {
            return response()->json([
                'success' => false,
                'message' => 'Cet utilisateur n\'a pas soumis de document de vérification',
            ], 404);
        }

        // Get both front and back documents
        $frontDocument = $user->diaspoIdDocument;
        $backDocument = Document::where('uploaded_by', $user->id)
            ->where('title', 'LIKE', '%DIASPO%Verso%')
            ->latest()
            ->first();

        $frontUrl = $frontDocument && $frontDocument->file_path
            ? media_url($frontDocument->file_path)
            : null;

        $backUrl = $backDocument && $backDocument->file_path
            ? media_url($backDocument->file_path)
            : null;

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'phone' => $user->phone,
                    'email' => $user->email,
                ],
                'verification_status' => $user->diaspo_verification_status,
                'documents' => [
                    'front' => [
                        'id' => $frontDocument?->id,
                        'title' => $frontDocument?->title,
                        'url' => $frontUrl,
                        'file_name' => $frontDocument?->file_name,
                    ],
                    'back' => [
                        'id' => $backDocument?->id,
                        'title' => $backDocument?->title,
                        'url' => $backUrl,
                        'file_name' => $backDocument?->file_name,
                    ],
                ],
                'verified_at' => $user->diaspo_verified_at?->toIso8601String(),
                'rejection_reason' => $user->diaspo_rejection_reason,
                'submitted_at' => $user->updated_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * Approve a DIASPO verification
     */
    public function approve(Request $request, $userId)
    {
        $user = User::findOrFail($userId);

        if ($error = $this->approvalError($user)) {
            return response()->json(['success' => false, 'message' => $error], 422);
        }

        try {
            $this->verifications->approveIdentity($user, auth()->id());

            return response()->json([
                'success' => true,
                'message' => 'Vérification approuvée avec succès',
                'data' => [
                    'user_id' => $user->id,
                    'verification_status' => $user->diaspo_verification_status,
                    'verified_at' => $user->diaspo_verified_at->toIso8601String(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('[ADMIN-DIASPO-VERIFICATION] Error approving verification', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'approbation: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Reject a DIASPO verification
     */
    public function reject(Request $request, $userId)
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $user = User::findOrFail($userId);

        if ($user->diaspo_verification_status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Cette vérification a déjà été traitée',
            ], 422);
        }

        try {
            $this->verifications->rejectIdentity($user, $request->reason, auth()->id());

            return response()->json([
                'success' => true,
                'message' => 'Vérification rejetée',
                'data' => [
                    'user_id' => $user->id,
                    'verification_status' => $user->diaspo_verification_status,
                    'rejection_reason' => $user->diaspo_rejection_reason,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('[ADMIN-DIASPO-VERIFICATION] Error rejecting verification', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du rejet: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function approvalError(User $user): ?string
    {
        if ($user->diaspo_verification_status !== 'pending') {
            return 'Cette vérification a déjà été traitée';
        }
        if (!$user->diaspo_id_document_id) {
            return 'Aucun document soumis pour cet utilisateur';
        }

        return null;
    }

    // ============================================
    // WEB METHODS (for Blade views)
    // ============================================

    /**
     * Display web listing of users pending DIASPO verification
     */
    public function indexWeb(Request $request)
    {
        // Profils ayant envoyé des pièces, et profils non vérifiés qui ont publié
        // sans en fournir (« Sans pièces ») : les deux doivent être régularisés.
        $query = User::with(['diaspoIdDocument'])
            ->where(function ($q) {
                $q->whereNotNull('diaspo_id_document_id')
                    ->orWhereHas('diaspoOffers', fn ($o) => $o->awaitingVerification());
            })
            ->withMin(['diaspoOffers as next_deadline' => fn ($o) => $o->awaitingVerification()], 'verification_deadline_at')
            ->withCount(['diaspoOffers as unverified_offers_count' => fn ($o) => $o->awaitingVerification()]);

        // Filter by status
        $status = $request->get('status', 'pending');
        if ($status === 'unverified') {
            $query->whereNull('diaspo_id_document_id')->where('diaspo_verification_status', 'unverified');
        } elseif ($status !== 'all') {
            $query->where('diaspo_verification_status', $status);
        }

        $verifications = $query->latest('updated_at')->paginate(20)->withQueryString();

        // Get counts for each status
        $counts = [
            'pending' => User::where('diaspo_verification_status', 'pending')->count(),
            'unverified' => User::where('diaspo_verification_status', 'unverified')
                ->whereNull('diaspo_id_document_id')
                ->whereHas('diaspoOffers', fn ($o) => $o->awaitingVerification())
                ->count(),
            'verified' => User::where('diaspo_verification_status', 'verified')->count(),
            'rejected' => User::where('diaspo_verification_status', 'rejected')->count(),
        ];
        $graceDays = DiaspoVerificationService::graceDays();

        return view('admin.diaspo.verifications.index', compact('verifications', 'counts', 'status', 'graceDays'));
    }

    /**
     * Délai de régularisation (jours) accordé aux profils non vérifiés. S'applique
     * aux nouvelles offres ; les échéances déjà fixées se prolongent offre par offre.
     */
    public function updateSettingsWeb(Request $request)
    {
        $validated = $request->validate([
            'grace_days' => 'required|integer|min:1|max:90',
        ]);

        DiaspoVerificationService::setGraceDays((int) $validated['grace_days']);

        return redirect()->route('admin.diaspo.verifications.index', ['status' => $request->get('status', 'pending')])
            ->with('success', 'Délai de régularisation fixé à ' . $validated['grace_days'] . ' jour(s) pour les nouvelles offres.');
    }

    /**
     * Display web view of specific user's verification documents
     */
    public function showWeb(Request $request, $userId)
    {
        $user = User::with(['diaspoIdDocument'])->findOrFail($userId);

        $offers = DiaspoOffer::withTrashed()
            ->where('user_id', $user->id)
            ->latest()
            ->get();
        $events = DiaspoVerificationEvent::with(['actor', 'offer'])
            ->where('user_id', $user->id)
            ->latest('created_at')
            ->latest('id')
            ->get();

        if (!$user->diaspo_id_document_id && $offers->isEmpty() && $events->isEmpty()) {
            return redirect()->route('admin.diaspo.verifications.index')
                ->with('error', 'Cet utilisateur n\'a pas soumis de document de vérification');
        }

        // Get both front and back documents
        $frontDocument = $user->diaspoIdDocument;
        $backDocument = $user->diaspo_id_document_id
            ? Document::where('uploaded_by', $user->id)
                ->where('title', 'LIKE', '%DIASPO%Verso%')
                ->latest()
                ->first()
            : null;

        $frontUrl = $frontDocument && $frontDocument->file_path
            ? media_url($frontDocument->file_path)
            : null;

        $backUrl = $backDocument && $backDocument->file_path
            ? media_url($backDocument->file_path)
            : null;

        // Parcours renforcé : selfie, titre de séjour / visa, justificatifs de voyage.
        $extraDocuments = \App\Services\DiaspoVerificationService::latestIdentityDocuments($user)
            ->whereNull('side')
            ->sortBy(fn ($d) => $d->type === \App\Models\DiaspoIdentityDocument::TYPE_SELFIE ? 0 : 1)
            ->values();
        $travelProofs = \App\Models\DiaspoIdentityDocument::with('document')
            ->where('user_id', $user->id)
            ->where('type', \App\Models\DiaspoIdentityDocument::TYPE_TRAVEL_PROOF)
            ->get()
            ->keyBy('diaspo_offer_id');
        $required = \App\Services\DiaspoVerificationService::requiredDocuments($user->nationality, $user->residence_country);

        return view('admin.diaspo.verifications.show', compact('user', 'frontDocument', 'backDocument', 'frontUrl', 'backUrl', 'offers', 'events', 'extraDocuments', 'travelProofs', 'required'));
    }

    /**
     * Approve web verification
     */
    public function approveWeb(Request $request, $userId)
    {
        $user = User::findOrFail($userId);

        if ($error = $this->approvalError($user)) {
            return redirect()->back()->with('error', $error);
        }

        try {
            $this->verifications->approveIdentity($user, auth()->id());

            return redirect()->route('admin.diaspo.verifications.index')
                ->with('success', 'Vérification approuvée avec succès pour ' . $user->first_name . ' ' . $user->last_name);
        } catch (\Exception $e) {
            Log::error('[ADMIN-DIASPO-VERIFICATION] Error approving verification', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->back()->with('error', 'Erreur lors de l\'approbation: ' . $e->getMessage());
        }
    }

    /**
     * Reject web verification
     */
    public function rejectWeb(Request $request, $userId)
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $user = User::findOrFail($userId);

        if ($user->diaspo_verification_status !== 'pending') {
            return redirect()->back()->with('error', 'Cette vérification a déjà été traitée');
        }

        try {
            $this->verifications->rejectIdentity($user, $request->reason, auth()->id());

            return redirect()->route('admin.diaspo.verifications.index')
                ->with('success', 'Vérification rejetée pour ' . $user->first_name . ' ' . $user->last_name);
        } catch (\Exception $e) {
            Log::error('[ADMIN-DIASPO-VERIFICATION] Error rejecting verification', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->back()->with('error', 'Erreur lors du rejet: ' . $e->getMessage());
        }
    }
}
