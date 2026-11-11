<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\ManagerCredentialsMail;
use App\Models\Category;
use App\Models\Division;
use App\Models\DivisionCommission;
use App\Models\DivisionHead;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Rubriques de la plateforme et leurs chefs (admin uniquement).
 *
 * L'admin crée une rubrique (catégories du catalogue ou Immobilier), puis nomme
 * son chef : un compte « Chef de rubrique » est créé et ses identifiants partent
 * par e-mail, avec le pourcentage de la commission ASSO qui lui revient. Un
 * changement de chef ou de taux ferme l'affectation en cours et en ouvre une
 * nouvelle : les ventes passées gardent leur chef et leur taux.
 */
class DivisionController extends Controller
{
    public function index()
    {
        $divisions = Division::with(['categories', 'currentHead.user'])->orderBy('name')->get();
        $totals = DivisionCommission::selectRaw('division_id, status, SUM(amount) as total')
            ->groupBy('division_id', 'status')->get()->groupBy('division_id');

        return view('admin.divisions.index', compact('divisions', 'totals'));
    }

    public function create()
    {
        return $this->form(new Division(['type' => Division::TYPE_CATEGORY, 'is_active' => true]));
    }

    public function store(Request $request)
    {
        $validated = $this->validateDivision($request);

        $division = DB::transaction(function () use ($validated) {
            $division = Division::create($validated);
            $division->categories()->sync($division->isRealEstate() ? [] : $validated['categories'] ?? []);

            return $division;
        });

        return redirect()->route('admin.divisions.show', $division)
            ->with('success', "Rubrique {$division->name} créée. Nommez maintenant son chef.");
    }

