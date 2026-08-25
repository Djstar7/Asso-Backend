<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\FirebaseMessagingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Validation ADMIN des comptes Stripe Connect vendeurs.
 *
 * Porte interne ASSO (distincte de la vérification KYC de Stripe) : un vendeur
 * ayant soumis son IBAN passe en `pending` ; l'admin l'approuve ou le rejette
 * (avec motif obligatoire). Seul un compte `approved` pourra recevoir un payout.
 * Calqué sur DiaspoVerificationController. Voir [[stripe-connect-state]].
 */
class StripeConnectController extends Controller
{
    /**
     * Les routes v1/admin ne portent pas de middleware de rôle : on vérifie
     * explicitement que l'appelant est admin (users.role === 'admin').
     */
    private function ensureAdmin(Request $request): void
    {
        if (($request->user()->role ?? null) !== 'admin') {
            abort(403, 'Accès réservé aux administrateurs.');
        }
    }

    /**
     * Liste des comptes Stripe Connect (par défaut : en attente).
     * GET /v1/admin/stripe/accounts?status=pending|approved|rejected
     */
    public function index(Request $request)
    {
        $this->ensureAdmin($request);

        $query = User::whereNotNull('stripe_account_id');

        if ($request->filled('status')) {
            $query->where('stripe_account_status', $request->status);
        } else {
            $query->where('stripe_account_status', 'pending');
        }

        $users = $query->latest('stripe_submitted_at')->paginate(20)->withQueryString();

        return response()->json([
            'success' => true,
            'accounts' => $users->getCollection()->map(fn($user) => $this->formatAccount($user)),
            'pagination' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
            'counts' => [
                'pending' => User::where('stripe_account_status', 'pending')->count(),
                'approved' => User::where('stripe_account_status', 'approved')->count(),
                'rejected' => User::where('stripe_account_status', 'rejected')->count(),
            ],
        ]);
    }

    /**
     * Détail d'un compte Stripe Connect vendeur.
     * GET /v1/admin/stripe/accounts/{userId}
     */
    public function show(Request $request, $userId)
    {
        $this->ensureAdmin($request);

        $user = User::findOrFail($userId);

        if (!$user->stripe_account_id) {
            return response()->json([
                'success' => false,
                'message' => "Ce vendeur n'a pas soumis d'informations bancaires Stripe.",
            ], 404);
        }

        return response()->json([
            'success' => true,
            // L'état Stripe n'est lu que sur le détail (1 appel API), pas sur la liste.
            'data' => $this->formatAccount($user) + ['stripe_state' => $this->stripeState($user)],
        ]);
    }

