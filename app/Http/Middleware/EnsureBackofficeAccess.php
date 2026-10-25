<?php

namespace App\Http\Middleware;

use App\Support\AssoScope;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Filtre le back-office : seuls l'admin et les gestionnaires y entrent, et un
 * gestionnaire ne voit que les sections de ses permissions
 * (config/admin_access.php), limitées aux boutiques ASSO (AssoScope).
 */
class EnsureBackofficeAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        // Le back-office est rédigé en français : les libellés traduits (__())
        // ne suivent pas APP_LOCALE, réservée à l'API (SetLocale).
        App::setLocale('fr');

        $user = $request->user();

        if (! $user || ! $user->isBackofficeStaff()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                return response()->json(['message' => 'Accès non autorisé à l’administration.'], 403);
            }

            return redirect()->route('admin.login')->withErrors(['email' => 'Accès non autorisé.']);
        }

        $routeName = $request->route()?->getName();

        // Route sans nom : impossible à rattacher à une permission, admin uniquement.
        if ($routeName === null ? $user->isAdmin() : $user->canAccessAdminRoute($routeName)) {
            if (AssoScope::restricts($user)) {
                AssoScope::enable();

                // Fiche d'un vendeur particulier ouverte par son adresse : introuvable.
                foreach ($request->route()?->parameters() ?? [] as $parameter) {
                    abort_if($parameter instanceof Model && ! AssoScope::covers($parameter), 404);
                }
            }

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
