<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Filtre le back-office : seuls l'admin et les gestionnaires y entrent, et un
 * gestionnaire ne voit que les sections de ses permissions
 * (config/admin_access.php).
 */
class EnsureBackofficeAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isBackofficeStaff()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('admin.login')->withErrors(['email' => 'Accès non autorisé.']);
        }

        $routeName = $request->route()?->getName();

        // Route sans nom : impossible à rattacher à une permission, admin uniquement.
        if ($routeName === null ? $user->isAdmin() : $user->canAccessAdminRoute($routeName)) {
            return $next($request);
        }

        // Le Dashboard est la page d'arrivée par défaut : on renvoie le
        // gestionnaire vers sa propre page d'accueil plutôt qu'une erreur.
        if ($routeName === 'admin.dashboard' && ($home = $user->adminHomeRoute())) {
            return redirect()->route($home);
        }

        abort(403, "Vous n'avez pas accès à cette section.");
    }
}
