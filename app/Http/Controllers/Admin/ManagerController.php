<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\ManagerCredentialsMail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Gestionnaires du back-office (product_manager…).
 *
 * L'admin saisit le nom et l'e-mail du gestionnaire et, s'il le souhaite,
 * lui ouvre d'autres sections. Le rôle n'est pas choisi : c'est toujours le
 * rôle gestionnaire (premier de admin_access.manager_roles). Un mot de passe est généré et envoyé
 * par e-mail. Réservé à l'admin : aucune permission de config/admin_access.php
 * ne couvre ces routes.
 */
class ManagerController extends Controller
{
    public function index(Request $request)
    {
        $query = User::query()->whereIn('role', array_keys(config('admin_access.manager_roles')));

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $managers = $query->latest()->paginate(20)->withQueryString();

        return view('admin.managers.index', [
            'managers' => $managers,
            'permissionLabels' => $this->permissionLabels(),
        ]);
    }

    public function create()
    {
        return $this->form(new User(['role' => $this->managerRole()]));
    }

    public function store(Request $request)
    {
        $validated = $this->validateManager($request);
        $role = $this->managerRole();
        $password = $this->generatePassword();

        try {
            // L'e-mail fait partie de la création : s'il ne part pas, le compte
            // n'est pas créé et l'admin peut réessayer.
            $manager = DB::transaction(function () use ($validated, $request, $password, $role) {
                $manager = User::create([
                    'first_name' => $validated['first_name'],
                    'last_name' => $validated['last_name'],
                    'email' => $validated['email'],
                    'password' => $password,
                    'role' => $role,
                    'roles' => [$role],
                    'admin_permissions' => $this->extraPermissions($validated, $role),
                    'created_by_admin_id' => $request->user()->id,
                    'is_profile_complete' => true,
                ]);
                $manager->forceFill(['email_verified_at' => now()])->save();

                Mail::to($manager->email)->send(new ManagerCredentialsMail($manager, $password));

                return $manager;
            });
        } catch (\Throwable $e) {
            Log::error('Création gestionnaire : échec', ['email' => $validated['email'], 'error' => $e->getMessage()]);

            return back()->withInput()
                ->with('error', "Le gestionnaire n'a pas été créé : l'e-mail d'accès n'a pas pu être envoyé. Vérifiez la configuration e-mail puis réessayez.");
        }

        return redirect()->route('admin.managers.index')
            ->with('success', "Gestionnaire {$manager->name} créé. Ses identifiants ont été envoyés à {$manager->email}.");
    }

    public function edit(User $manager)
    {
        $this->ensureManager($manager);

        return $this->form($manager);
    }

    public function update(Request $request, User $manager)
    {
        $this->ensureManager($manager);
        $validated = $this->validateManager($request, $manager);

        $manager->update([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'admin_permissions' => $this->extraPermissions($validated, $manager->role),
        ]);

        return redirect()->route('admin.managers.index')
            ->with('success', "Accès de {$manager->name} mis à jour.");
    }

    /**
     * Génère un nouveau mot de passe et le renvoie par e-mail.
     */
    public function resendCredentials(User $manager)
    {
        $this->ensureManager($manager);
        $password = $this->generatePassword();

        try {
            // L'ancien mot de passe reste valable si l'e-mail ne part pas.
            DB::transaction(function () use ($manager, $password) {
                $manager->update(['password' => $password]);
                Mail::to($manager->email)->send(new ManagerCredentialsMail($manager, $password, isReset: true));
            });
        } catch (\Throwable $e) {
            Log::error('Renvoi des accès gestionnaire : échec', ['user_id' => $manager->id, 'error' => $e->getMessage()]);

            return back()->with('error', "L'e-mail n'a pas pu être envoyé ; le mot de passe n'a pas été modifié.");
        }

        return back()->with('success', "Nouveaux identifiants envoyés à {$manager->email}.");
    }

    /**
     * Supprime le compte du gestionnaire (ses sessions tombent avec lui).
     */
    public function destroy(User $manager)
    {
        $this->ensureManager($manager);

        $name = $manager->name;
        $manager->tokens()->delete();
        $manager->delete();

        return redirect()->route('admin.managers.index')
            ->with('success', "Le gestionnaire {$name} a été supprimé.");
    }

    // ------------------------------------------------------------------

    private function form(User $manager)
    {
        return view('admin.managers.form', [
            'manager' => $manager,
            'permissionGroups' => config('admin_access.permissions'),
            'rolePermissions' => config("admin_access.role_permissions.{$manager->role}", []),
        ]);
    }

    private function validateManager(Request $request, ?User $manager = null): array
    {
        return $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($manager?->id),
            ],
            'permissions' => 'nullable|array',
            'permissions.*' => ['string', Rule::in(array_keys($this->permissionLabels()))],
        ], [
            'email.unique' => 'Cette adresse e-mail est déjà utilisée par un compte ASSO.',
        ]);
    }

    /**
     * Ne stocke que les permissions en plus de celles du rôle : si le rôle
     * évolue, ses permissions par défaut suivent sans migration.
     */
    private function extraPermissions(array $validated, string $role): array
    {
        $fromRole = config("admin_access.role_permissions.{$role}", []);

        return array_values(array_diff(array_unique($validated['permissions'] ?? []), $fromRole));
    }

    private function managerRole(): string
    {
        return array_key_first(config('admin_access.manager_roles'));
    }

    private function permissionLabels(): array
    {
        return array_merge(...array_values(config('admin_access.permissions')));
    }

    private function ensureManager(User $user): void
    {
        abort_unless(
            in_array($user->role, array_keys(config('admin_access.manager_roles')), true),
            404
        );
    }

    private function generatePassword(): string
    {
        return Str::password(12, symbols: false);
    }
}
