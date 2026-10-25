<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Connexion au back-office. Deux pages partagent la même vue : celle de
 * l'admin (/admin/login) et l'espace gestionnaire (/admin/gestionnaire/login).
 * Chacun se connecte sur la sienne ; un compte arrivé sur la mauvaise page
 * y est renvoyé, sans être connecté.
 */
class AuthController extends Controller
{
    /**
     * Show login form
     */
    public function showLogin()
    {
        return $this->showPortal('admin');
    }

    /**
     * Page de connexion de l'espace gestionnaire.
     */
    public function showManagerLogin()
    {
        return $this->showPortal('manager');
    }

    /**
     * Handle login request
     */
    public function login(Request $request)
    {
        return $this->attemptLogin($request, 'admin');
    }

    /**
     * Connexion depuis l'espace gestionnaire.
     */
    public function managerLogin(Request $request)
    {
        return $this->attemptLogin($request, 'manager');
    }

    /**
     * Handle logout request
     */
    public function logout(Request $request)
    {
        // Retour sur la page de connexion de l'espace qu'on quitte.
        $loginRoute = $this->loginRouteFor($request->user());

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route($loginRoute);
    }

    private function showPortal(string $portal)
    {
        if (Auth::check() && Auth::user()->isBackofficeStaff()) {
            return redirect()->route(Auth::user()->adminHomeRoute() ?? 'admin.dashboard');
        }

        // If user is authenticated but not staff, log them out to avoid redirect loop
        if (Auth::check()) {
            Auth::logout();
            request()->session()->invalidate();
            request()->session()->regenerateToken();
        }

        return view('admin.auth.login', ['portal' => $portal]);
    }

    private function attemptLogin(Request $request, string $portal)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $credentials = $request->only('email', 'password');
        // Both portals share the same limits; changing portal must not reset them.
        $accountKey = 'admin-login:'.hash('sha256', Str::lower($credentials['email']));
        $ipKey = 'admin-login-ip:'.$request->ip();
        if (RateLimiter::tooManyAttempts($accountKey, 5) || RateLimiter::tooManyAttempts($ipKey, 30)) {
            $seconds = max(RateLimiter::availableIn($accountKey), RateLimiter::availableIn($ipKey));

            return response()->view('errors.admin', ['status' => 429, 'message' => "Trop de tentatives. Réessayez dans {$seconds} secondes."], 429)
                ->header('Retry-After', (string) $seconds);
        }
        RateLimiter::hit($accountKey, 60);
        RateLimiter::hit($ipKey, 60);

        if (Auth::attempt($credentials, $request->filled('remember'))) {
            $user = Auth::user();

            // Admin ou gestionnaire (product_manager…) ; un gestionnaire sans
            // aucune section ouverte ne peut rien faire : on le refuse aussi.
            $home = $user->isBackofficeStaff() ? $user->adminHomeRoute() : null;
            if ($home === null) {
                Auth::logout();

                return back()->withErrors(['email' => 'Accès non autorisé.']);
            }

            // Mauvaise page : on renvoie vers celle du compte.
            $expected = $user->isAdmin() ? 'admin' : 'manager';
            if ($expected !== $portal) {
                Auth::logout();

                return redirect()->route($this->loginRouteFor($user))
                    ->withInput($request->only('email'))
                    ->with('portal_notice', $expected === 'admin'
                        ? 'Ce compte est un compte administrateur : connectez-vous ici.'
                        : 'Ce compte est un compte gestionnaire : connectez-vous depuis votre espace.');
            }

            $request->session()->regenerate();
            RateLimiter::clear($accountKey);

            return redirect()->intended(route($home));
        }

        return back()->withErrors([
            'email' => 'Les identifiants fournis ne correspondent pas à nos enregistrements.',
        ])->onlyInput('email');
    }

    private function loginRouteFor(?User $user): string
    {
        return $user && $user->isBackofficeStaff() && ! $user->isAdmin()
            ? 'admin.manager.login'
            : 'admin.login';
    }
}
