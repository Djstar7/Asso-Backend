<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Document;
use App\Models\DiaspoOffer;
use App\Services\FirebaseMessagingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DiaspoVerificationController extends Controller
{
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
            ? asset('storage/' . $frontDocument->file_path)
            : null;

        $backUrl = $backDocument && $backDocument->file_path
            ? asset('storage/' . $backDocument->file_path)
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

        if ($user->diaspo_verification_status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Cette vérification a déjà été traitée',
            ], 422);
        }

        if (!$user->diaspo_id_document_id) {
            return response()->json([
                'success' => false,
                'message' => 'Aucun document soumis pour cet utilisateur',
            ], 422);
        }

        DB::beginTransaction();
        try {
            Log::info('[ADMIN-DIASPO-VERIFICATION] Approving verification', [
                'user_id' => $user->id,
                'admin_id' => auth()->id(),
            ]);

            $user->update([
                'diaspo_verification_status' => 'verified',
                'diaspo_verified_at' => now(),
                'diaspo_rejection_reason' => null,
            ]);

            DiaspoOffer::where('user_id', $user->id)
                ->where('status', 'pending')
                ->where('verification_status', 'pending')
                ->update([
                    'status' => 'approved',
                    'verification_status' => 'verified',
                    'verified_at' => now(),
                    'verified_by' => auth()->id(),
                    'rejection_reason' => null,
                ]);

            DB::commit();

            Log::info('[ADMIN-DIASPO-VERIFICATION] Verification approved successfully', [
                'user_id' => $user->id,
            ]);

            // Send FCM notification to user
            try {
                $fcmService = app(FirebaseMessagingService::class);
                $fcmService->sendToUser(
                    $user,
                    'Vérification DIASPO approuvée !',
                    'Félicitations ! Votre identité a été vérifiée. Vos offres DIASPO en attente sont maintenant publiées.',
                    [
                        'type' => 'diaspo_verified',
                        'action' => 'open_diaspo',
                    ]
                );

                Log::info('[ADMIN-DIASPO-VERIFICATION] FCM notification sent to user', ['user_id' => $user->id]);
            } catch (\Exception $e) {
                Log::error('[ADMIN-DIASPO-VERIFICATION] Failed to send FCM notification', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

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
            DB::rollBack();
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

        DB::beginTransaction();
        try {
            Log::info('[ADMIN-DIASPO-VERIFICATION] Rejecting verification', [
                'user_id' => $user->id,
                'admin_id' => auth()->id(),
                'reason' => $request->reason,
            ]);

            $user->update([
                'diaspo_verification_status' => 'rejected',
                'diaspo_verified_at' => null,
                'diaspo_rejection_reason' => $request->reason,
            ]);

            DB::commit();

            Log::info('[ADMIN-DIASPO-VERIFICATION] Verification rejected successfully', [
                'user_id' => $user->id,
            ]);

            // Send FCM notification to user
            try {
                $fcmService = app(FirebaseMessagingService::class);
                $fcmService->sendToUser(
                    $user,
                    'Vérification DIASPO non approuvée',
                    'Votre document d\'identité n\'a pas été approuvé. Raison: ' . $request->reason,
                    [
                        'type' => 'diaspo_rejected',
                        'action' => 'open_diaspo',
                        'reason' => $request->reason,
                    ]
                );

                Log::info('[ADMIN-DIASPO-VERIFICATION] FCM notification sent to user', ['user_id' => $user->id]);
            } catch (\Exception $e) {
                Log::error('[ADMIN-DIASPO-VERIFICATION] Failed to send FCM notification', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

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
            DB::rollBack();
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

    // ============================================
    // WEB METHODS (for Blade views)
    // ============================================

    /**
     * Display web listing of users pending DIASPO verification
     */
    public function indexWeb(Request $request)
    {
        $query = User::with(['diaspoIdDocument'])
            ->whereNotNull('diaspo_id_document_id');

        // Filter by status
        $status = $request->get('status', 'pending');
        if ($status !== 'all') {
            $query->where('diaspo_verification_status', $status);
        }

        $verifications = $query->latest('updated_at')->paginate(20)->withQueryString();

        // Get counts for each status
        $counts = [
            'pending' => User::where('diaspo_verification_status', 'pending')->count(),
            'verified' => User::where('diaspo_verification_status', 'verified')->count(),
            'rejected' => User::where('diaspo_verification_status', 'rejected')->count(),
        ];

        return view('admin.diaspo.verifications.index', compact('verifications', 'counts', 'status'));
    }

    /**
     * Display web view of specific user's verification documents
     */
    public function showWeb(Request $request, $userId)
    {
        $user = User::with(['diaspoIdDocument'])->findOrFail($userId);

        if (!$user->diaspo_id_document_id) {
            return redirect()->route('admin.diaspo.verifications.index')
                ->with('error', 'Cet utilisateur n\'a pas soumis de document de vérification');
        }

        // Get both front and back documents
        $frontDocument = $user->diaspoIdDocument;
        $backDocument = Document::where('uploaded_by', $user->id)
            ->where('title', 'LIKE', '%DIASPO%Verso%')
            ->latest()
            ->first();

        $frontUrl = $frontDocument && $frontDocument->file_path
            ? asset('storage/' . $frontDocument->file_path)
            : null;

        $backUrl = $backDocument && $backDocument->file_path
            ? asset('storage/' . $backDocument->file_path)
            : null;

        return view('admin.diaspo.verifications.show', compact('user', 'frontDocument', 'backDocument', 'frontUrl', 'backUrl'));
    }

    /**
     * Approve web verification
     */
    public function approveWeb(Request $request, $userId)
    {
        $user = User::findOrFail($userId);

        if ($user->diaspo_verification_status !== 'pending') {
            return redirect()->back()->with('error', 'Cette vérification a déjà été traitée');
        }

        if (!$user->diaspo_id_document_id) {
            return redirect()->back()->with('error', 'Aucun document soumis pour cet utilisateur');
        }

        DB::beginTransaction();
        try {
            Log::info('[ADMIN-DIASPO-VERIFICATION] Approving verification', [
                'user_id' => $user->id,
                'admin_id' => auth()->id(),
            ]);

            $user->update([
                'diaspo_verification_status' => 'verified',
                'diaspo_verified_at' => now(),
                'diaspo_rejection_reason' => null,
            ]);

            DiaspoOffer::where('user_id', $user->id)
                ->where('status', 'pending')
                ->where('verification_status', 'pending')
                ->update([
                    'status' => 'approved',
                    'verification_status' => 'verified',
                    'verified_at' => now(),
                    'verified_by' => auth()->id(),
                    'rejection_reason' => null,
                ]);

            DB::commit();

            Log::info('[ADMIN-DIASPO-VERIFICATION] Verification approved successfully', [
                'user_id' => $user->id,
            ]);

            // Send FCM notification to user
            try {
                $fcmService = app(FirebaseMessagingService::class);
                $fcmService->sendToUser(
                    $user,
                    'Vérification DIASPO approuvée !',
                    'Félicitations ! Votre identité a été vérifiée. Vos offres DIASPO en attente sont maintenant publiées.',
                    [
                        'type' => 'diaspo_verified',
                        'action' => 'open_diaspo',
                    ]
                );

                Log::info('[ADMIN-DIASPO-VERIFICATION] FCM notification sent to user', ['user_id' => $user->id]);
            } catch (\Exception $e) {
                Log::error('[ADMIN-DIASPO-VERIFICATION] Failed to send FCM notification', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return redirect()->route('admin.diaspo.verifications.index')
                ->with('success', 'Vérification approuvée avec succès pour ' . $user->first_name . ' ' . $user->last_name);
        } catch (\Exception $e) {
            DB::rollBack();
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

        DB::beginTransaction();
        try {
            Log::info('[ADMIN-DIASPO-VERIFICATION] Rejecting verification', [
                'user_id' => $user->id,
                'admin_id' => auth()->id(),
                'reason' => $request->reason,
            ]);

            $user->update([
                'diaspo_verification_status' => 'rejected',
                'diaspo_verified_at' => null,
                'diaspo_rejection_reason' => $request->reason,
            ]);

            DB::commit();

            Log::info('[ADMIN-DIASPO-VERIFICATION] Verification rejected successfully', [
                'user_id' => $user->id,
            ]);

            // Send FCM notification to user
            try {
                $fcmService = app(FirebaseMessagingService::class);
                $fcmService->sendToUser(
                    $user,
                    'Vérification DIASPO non approuvée',
                    'Votre document d\'identité n\'a pas été approuvé. Raison: ' . $request->reason,
                    [
                        'type' => 'diaspo_rejected',
                        'action' => 'open_diaspo',
                        'reason' => $request->reason,
                    ]
                );

                Log::info('[ADMIN-DIASPO-VERIFICATION] FCM notification sent to user', ['user_id' => $user->id]);
            } catch (\Exception $e) {
                Log::error('[ADMIN-DIASPO-VERIFICATION] Failed to send FCM notification', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return redirect()->route('admin.diaspo.verifications.index')
                ->with('success', 'Vérification rejetée pour ' . $user->first_name . ' ' . $user->last_name);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[ADMIN-DIASPO-VERIFICATION] Error rejecting verification', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->back()->with('error', 'Erreur lors du rejet: ' . $e->getMessage());
        }
    }
}