    public function show(Division $division)
    {
        $division->load(['categories', 'currentHead.user', 'heads.user', 'heads.creator']);
        $commissions = DivisionCommission::where('division_id', $division->id)->with('user')->latest('sold_at')->paginate(30);
        $summary = DivisionCommission::where('division_id', $division->id)
            ->selectRaw('status, SUM(amount) as total, COUNT(*) as n')->groupBy('status')->get()->keyBy('status');
        // Chefs déjà créés, réutilisables (un chef peut avoir plusieurs rubriques).
        $chiefs = User::where('role', 'division_head')->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'email']);

        return view('admin.divisions.show', compact('division', 'commissions', 'summary', 'chiefs'));
    }

    public function edit(Division $division)
    {
        return $this->form($division->load('categories'));
    }

    public function update(Request $request, Division $division)
    {
        $validated = $this->validateDivision($request, $division);

        DB::transaction(function () use ($division, $validated) {
            $division->update($validated);
            $division->categories()->sync($division->isRealEstate() ? [] : $validated['categories'] ?? []);
        });

        return redirect()->route('admin.divisions.show', $division)->with('success', 'Rubrique mise à jour.');
    }

    /** Nomme le chef de la rubrique (nouveau compte ou chef existant) ; remplace le chef en poste. */
    public function appoint(Request $request, Division $division)
    {
        $validated = $request->validate([
            'mode' => ['required', Rule::in(['new', 'existing'])],
            'user_id' => ['required_if:mode,existing', 'nullable', 'integer', Rule::exists('users', 'id')->where('role', 'division_head')],
            'first_name' => 'required_if:mode,new|nullable|string|max:255',
            'last_name' => 'required_if:mode,new|nullable|string|max:255',
            'email' => ['required_if:mode,new', 'nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'commission_rate' => 'required|numeric|min:0|max:100',
        ], [
            'email.unique' => 'Cette adresse e-mail est déjà utilisée par un compte ASSO.',
            'commission_rate.max' => 'Le pourcentage ne peut pas dépasser 100 % de la commission ASSO.',
        ]);
        $password = $validated['mode'] === 'new' ? Str::password(12, symbols: false) : null;

        try {
            $head = DB::transaction(function () use ($validated, $division, $request, $password) {
                $user = $validated['mode'] === 'new'
                    ? $this->createChiefAccount($validated, $request->user(), $password)
                    : User::findOrFail($validated['user_id']);

                $this->closeCurrent($division, 'Remplacé');

                return DivisionHead::create([
                    'division_id' => $division->id,
                    'user_id' => $user->id,
                    'commission_rate' => $validated['commission_rate'],
                    'started_at' => now(),
                    'created_by' => $request->user()->id,
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('Nomination chef de rubrique : échec', ['division_id' => $division->id, 'error' => $e->getMessage()]);

            return back()->withInput()->with('error', $password
                ? "Le chef n'a pas été nommé : l'e-mail d'accès n'a pas pu être envoyé. Vérifiez la configuration e-mail puis réessayez."
                : "Le chef n'a pas été nommé.");
        }

        $head->load('user');

        return redirect()->route('admin.divisions.show', $division)->with('success',
            "{$head->user->name} est chef de la rubrique {$division->name} ({$head->commission_rate} % de la commission ASSO)."
            .($password ? " Ses identifiants ont été envoyés à {$head->user->email}." : ''));
    }

    /** Nouveau taux pour le chef en poste : valable pour les ventes à venir uniquement. */
    public function changeRate(Request $request, Division $division)
    {
        $validated = $request->validate(['commission_rate' => 'required|numeric|min:0|max:100']);
        $current = $division->currentHead()->first();
        abort_unless($current, 404);

        DB::transaction(function () use ($division, $current, $validated, $request) {
            $this->closeCurrent($division, 'Changement de taux');
            DivisionHead::create([
                'division_id' => $division->id,
                'user_id' => $current->user_id,
                'commission_rate' => $validated['commission_rate'],
                'started_at' => now(),
                'created_by' => $request->user()->id,
            ]);
        });

        return back()->with('success', "Nouveau taux : {$validated['commission_rate']} %, appliqué aux ventes à partir de maintenant.");
    }

    /** Met fin au mandat du chef : il perd l'accès à la rubrique, garde ses gains et son retrait. */
    public function endHead(Division $division)
    {
        abort_unless($division->currentHead()->exists(), 404);
        $this->closeCurrent($division, 'Fin de mandat');

        return back()->with('success', 'Mandat terminé. Les commissions déjà acquises ou en attente restent dues au chef.');
    }

    public function resendCredentials(Division $division, DivisionHead $head)
    {
        abort_unless($head->division_id === $division->id && $head->user?->role === 'division_head', 404);
        $user = $head->user;
        $password = Str::password(12, symbols: false);

        try {
            DB::transaction(function () use ($user, $password) {
                $user->update(['password' => $password]);
                Mail::to($user->email)->send(new ManagerCredentialsMail($user, $password, isReset: true));
            });
        } catch (\Throwable $e) {
            Log::error('Renvoi des accès chef de rubrique : échec', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return back()->with('error', "L'e-mail n'a pas pu être envoyé ; le mot de passe n'a pas été modifié.");
        }

        return back()->with('success', "Nouveaux identifiants envoyés à {$user->email}.");
    }

    // ------------------------------------------------------------------

    private function form(Division $division)
    {
        // Catégories déjà rattachées à une autre rubrique : non proposées.
        $taken = DB::table('division_category')->where('division_id', '<>', $division->id ?? 0)->pluck('category_id')->all();
        $categories = Category::orderBy('name')->get()->reject(fn ($c) => in_array($c->id, $taken));

        return view('admin.divisions.form', compact('division', 'categories'));
    }

    private function validateDivision(Request $request, ?Division $division = null): array
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'name_en' => 'nullable|string|max:255',
            'type' => ['required', Rule::in(array_keys(Division::TYPES))],
            'categories' => 'required_if:type,'.Division::TYPE_CATEGORY.'|array',
            'categories.*' => ['integer', 'exists:categories,id',
                Rule::unique('division_category', 'category_id')->where(fn ($q) => $q->where('division_id', '<>', $division?->id ?? 0))],
        ], [
            'categories.required_if' => 'Choisissez au moins une catégorie.',
            'categories.*.unique' => 'Une des catégories appartient déjà à une autre rubrique.',
        ]);

        // Une seule rubrique Immobilier (le module n'a pas de catégories).
        if ($validated['type'] === Division::TYPE_REAL_ESTATE
            && Division::where('type', Division::TYPE_REAL_ESTATE)->whereKeyNot($division?->id ?? 0)->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['type' => 'Une rubrique Immobilier existe déjà.']);
        }

        $validated['is_active'] = $request->boolean('is_active', true);

        return $validated;
    }

    private function closeCurrent(Division $division, string $reason): void
    {
        DivisionHead::where('division_id', $division->id)->whereNull('ended_at')->lockForUpdate()->get()
            ->each(fn (DivisionHead $h) => $h->update(['ended_at' => now(), 'end_reason' => $reason]));
    }

    private function createChiefAccount(array $validated, User $admin, string $password): User
    {
        $user = User::create([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'password' => $password,
            'role' => 'division_head',
            'roles' => ['division_head'],
            'admin_permissions' => [],
            'created_by_admin_id' => $admin->id,
            'is_profile_complete' => true,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        // Dans la transaction : sans e-mail, pas de compte.
        Mail::to($user->email)->send(new ManagerCredentialsMail($user, $password));

        return $user;
    }
}
