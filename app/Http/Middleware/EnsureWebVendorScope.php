<?php

namespace App\Http\Middleware;

use App\Support\LinkSettings;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limite le jeton de l'espace vendeur web aux routes de config/web_vendor.php.
 *
 * Les jetons de l'application ne sont pas concernés. Refuser par défaut garde
 * l'argent et le compte hors du navigateur, y compris pour les routes à venir.
 * Couper l'interrupteur du back-office ferme aussitôt les sessions ouvertes.
 */
class EnsureWebVendorScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        if (! $request->bearerToken() || ! $route || ! in_array('auth:sanctum', $route->gatherMiddleware(), true)) {
            return $next($request);
        }

        $user = $request->user('sanctum');
        $token = $user?->currentAccessToken();
        $abilities = $token?->abilities ?? [];

        if (! in_array(config('web_vendor.ability'), $abilities, true)) {
            return $next($request);
        }

        $method = $request->isMethod('HEAD') ? 'GET' : $request->method();
        $allowed = in_array($method.' '.$route->uri(), config('web_vendor.routes'), true);

        if (! $allowed || ! LinkSettings::webVendorAllows($user)) {
            return response()->json([
                'success' => false,
                'code' => 'web_scope_denied',
                'message' => __('web_login.scope_denied'),
            ], 403);
        }

        $this->keepAlive($token);

        return $next($request);
    }

    /** Repousse la fin de session, au plus une écriture par heure. */
    private function keepAlive(PersonalAccessToken $token): void
    {
        $until = now()->addDays(config('web_vendor.token_idle_days'));

        if ($token->expires_at?->lt($until->copy()->subHour())) {
            $token->forceFill(['expires_at' => $until])->save();
        }
    }
}