    /**
     * Approuve le compte Stripe Connect d'un vendeur.
     * POST /v1/admin/stripe/accounts/{userId}/approve
     */
    public function approve(Request $request, $userId)
    {
        $this->ensureAdmin($request);

        $user = User::findOrFail($userId);

        if ($user->stripe_account_status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Ce compte a déjà été traité.',
            ], 422);
        }

        if (!$user->stripe_account_id) {
            return response()->json([
                'success' => false,
                'message' => 'Aucun compte Stripe soumis pour ce vendeur.',
            ], 422);
        }

        // Garde-fou : approuver un compte que Stripe n'a pas activé produirait un
        // vendeur « validé » dont tous les virements échoueraient.
        $state = $this->stripeState($user);
        if ($state && !$state['ready']) {
            return response()->json([
                'success' => false,
                'message' => $this->notReadyMessage($state),
                'stripe_state' => $state,
            ], 422);
        }

        DB::beginTransaction();
        try {
            Log::info('[ADMIN-STRIPE-CONNECT] Approving account', [
                'user_id' => $user->id,
                'admin_id' => auth()->id(),
            ]);

            $user->update([
                'stripe_account_status' => 'approved',
                'stripe_verified_at' => now(),
                'stripe_rejection_reason' => null,
            ]);

            DB::commit();

            $this->notify(
                $user,
                'Compte de virement validé !',
                'Votre compte bancaire a été validé. Vous pourrez désormais être payé par virement (IBAN).',
                ['type' => 'stripe_account_approved', 'action' => 'open_wallet']
            );

            return response()->json([
                'success' => true,
                'message' => 'Compte Stripe approuvé avec succès.',
                'data' => $this->formatAccount($user->fresh()),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[ADMIN-STRIPE-CONNECT] Error approving account', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => "Erreur lors de l'approbation : " . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Rejette le compte Stripe Connect d'un vendeur (motif obligatoire).
     * POST /v1/admin/stripe/accounts/{userId}/reject
     */
    public function reject(Request $request, $userId)
    {
        $this->ensureAdmin($request);

        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $user = User::findOrFail($userId);

        if ($user->stripe_account_status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Ce compte a déjà été traité.',
            ], 422);
        }

        DB::beginTransaction();
        try {
            Log::info('[ADMIN-STRIPE-CONNECT] Rejecting account', [
                'user_id' => $user->id,
                'admin_id' => auth()->id(),
                'reason' => $request->reason,
            ]);

            $user->update([
                'stripe_account_status' => 'rejected',
                'stripe_verified_at' => null,
                'stripe_rejection_reason' => $request->reason,
            ]);

            DB::commit();

            $this->notify(
                $user,
                'Compte de virement non validé',
                "Votre compte bancaire n'a pas été validé. Raison : " . $request->reason,
                ['type' => 'stripe_account_rejected', 'action' => 'open_wallet', 'reason' => $request->reason]
            );

            return response()->json([
                'success' => true,
                'message' => 'Compte Stripe rejeté.',
                'data' => $this->formatAccount($user->fresh()),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[ADMIN-STRIPE-CONNECT] Error rejecting account', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du rejet : ' . $e->getMessage(),
            ], 500);
        }
    }

    // ============================================
    // WEB METHODS (panneau admin Blade)
    // ============================================

    /**
     * Liste web des comptes Stripe Connect vendeurs.
     * GET admin/stripe/accounts
     */
    public function indexWeb(Request $request)
    {
        $status = $request->get('status', 'pending');

        $query = User::whereNotNull('stripe_account_id');
        if ($status !== 'all') {
            $query->where('stripe_account_status', $status);
        }

        $accounts = $query->latest('stripe_submitted_at')->paginate(20)->withQueryString();

        $counts = [
            'pending' => User::where('stripe_account_status', 'pending')->count(),
            'approved' => User::where('stripe_account_status', 'approved')->count(),
            'rejected' => User::where('stripe_account_status', 'rejected')->count(),
        ];

        return view('admin.stripe.accounts.index', compact('accounts', 'counts', 'status'));
    }

    /**
     * Approuve un compte (web).
     * POST admin/stripe/accounts/{userId}/approve
     */
    public function approveWeb(Request $request, $userId)
    {
        $user = User::findOrFail($userId);

        if ($user->stripe_account_status !== 'pending') {
            return redirect()->back()->with('error', 'Ce compte a déjà été traité.');
        }

        // Même garde-fou que l'API : Stripe doit avoir activé la capability transfers.
        $state = $this->stripeState($user);
        if ($state && !$state['ready']) {
            return redirect()->back()->with('error', $this->notReadyMessage($state));
        }

        $user->update([
            'stripe_account_status' => 'approved',
            'stripe_verified_at' => now(),
            'stripe_rejection_reason' => null,
        ]);

        Log::info('[ADMIN-STRIPE-CONNECT] Account approved (web)', [
            'user_id' => $user->id,
            'admin_id' => auth()->id(),
        ]);

        $this->notify(
            $user,
            'Compte de virement validé !',
            'Votre compte bancaire a été validé. Vous pourrez désormais être payé par virement (IBAN).',
            ['type' => 'stripe_account_approved', 'action' => 'open_wallet']
        );

        return redirect()->route('admin.stripe.accounts.index')
            ->with('success', 'Compte de virement approuvé pour ' . $user->first_name . ' ' . $user->last_name . '.');
    }

    /**
     * Rejette un compte avec motif (web).
     * POST admin/stripe/accounts/{userId}/reject
     */
    public function rejectWeb(Request $request, $userId)
    {
        $request->validate(['reason' => 'required|string|max:500']);

        $user = User::findOrFail($userId);

        if ($user->stripe_account_status !== 'pending') {
            return redirect()->back()->with('error', 'Ce compte a déjà été traité.');
        }

        $user->update([
            'stripe_account_status' => 'rejected',
            'stripe_verified_at' => null,
            'stripe_rejection_reason' => $request->reason,
        ]);

        Log::info('[ADMIN-STRIPE-CONNECT] Account rejected (web)', [
            'user_id' => $user->id,
            'admin_id' => auth()->id(),
            'reason' => $request->reason,
        ]);

        $this->notify(
            $user,
            'Compte de virement non validé',
            "Votre compte bancaire n'a pas été validé. Raison : " . $request->reason,
            ['type' => 'stripe_account_rejected', 'action' => 'open_wallet', 'reason' => $request->reason]
        );

        return redirect()->route('admin.stripe.accounts.index')
            ->with('success', 'Compte de virement rejeté pour ' . $user->first_name . ' ' . $user->last_name . '.');
    }

    /**
     * État réel du compte côté Stripe (null si Stripe n'est pas configuré ou si le
     * vendeur n'a pas de compte : on ne bloque alors pas la décision de l'admin).
     */
    private function stripeState(User $user): ?array
    {
        $stripe = app(\App\Services\StripeService::class);

        if (!$stripe->isConfigured() || empty($user->stripe_account_id)) {
            return null;
        }

        $state = $stripe->accountState($user->stripe_account_id);

        // Compte injoignable (panne réseau/API) : ne pas bloquer l'admin sur un doute.
        return $state['exists'] ? $state : null;
    }

    /** Message d'explication quand Stripe n'a pas (encore) activé le compte. */
    private function notReadyMessage(array $state): string
    {
        if ($state['transfers'] === 'pending') {
            return "Stripe vérifie encore ce compte (capability transfers = pending). "
                . 'Réessayez dans quelques minutes.';
        }

        $due = $state['requirements_due'] ?? [];
        if (!empty($due)) {
            // Le vendeur ne peut plus saisir tant qu'il est « en attente » :
            // c'est le REJET (avec motif) qui lui rouvre le formulaire.
            $message = "Stripe n'a pas activé ce compte : informations manquantes ("
                . implode(', ', array_slice($due, 0, 6))
                . (count($due) > 6 ? ', …' : '')
                . '). Rejetez le dossier en indiquant ce motif : le vendeur pourra alors le renvoyer.';

            // En mode test, Stripe REFUSE une identité réelle : seules ses valeurs
            // de vérification passent. Sans ce rappel, on croit à un dossier
            // incomplet alors que la saisie est correcte.
            if (app(\App\Services\StripeService::class)->mode() === 'test') {
                $message .= ' [mode test : Stripe n\'accepte que ses valeurs de vérification — '
                    . 'date de naissance 01/01/1901 et adresse « address_full_match ».]';
            }

            return $message;
        }

        return "Stripe n'a pas activé ce compte (transfers = {$state['transfers']}). "
            . 'Approbation impossible : les virements échoueraient.';
    }

    /** Notifie le vendeur (push + in-app), sans jamais faire échouer la requête. */
    private function notify(User $user, string $title, string $body, array $data): void
    {
        try {
            app(FirebaseMessagingService::class)->sendToUser($user, $title, $body, $data);
        } catch (\Exception $e) {
            Log::error('[ADMIN-STRIPE-CONNECT] Failed to send FCM notification', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Représentation admin d'un compte (jamais l'IBAN complet). */
    private function formatAccount(User $user): array
    {
        return [
            'id' => $user->id,
            'user' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'phone' => $user->phone,
                'email' => $user->email,
            ],
            'stripe_account_id' => $user->stripe_account_id,
            'status' => $user->stripe_account_status,
            'rejection_reason' => $user->stripe_rejection_reason,
            'account_holder_name' => $user->stripe_account_holder_name,
            'iban_last4' => $user->stripe_external_last4,
            'bank_country' => $user->stripe_bank_country,
            'submitted_at' => $user->stripe_submitted_at?->toIso8601String(),
            'verified_at' => $user->stripe_verified_at?->toIso8601String(),
        ];
    }
}
