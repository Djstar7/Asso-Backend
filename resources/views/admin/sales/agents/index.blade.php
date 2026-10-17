@extends('admin.layouts.app')

@section('title', 'Commerciaux')
@section('header', 'Commerciaux')

@section('content')
@php $fmt = fn ($v) => number_format((float) $v, 0, ',', ' ') . ' F'; @endphp
<div class="p-6">
    <div class="flex flex-col md:flex-row md:justify-between md:items-center gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-white">Commerciaux</h1>
            <p class="text-gray-400 mt-1">Codes commerciaux et commissions sur les forfaits souscrits par les vendeurs</p>
        </div>
        <div class="flex gap-3">
            <a href="{{ route('admin.sales.commissions.index', ['status' => 'due']) }}" class="px-4 py-2 bg-dark-200 text-white rounded-lg hover:bg-dark-300 transition-all">
                <i class="fas fa-money-bill-wave mr-2"></i>Commissions à payer
            </a>
            <a href="{{ route('admin.sales.agents.create') }}" class="px-4 py-2 bg-primary-500 text-white rounded-lg hover:bg-primary-600 transition-all">
                <i class="fas fa-plus mr-2"></i>Nouveau commercial
            </a>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        @foreach([
            ['Commerciaux', $stats['agents'], 'fa-user-tie', 'text-white', 'primary'],
            ['Actifs', $stats['active'], 'fa-user-check', 'text-green-500', 'green'],
            ['Commissions à payer', $fmt($stats['due']), 'fa-hourglass-half', 'text-yellow-500', 'yellow'],
            ['Commissions payées', $fmt($stats['paid']), 'fa-check-circle', 'text-blue-500', 'blue'],
        ] as [$label, $value, $icon, $color, $bg])
            <div class="bg-dark-100 rounded-xl shadow-lg p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-400 text-sm">{{ $label }}</p>
                        <p class="text-2xl font-bold {{ $color }} mt-1">{{ $value }}</p>
                    </div>
                    <div class="w-12 h-12 bg-{{ $bg }}-500/20 rounded-lg flex items-center justify-center">
                        <i class="fas {{ $icon }} text-{{ $bg }}-500 text-xl"></i>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <!-- Barème par défaut -->
    <form method="POST" action="{{ route('admin.sales.agents.default-rate') }}" class="bg-dark-100 rounded-xl shadow-lg p-4 mb-6 flex flex-col md:flex-row md:items-center gap-3">
        @csrf
        <div class="flex-1">
            <p class="text-white font-medium"><i class="fas fa-percent mr-2 text-primary-500"></i>Taux de commission par défaut</p>
            <p class="text-xs text-gray-400 mt-1">Pourcentage du montant payé par le vendeur pour son forfait. Un taux particulier peut être fixé sur la fiche d'un commercial. Un changement ne s'applique qu'aux prochaines ventes.</p>
        </div>
        <div class="relative">
            <input type="number" name="sales_commission_rate" value="{{ old('sales_commission_rate', $defaultRate) }}" min="0" max="100" step="0.01" required
                   class="w-32 px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white pr-8">
            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm">%</span>
        </div>
        <button type="submit" class="px-4 py-2 bg-primary-500 text-white rounded-lg hover:bg-primary-600">Enregistrer</button>
    </form>

    <!-- Modalités du programme -->
    <form method="POST" action="{{ route('admin.sales.agents.program-terms') }}" class="bg-dark-100 rounded-xl shadow-lg p-4 mb-6">
        @csrf
        <p class="text-white font-medium"><i class="fas fa-file-signature mr-2 text-primary-500"></i>Modalités « Devenir un commercial ASSO »</p>
        <p class="text-xs text-gray-400 mt-1 mb-3">Texte affiché dans l'application avant que l'utilisateur obtienne son code. Le taux par défaut ci-dessus y est affiché automatiquement. La commission est versée une seule fois par vendeur, sur son premier forfait, directement dans le wallet du commercial.</p>
        <textarea name="sales_program_terms" rows="8" required maxlength="20000"
                  class="w-full px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white text-sm">{{ old('sales_program_terms', $programTerms) }}</textarea>
        @error('sales_program_terms')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
        <div class="flex justify-end mt-3">
            <button type="submit" class="px-4 py-2 bg-primary-500 text-white rounded-lg hover:bg-primary-600">Enregistrer les modalités</button>
        </div>
    </form>

    <!-- Filtres -->
    <div class="bg-dark-100 rounded-xl shadow-lg p-4 mb-6">
        <form method="GET" action="{{ route('admin.sales.agents.index') }}" class="grid grid-cols-1 md:grid-cols-4 gap-3">
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Nom, email, téléphone ou code…"
                   class="md:col-span-2 px-4 py-2 bg-dark-50 text-white border border-dark-200 rounded-lg focus:outline-none focus:border-primary-500">
            <select name="status" class="px-4 py-2 bg-dark-50 text-white border border-dark-200 rounded-lg">
                <option value="">Tous</option>
                <option value="active" @selected(request('status') === 'active')>Actifs</option>
                <option value="inactive" @selected(request('status') === 'inactive')>Désactivés</option>
            </select>
            <button type="submit" class="px-6 py-2 bg-primary-500 text-white rounded-lg hover:bg-primary-600"><i class="fas fa-search mr-2"></i>Filtrer</button>
        </form>
    </div>

    @if($agents->isEmpty())
        <div class="bg-dark-100 rounded-xl shadow-lg p-12 text-center">
            <i class="fas fa-user-tie text-6xl text-gray-600 mb-4"></i>
            <h3 class="text-xl font-semibold text-white mb-2">Aucun commercial</h3>
            <p class="text-gray-400">Créez un commercial pour lui attribuer un code.</p>
        </div>
    @else
        <div class="bg-dark-100 rounded-xl shadow-lg overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-400 border-b border-dark-200">
                        <th class="px-4 py-3">Commercial</th>
                        <th class="px-4 py-3">Code</th>
                        <th class="px-4 py-3">Taux</th>
                        <th class="px-4 py-3 text-right">Ventes</th>
                        <th class="px-4 py-3 text-right">Montant apporté</th>
                        <th class="px-4 py-3 text-right">À payer</th>
                        <th class="px-4 py-3 text-right">Payé</th>
                        <th class="px-4 py-3">Statut</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($agents as $agent)
                        <tr class="border-b border-dark-200 hover:bg-dark-50/50">
                            <td class="px-4 py-3">
                                <a href="{{ route('admin.sales.agents.show', $agent) }}" class="text-white font-medium hover:text-primary-400">{{ $agent->full_name }}</a>
                                @if($agent->source === \App\Models\SalesAgent::SOURCE_SELF)
                                    <span class="ml-1 text-[10px] px-1.5 py-0.5 rounded bg-blue-500/20 text-blue-400" title="Modalités acceptées le {{ $agent->terms_accepted_at?->displayTz()->format('d/m/Y H:i') }}">Inscrit via l'app</span>
                                @endif
                                <div class="text-xs text-gray-500">{{ $agent->phone }} {{ $agent->email }}</div>
                            </td>
                            <td class="px-4 py-3 font-mono text-primary-400">{{ $agent->code }}</td>
                            <td class="px-4 py-3 text-gray-300">
                                {{ $agent->commission_rate !== null ? (float) $agent->commission_rate . ' %' : (float) $defaultRate . ' % (défaut)' }}
                            </td>
                            <td class="px-4 py-3 text-right text-white">{{ $agent->sales_count }}</td>
                            <td class="px-4 py-3 text-right text-gray-300">{{ $fmt($agent->revenue) }}</td>
                            <td class="px-4 py-3 text-right text-yellow-400 font-semibold">{{ $fmt($agent->due_amount) }}</td>
                            <td class="px-4 py-3 text-right text-green-400">{{ $fmt($agent->paid_amount) }}</td>
                            <td class="px-4 py-3">
                                @if($agent->is_active)
                                    <span class="px-3 py-1 bg-green-500/20 text-green-400 text-xs font-semibold rounded-full">Actif</span>
                                @else
                                    <span class="px-3 py-1 bg-gray-500/20 text-gray-400 text-xs font-semibold rounded-full">Désactivé</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $agents->links() }}</div>
    @endif
</div>
@endsection
