<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StripeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Onboarding Stripe Connect côté VENDEUR.
 *
 * Le vendeur soumet son pays + IBAN (+ titulaire) → on crée (ou met à jour) son
 * compte Stripe Connect « Custom-equivalent » et on attache l'IBAN comme compte
 * externe. Le statut interne ASSO passe alors à `pending` : un admin ASSO valide
 * ou rejette ensuite (porte interne, distincte de la vérification KYC de Stripe).
 *
 * Voir Admin\StripeConnectController pour la validation, et [[stripe-connect-state]].
 */
class StripeConnectController extends Controller
{
    public function __construct(private StripeService $stripe)
    {
    }

    /**
     * Statut d'onboarding Stripe du vendeur connecté.
     * GET /v1/stripe/connect/status
     */
    public function status(Request $request)
    {
        $user = $request->user();

        $data = $this->formatStatus($user);

        // État RÉEL côté Stripe : un compte peut être `approved` chez nous mais encore
        // bloqué par Stripe (vérification en cours ou pièce manquante). Le vendeur doit
        // le savoir avant de tenter un virement qui échouerait.
        if (!empty($user->stripe_account_id) && $this->stripe->isConfigured()) {
            $state = $this->stripe->accountState($user->stripe_account_id);
            $data['stripe'] = [
                'ready' => $state['ready'],
                'verification' => $this->verificationLabel($state),
                'requirements_due' => $state['requirements_due'],
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /** Libellé de l'état de vérification Stripe, affichable tel quel par le mobile. */
    private function verificationLabel(array $state): string
    {
        if ($state['ready']) {
            return 'Compte vérifié par Stripe.';
        }

        return match ($state['transfers']) {
            'pending' => 'Vérification en cours chez Stripe (quelques minutes).',
            'inactive' => empty($state['requirements_due'])
                ? "Compte pas encore activé par Stripe."
                : 'Informations complémentaires demandées par Stripe.',
            default => "État du compte indisponible pour le moment.",
        };
    }

    /**
     * Soumet (ou met à jour) les informations bancaires du vendeur.
     * POST /v1/stripe/connect/submit
     */
    public function submit(Request $request)
    {
        if (!$this->stripe->isConfigured()) {
            return response()->json([
                'success' => false,
                'message' => "Le paiement par virement (Stripe) n'est pas encore disponible.",
            ], 503);
        }

        $validated = $request->validate([
            'country' => 'required|string|size:2',
            // IBAN : 2 lettres pays + 2 chiffres clé + 11 à 30 caractères alphanum.
            'iban' => 'required|string|regex:/^[A-Za-z]{2}[0-9]{2}[A-Za-z0-9]{11,30}$/',
            'account_holder_name' => 'required|string|max:255',
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            // KYC EXIGÉ PAR STRIPE : sans ces informations le compte reste bloqué en
            // `requirements.past_due` et AUCUN virement ne peut aboutir.
            'birth_date' => 'required|date|before:-18 years',
            'phone' => 'required|string|max:30',
            'address_line1' => 'required|string|max:255',
            'address_line2' => 'nullable|string|max:255',
            'address_city' => 'required|string|max:120',
            'address_postal_code' => 'required|string|max:20',
            'address_state' => 'nullable|string|max:120',
        ]);

        $user = $request->user();

        // Un compte déjà validé ne peut pas changer d'IBAN librement (sécurité).
        if ($user->stripe_account_status === 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'Votre compte de virement est déjà validé. Contactez le support pour modifier votre IBAN.',
            ], 422);
        }

        $iban = strtoupper(preg_replace('/\s+/', '', $validated['iban']));
        $country = strtoupper($validated['country']);
        $holder = $validated['account_holder_name'];

        // Informations d'identité transmises à Stripe (jamais stockées chez nous).
        $kyc = [
            'country' => $country,
            'first_name' => $validated['first_name'] ?? $user->first_name,
            'last_name' => $validated['last_name'] ?? $user->last_name,
            'email' => $user->email,
            'birth_date' => $validated['birth_date'],
            'phone' => $validated['phone'],
            'address_line1' => $validated['address_line1'],
            'address_line2' => $validated['address_line2'] ?? null,
            'address_city' => $validated['address_city'],
            'address_postal_code' => $validated['address_postal_code'],
            'address_state' => $validated['address_state'] ?? null,
        ];

        try {
            if (empty($user->stripe_account_id)) {
                // Premier envoi : créer le compte Connect + attacher l'IBAN.
                $result = $this->stripe->createCustomAccountWithBank($kyc + [
                    'iban' => $iban,
                    'account_holder_name' => $holder,
                    'user_id' => $user->id,
                    'tos_ip' => $request->ip(),
                    'tos_date' => time(),
                ]);
                $accountId = $result['id'];
            } else {
                // Renvoi (compte rejeté ou en attente) : remplacer l'IBAN ET
                // rafraîchir le KYC (il est souvent la cause du rejet).
                $result = $this->stripe->replaceExternalAccount($user->stripe_account_id, [
                    'country' => $country,
                    'iban' => $iban,
                    'account_holder_name' => $holder,
                ]);
                $this->stripe->updateAccountKyc($user->stripe_account_id, $kyc);
                $accountId = $user->stripe_account_id;
            }

            $user->update([
                'stripe_account_id' => $accountId,
                'stripe_account_status' => 'pending',
                'stripe_rejection_reason' => null,
                'stripe_submitted_at' => now(),
                'stripe_external_last4' => $result['external_last4'] ?? null,
                'stripe_bank_country' => $result['bank_country'] ?? $country,
                'stripe_account_holder_name' => $holder,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Informations bancaires enregistrées. Votre compte de virement sera vérifié sous 24-48h.',
                'data' => $this->formatStatus($user->fresh()),
            ]);
        } catch (\Throwable $e) {
            Log::error('[StripeConnectController] Échec soumission Stripe Connect', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $this->friendlyErrorMessage($e),
            ], 422);
        }
    }

