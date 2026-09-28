<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * Display a listing of users with advanced filters
     */
    public function index(Request $request)
    {
        // Admin et gestionnaires se gèrent dans la section Gestionnaires.
        $query = User::query()->whereNotIn('role', config('admin_access.staff_roles'));

        // Search filter
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        // Role filter
        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        // Gender filter
        if ($request->filled('gender')) {
            $query->where('gender', $request->gender);
        }

        // Country filter
        if ($request->filled('country')) {
            $query->where('country', $request->country);
        }

        // Date range filter
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Sorting
        $sortField = $request->get('sort', 'created_at');
        $sortDirection = $request->get('direction', 'desc');
        $query->orderBy($sortField, $sortDirection);

        $users = $query->with('shops')->withCount('referrals')->paginate(15)->withQueryString();

        return view('admin.users.index', compact('users'));
    }

    /**
     * Show the form for creating a new user
     */
    public function create()
    {
        return view('admin.users.create');
    }

    /**
     * Store a newly created user
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'role' => 'required|in:client,vendeur,livreur',
            'gender' => 'nullable|in:male,female,other',
            'birth_date' => 'nullable|date',
            'phone' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:100',
            'address' => 'nullable|string',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
        ]);

        $validated['password'] = Hash::make($validated['password']);

        User::create($validated);

        return redirect()->route('admin.users.index')
            ->with('success', 'Utilisateur créé avec succès!');
    }

    /**
     * Display the specified user
     */
    public function show(User $user)
    {
        $user->load('shops.products');

        // P6 : forfaits souscrits par le vendeur, avec le code commercial éventuel.
        $packageSubscriptions = \App\Models\PackageSubscription::with(['package', 'salesAgent', 'salesCommission'])
            ->where('user_id', $user->id)
            ->latest()
            ->limit(50)
            ->get();

        return view('admin.users.show', compact('user', 'packageSubscriptions'));
    }

    /**
     * Show the form for editing the specified user
     */
    public function edit(User $user)
    {
        $this->ensureCanManage($user);

        return view('admin.users.edit', compact('user'));
    }

    /**
     * Update the specified user
     */
    public function update(Request $request, User $user)
    {
        $this->ensureCanManage($user);

        // Seul l'admin peut nommer un autre admin (un gestionnaire ayant la
        // section Utilisateurs ne doit pas pouvoir élever ses propres droits).
        $roles = $request->user()->isAdmin() ? 'client,vendeur,livreur,admin' : 'client,vendeur,livreur';

        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => ['required', 'email', Rule::unique('users')->ignore($user->id)],
            'role' => 'required|in:'.$roles,
            'gender' => 'nullable|in:male,female,other',
            'birth_date' => 'nullable|date',
            'phone' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:100',
            'address' => 'nullable|string',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
        ]);

        // Only update password if provided
        if ($request->filled('password')) {
            $request->validate([
                'password' => 'required|string|min:8|confirmed',
            ]);
            $validated['password'] = Hash::make($request->password);
        }

        $user->update($validated);

        return redirect()->route('admin.users.index')
            ->with('success', 'Utilisateur mis à jour avec succès!');
    }

    /**
     * Remove the specified user
     */
    public function destroy(User $user)
    {
        $this->ensureCanManage($user);

        if ($user->role === 'admin') {
            return redirect()->route('admin.users.index')
                ->with('error', 'Impossible de supprimer un administrateur!');
        }

        $user->delete();

        return redirect()->route('admin.users.index')
            ->with('success', 'Utilisateur supprimé avec succès!');
    }

    /**
     * Les comptes du back-office (hors admin connecté) ne se modifient pas
     * depuis la section Utilisateurs : ils relèvent de la section Gestionnaires.
     */
    private function ensureCanManage(User $user): void
    {
        abort_if(
            $user->isBackofficeStaff() && ! request()->user()->isAdmin(),
            403,
            'Ce compte se gère depuis la section Gestionnaires.'
        );
    }
}
