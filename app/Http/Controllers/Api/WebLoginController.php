<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WebLoginSession;
use App\Services\FirebaseMessagingService;
use App\Support\LinkSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Connexion par QR à l'espace vendeur web.
 *
 * Le navigateur crée une session et affiche son code en QR. L'application le
 * scanne et le vendeur confirme. Le navigateur, seul détenteur du secret, reçoit
 * alors une seule fois un jeton limité (config/web_vendor.php).
 *
 * Le code n'est pas un lien : il ne se lit que dans l'application, pour qu'un
 * message piégé ne puisse pas lancer la confirmation.
 */
class WebLoginController extends Controller
{
    /** Navigateur : nouvelle session et son QR. */
    public function create(Request $request): JsonResponse
    {
        abort_unless(LinkSettings::webVendorEnabled(), 404);

        $secret = Str::random(64);
        $code = Str::random(64);
        $agent = $request->userAgent();

        $session = WebLoginSession::create([
            'public_id' => (string) Str::uuid(),
            'secret_hash' => hash('sha256', $secret),
            'code_hash' => hash('sha256', $code),
            'status' => WebLoginSession::PENDING,
            'browser' => WebLoginSession::browserFrom($agent),
            'platform' => WebLoginSession::platformFrom($agent),
            'ip' => (string) $request->ip(),
            'expires_at' => now()->addSeconds(config('web_vendor.session_seconds')),
        ]);

        return response()->json([
            'success' => true,
            'session' => [
                'id' => $session->public_id,
                'secret' => $secret,
                'qr' => config('web_vendor.qr_prefix').$code,
                'expires_at' => $session->expires_at->toIso8601String(),
                'poll_interval' => config('web_vendor.poll_seconds'),
            ],
        ], 201);
    }

    /** Navigateur : où en est la session ; remet le jeton une seule fois. */
    public function poll(Request $request, string $id): JsonResponse
    {
        abort_unless(LinkSettings::webVendorEnabled(), 404);

        $validated = $request->validate(['secret' => 'required|string|max:128']);

        return DB::transaction(function () use ($id, $validated) {
            $session = WebLoginSession::where('public_id', $id)->lockForUpdate()->first();

            if (! $session || ! hash_equals($session->secret_hash, hash('sha256', $validated['secret']))) {
                return response()->json(['success' => false, 'message' => __('web_login.not_found')], 404);
            }

            if ($session->status === WebLoginSession::REJECTED) {
                return $this->status('rejected');
            }

            if ($session->status === WebLoginSession::CONSUMED || $session->isExpired()) {
                return $this->status('expired');
            }

            if ($session->status !== WebLoginSession::APPROVED) {
                return $this->status($session->status);
            }

            $user = $session->user;
            if (! $user || ! $this->canUseWebSpace($user)) {
                return $this->status('expired');
            }

            $token = $user->createToken(
                config('web_vendor.token_name'),
                [config('web_vendor.ability')],
                now()->addHours(config('web_vendor.token_hours')),
            );

            $session->update([
                'status' => WebLoginSession::CONSUMED,
                'consumed_at' => now(),
                'token_id' => $token->accessToken->getKey(),
            ]);

            return response()->json([
                'success' => true,
                'status' => 'approved',
                'token' => $token->plainTextToken,
                'user' => app(AuthController::class)->userPayload($user),
            ]);
        });
    }