    /**
     * Traduit une exception (souvent brute de Stripe) en message clair pour le
     * vendeur, sans jamais exposer le détail technique interne (URL d'API, code
     * errno réseau, etc.) qui reste tracé côté serveur via Log::error.
     */
    private function friendlyErrorMessage(\Throwable $e): string
    {
        // Problème de connexion entre notre serveur et Stripe (DNS, réseau, timeout).
        if ($e instanceof \Stripe\Exception\ApiConnectionException) {
            return "Le service de virement bancaire est momentanément indisponible. "
                . "Veuillez réessayer dans quelques instants.";
        }

        // Paramètres refusés par Stripe : le plus souvent un IBAN ou un pays invalide.
        if ($e instanceof \Stripe\Exception\InvalidRequestException) {
            $raw = strtolower($e->getMessage());
            if (str_contains($raw, 'iban') || str_contains($raw, 'bank') || str_contains($raw, 'account_number')) {
                return "L'IBAN saisi semble invalide. Vérifiez-le puis réessayez.";
            }
            // Stripe formule le pays non supporté sans le mot « country »
            // (ex. « CM is not currently supported by Stripe. ») : sans ce test, le
            // vendeur recevait un message générique parlant d'informations invalides.
            if (str_contains($raw, 'country') || str_contains($raw, 'not currently supported')) {
                return "Le pays du compte bancaire n'est pas pris en charge pour les virements.";
            }
            return "Certaines informations bancaires sont invalides. Vérifiez vos données puis réessayez.";
        }

        // Clés API absentes / invalides côté plateforme : ce n'est pas la faute du vendeur.
        if ($e instanceof \Stripe\Exception\AuthenticationException
            || $e instanceof \RuntimeException) {
            return "Le paiement par virement (Stripe) n'est pas encore disponible. "
                . "Veuillez réessayer plus tard.";
        }

        // Toute autre erreur Stripe : message générique, sans détail technique.
        if ($e instanceof \Stripe\Exception\ApiErrorException) {
            return "Impossible d'enregistrer vos informations bancaires pour le moment. "
                . "Veuillez réessayer plus tard.";
        }

        return "Une erreur est survenue lors de l'enregistrement de vos informations bancaires. "
            . "Veuillez réessayer.";
    }

    /** Représentation publique du statut d'onboarding (jamais l'IBAN complet). */
    private function formatStatus($user): array
    {
        return [
            'status' => $user->stripe_account_status, // null | pending | approved | rejected
            'rejection_reason' => $user->stripe_rejection_reason,
            'submitted_at' => $user->stripe_submitted_at,
            'verified_at' => $user->stripe_verified_at,
            'iban_last4' => $user->stripe_external_last4,
            'bank_country' => $user->stripe_bank_country,
            'account_holder_name' => $user->stripe_account_holder_name,
            'has_account' => !empty($user->stripe_account_id),
        ];
    }
}
