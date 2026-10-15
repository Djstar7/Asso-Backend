<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PackageSubscription;
use App\Models\SalesAgent;
use App\Models\SalesCommission;
use App\Models\Setting;
use App\Models\User;
use App\Services\SalesCommissionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * P6 — Gestion des commerciaux et tableau de suivi par commercial.
 */
class SalesAgentController extends Controller
{
    public function __construct(protected SalesCommissionService $salesCommissionService) {}

    public function index(Request $request)
    {
        $query = SalesAgent::query()
            ->withCount(['commissions as sales_count' => fn ($q) => $q->where('status', '!=', SalesCommission::STATUS_CANCELLED)])
            ->withSum(['commissions as revenue' => fn ($q) => $q->where('status', '!=', SalesCommission::STATUS_CANCELLED)], 'amount_paid_xaf')
            ->withSum(['commissions as due_amount' => fn ($q) => $q->where('status', SalesCommission::STATUS_DUE)], 'commission_amount')
            ->withSum(['commissions as paid_amount' => fn ($q) => $q->where('status', SalesCommission::STATUS_PAID)], 'commission_amount');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(fn ($q) => $q->where('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('code', 'like', '%'.SalesAgent::normalizeCode($search).'%'));
        }
        if ($request->status === 'active') {
            $query->where('is_active', true);
        } elseif ($request->status === 'inactive') {
            $query->where('is_active', false);
        }

        $agents = $query->orderBy('last_name')->orderBy('first_name')->paginate(20)->withQueryString();

        $stats = [
            'agents' => SalesAgent::count(),
            'active' => SalesAgent::active()->count(),
            'due' => (float) SalesCommission::due()->sum('commission_amount'),
            'paid' => (float) SalesCommission::paid()->sum('commission_amount'),
        ];
        $defaultRate = $this->salesCommissionService->defaultRate();
        $programTerms = $this->salesCommissionService->programTerms();

        return view('admin.sales.agents.index', compact('agents', 'stats', 'defaultRate', 'programTerms'));
    }

    public function create()
    {
        return view('admin.sales.agents.form', [
            'agent' => new SalesAgent(['is_active' => true]),
            'defaultRate' => $this->salesCommissionService->defaultRate(),
        ]);
    }

    public function store(Request $request)
    {
        $agent = SalesAgent::create($this->validated($request));

        return redirect()->route('admin.sales.agents.show', $agent)
            ->with('success', "Commercial créé. Son code : <strong>{$agent->code}</strong>");
    }

    /** Tableau de suivi d'un commercial : indicateurs + historique de ses ventes. */
    public function show(Request $request, SalesAgent $agent)
    {
        $agent->load('user');

        $base = SalesCommission::where('sales_agent_id', $agent->id);
        $stats = [
            'sales' => (clone $base)->where('status', '!=', SalesCommission::STATUS_CANCELLED)->count(),
            'revenue' => (float) (clone $base)->where('status', '!=', SalesCommission::STATUS_CANCELLED)->sum('amount_paid_xaf'),
            'due' => (float) (clone $base)->due()->sum('commission_amount'),
            'paid' => (float) (clone $base)->paid()->sum('commission_amount'),
        ];

        $commissions = (clone $base)->with(['vendor', 'paidBy'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest('sold_at')
            ->paginate(25)
            ->withQueryString();

        // Tentatives avec ce code dont le paiement n'a pas abouti (en attente / échoué).
        $unpaidAttempts = PackageSubscription::with(['user', 'package'])
            ->where('sales_agent_id', $agent->id)
            ->where('status', '!=', 'paid')
            ->latest()
            ->limit(20)
            ->get();

        return view('admin.sales.agents.show', compact('agent', 'stats', 'commissions', 'unpaidAttempts'));
    }

    public function edit(SalesAgent $agent)
    {
        return view('admin.sales.agents.form', [
            'agent' => $agent,
            'defaultRate' => $this->salesCommissionService->defaultRate(),
        ]);
    }

    public function update(Request $request, SalesAgent $agent)
    {
        $agent->update($this->validated($request, $agent));

        return redirect()->route('admin.sales.agents.show', $agent)->with('success', 'Commercial mis à jour.');
    }

    public function toggleActive(SalesAgent $agent)
    {
        $agent->update(['is_active' => ! $agent->is_active]);

        return back()->with('success', $agent->is_active ? 'Commercial activé : son code est de nouveau accepté.' : 'Commercial désactivé : son code n\'est plus accepté.');
    }

    /** Nouveau code aléatoire (l'ancien n'est plus accepté ; l'historique garde le code utilisé). */
    public function regenerateCode(SalesAgent $agent)
    {
        $agent->update(['code' => SalesAgent::generateCode()]);

        return back()->with('success', "Nouveau code : <strong>{$agent->code}</strong>");
    }

    /** Taux de commission par défaut (%) appliqué aux commerciaux sans taux particulier. */
    public function updateDefaultRate(Request $request)
    {
        $validated = $request->validate([
            'sales_commission_rate' => 'required|numeric|min:0|max:100',
        ]);

        Setting::set('sales_commission_rate', $validated['sales_commission_rate'], 'string', 'commissions', 'Commission des commerciaux sur les forfaits souscrits avec leur code (%)');

        return back()->with('success', 'Taux par défaut mis à jour. Il s\'applique aux prochaines ventes, jamais aux commissions déjà enregistrées.');
    }

    /** Modalités affichées dans l'application avant qu'un utilisateur devienne commercial. */
    public function updateProgramTerms(Request $request)
    {
        $validated = $request->validate([
            'sales_program_terms' => 'required|string|max:20000',
        ]);

        Setting::set('sales_program_terms', $validated['sales_program_terms'], 'text', 'commissions', 'Modalités du programme « Devenir un commercial ASSO »');

        return back()->with('success', 'Modalités du programme commercial mises à jour.');
    }

    private function validated(Request $request, ?SalesAgent $agent = null): array
    {
        if ($request->filled('code')) {
            $request->merge(['code' => SalesAgent::normalizeCode($request->code)]);
        }

        $validated = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:30',
            'code' => ['nullable', 'string', 'min:4', 'max:32', 'regex:/^[A-Z0-9-]+$/', Rule::unique('sales_agents', 'code')->ignore($agent?->id)],
            'commission_rate' => 'nullable|numeric|min:0|max:100',
            'user_email' => 'nullable|email|exists:users,email',
            'notes' => 'nullable|string|max:2000',
        ], [
            'code.regex' => 'Le code ne peut contenir que des lettres, des chiffres et des tirets.',
            'code.unique' => 'Ce code est déjà attribué à un autre commercial.',
            'user_email.exists' => 'Aucun compte ASSO avec cet email.',
        ]);

        $validated['user_id'] = ! empty($validated['user_email'])
            ? User::where('email', $validated['user_email'])->value('id')
            : null;
        unset($validated['user_email']);
        $validated['is_active'] = $request->boolean('is_active');

        if (empty($validated['code'])) {
            // Création : code généré automatiquement ; modification : code conservé.
            $validated['code'] = $agent?->code;
        }

        return $validated;
    }
}