    /** Application : l'entrée vers l'espace web est-elle proposée à ce vendeur ? */
    public function availability(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'available' => $this->canUseWebSpace($request->user()),
        ]);
    }

    /** Application : le QR a été lu, on montre au vendeur ce qu'il va autoriser. */
    public function scan(Request $request): JsonResponse
    {
        $session = $this->sessionFromCode($request);
        if ($session instanceof JsonResponse) {
            return $session;
        }

        $session->update([
            'status' => WebLoginSession::SCANNED,
            'user_id' => $request->user()->id,
            'scanned_at' => $session->scanned_at ?? now(),
        ]);

        return response()->json([
            'success' => true,
            'session' => [
                'browser' => $session->browser,
                'platform' => $session->platform,
                'ip' => $session->ip,
                'expires_at' => $session->expires_at->toIso8601String(),
            ],
        ]);
    }

    public function approve(Request $request): JsonResponse
    {
        $session = $this->sessionFromCode($request, requireScan: true);
        if ($session instanceof JsonResponse) {
            return $session;
        }

        $session->update([
            'status' => WebLoginSession::APPROVED,
            'approved_at' => now(),
            'expires_at' => now()->addSeconds(config('web_vendor.pickup_seconds')),
        ]);

        $this->notifyNewConnection($request->user(), $session);

        return response()->json(['success' => true, 'message' => __('web_login.approved')]);
    }

    public function reject(Request $request): JsonResponse
    {
        $session = $this->sessionFromCode($request, requireScan: true);
        if ($session instanceof JsonResponse) {
            return $session;
        }

        $session->update(['status' => WebLoginSession::REJECTED]);

        return response()->json(['success' => true]);
    }

    /** Application : navigateurs actuellement connectés à ce compte. */
    public function devices(Request $request): JsonResponse
    {
        $user = $request->user();

        $tokens = $user->tokens()
            ->where('name', config('web_vendor.token_name'))
            ->where('expires_at', '>', now())
            ->get()
            ->keyBy('id');

        $sessions = WebLoginSession::where('user_id', $user->id)
            ->whereIn('token_id', $tokens->keys())
            ->latest('id')
            ->get()
            ->map(fn (WebLoginSession $session) => [
                'id' => $session->token_id,
                'browser' => $session->browser,
                'platform' => $session->platform,
                'ip' => $session->ip,
                'connected_at' => $session->consumed_at?->toIso8601String(),
                'expires_at' => $tokens[$session->token_id]->expires_at?->toIso8601String(),
            ]);

        return response()->json(['success' => true, 'sessions' => $sessions]);
    }

    public function revoke(Request $request, int $id): JsonResponse
    {
        $deleted = $request->user()->tokens()
            ->where('name', config('web_vendor.token_name'))
            ->whereKey($id)
            ->delete();

        abort_unless($deleted, 404);

        return response()->json(['success' => true]);
    }

    private function status(string $status): JsonResponse
    {
        return response()->json(['success' => true, 'status' => $status]);
    }

    /** Vendeur avec une boutique, inscrit à l'espace web pendant les essais. */
    private function canUseWebSpace(User $user): bool
    {
        return LinkSettings::webVendorAllows($user)
            && $user->hasAnyRole(['vendeur', 'vendor'])
            && $user->shops()->exists();
    }

    /** Session désignée par le QR, ou la réponse d'erreur à renvoyer. */
    private function sessionFromCode(Request $request, bool $requireScan = false): WebLoginSession|JsonResponse
    {
        $validated = $request->validate(['code' => 'required|string|max:128']);
        $user = $request->user();

        if (! $this->canUseWebSpace($user)) {
            return response()->json(['success' => false, 'message' => __('web_login.not_available')], 403);
        }

        $session = WebLoginSession::where('code_hash', hash('sha256', $validated['code']))->first();

        $usable = $session
            && ! $session->isExpired()
            && in_array($session->status, $requireScan
                ? [WebLoginSession::SCANNED]
                : [WebLoginSession::PENDING, WebLoginSession::SCANNED], true)
            // Un QR déjà lu par un autre compte ne se réattribue pas.
            && ($session->user_id === null || $session->user_id === $user->id)
            && (! $requireScan || $session->user_id === $user->id);

        if (! $usable) {
            return response()->json(['success' => false, 'message' => __('web_login.invalid_code')], 410);
        }

        return $session;
    }

    private function notifyNewConnection(User $user, WebLoginSession $session): void
    {
        try {
            app(FirebaseMessagingService::class)->sendToUser(
                $user,
                $user->localized('web_login.notification_title'),
                $user->localized('web_login.notification_body', [
                    'browser' => $session->browser,
                    'platform' => $session->platform,
                ]),
                ['type' => 'web_login', 'notification_key' => 'web-login:'.$session->id],
            );
        } catch (\Throwable $e) {
            // La connexion reste valable : le vendeur vient de la confirmer lui-même.
            Log::warning('[WebLogin] notification non envoyée : '.$e->getMessage());
        }
    }
}
