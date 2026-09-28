<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    /**
     * Show login form
     */
    public function showLogin()
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

        return view('admin.auth.login');
    }

    /**
     * Handle login request
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $credentials = $request->only('email', 'password');

        if (Auth::attempt($credentials, $request->filled('remember'))) {
            $user = Auth::user();

            // Admin ou gestionnaire (product_manager…) ; un gestionnaire sans
            // aucune section ouverte ne peut rien faire : on le refuse aussi.
            $home = $user->isBackofficeStaff() ? $user->adminHomeRoute() : null;
            if ($home === null) {
                Auth::logout();

                return back()->withErrors(['email' => 'Accès non autorisé.']);
            }

            $request->session()->regenerate();

            return redirect()->intended(route($home));
        }

        return back()->withErrors([
            'email' => 'Les identifiants fournis ne correspondent pas à nos enregistrements.',
        ])->onlyInput('email');
    }

    /**
     * Handle logout request
     */
    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
