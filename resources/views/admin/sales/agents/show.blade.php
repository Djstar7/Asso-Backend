@extends('admin.layouts.app')

@section('title', $agent->full_name)
@section('header', 'Commerciaux')

@section('content')
@php $fmt = fn ($v) => number_format((float) $v, 0, ',', ' ') . ' F'; @endphp
<div class="p-6 space-y-6">
    <div class="flex flex-col md:flex-row md:justify-between md:items-start gap-4">
        <div>
            <a href="{{ route('admin.sales.agents.index') }}" class="text-sm text-gray-400 hover:text-white"><i class="fas fa-arrow-left mr-1"></i>Commerciaux</a>
            <h1 class="text-2xl font-bold text-white mt-2">{{ $agent->full_name }}
                @if($agent->is_active)
                    <span class="ml-2 px-3 py-1 bg-green-500/20 text-green-400 text-xs font-semibold rounded-full align-middle">Actif</span>
                @else
                    <span class="ml-2 px-3 py-1 bg-gray-500/20 text-gray-400 text-xs font-semibold rounded-full align-middle">Désactivé</span>
                @endif
            </h1>
            <p class="text-gray-400 mt-1">
                Code <span class="font-mono text-primary-400 text-lg">{{ $agent->code }}</span>
                · Taux {{ $agent->commission_rate !== null ? (float) $agent->commission_rate . ' %' : 'par défaut' }}
                @if($agent->phone) · {{ $agent->phone }} @endif
                @if($agent->email) · {{ $agent->email }} @endif
                @if($agent->user) · compte lié : {{ $agent->user->email }} @endif
            </p>
            @if($agent->notes)<p class="text-sm text-gray-500 mt-2 whitespace-pre-line">{{ $agent->notes }}</p>@endif
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('admin.sales.agents.edit', $agent) }}" class="px-4 py-2 bg-dark-200 text-white rounded-lg hover:bg-dark-300"><i class="fas fa-edit mr-2"></i>Modifier</a>
            <form method="POST" action="{{ route('admin.sales.agents.regenerate-code', $agent) }}" data-confirm="Générer un nouveau code ? L'ancien ne sera plus accepté.">
                @csrf
                <button class="px-4 py-2 bg-dark-200 text-white rounded-lg hover:bg-dark-300"><i class="fas fa-sync mr-2"></i>Nouveau code</button>
            </form>
            <form method="POST" action="{{ route('admin.sales.agents.toggle-active', $agent) }}">
                @csrf
                <button class="px-4 py-2 {{ $agent->is_active ? 'bg-red-600 hover:bg-red-700' : 'bg-green-600 hover:bg-green-700' }} text-white rounded-lg">
                    <i class="fas {{ $agent->is_active ? 'fa-ban' : 'fa-check' }} mr-2"></i>{{ $agent->is_active ? 'Désactiver' : 'Activer' }}
                </button>
            </form>
            <a href="{{ route('admin.sales.commissions.export', ['agent_id' => $agent->id]) }}" class="px-4 py-2 bg-dark-200 text-white rounded-lg hover:bg-dark-300"><i class="fas fa-file-csv mr-2"></i>Export CSV</a>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="bg-dark-100 rounded-xl shadow-lg p-6"><p class="text-gray-400 text-sm">Souscriptions apportées</p><p class="text-2xl font-bold text-white mt-1">{{ $stats['sales'] }}</p></div>
        <div class="bg-dark-100 rounded-xl shadow-lg p-6"><p class="text-gray-400 text-sm">Montant payé par les vendeurs</p><p class="text-2xl font-bold text-white mt-1">{{ $fmt($stats['revenue']) }}</p></div>
        <div class="bg-dark-100 rounded-xl shadow-lg p-6"><p class="text-gray-400 text-sm">Commission à payer</p><p class="text-2xl font-bold text-yellow-500 mt-1">{{ $fmt($stats['due']) }}</p></div>
        <div class="bg-dark-100 rounded-xl shadow-lg p-6"><p class="text-gray-400 text-sm">Commission payée</p><p class="text-2xl font-bold text-green-500 mt-1">{{ $fmt($stats['paid']) }}</p></div>
    </div>

    <div>
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-lg font-semibold text-white">Historique des ventes</h2>
            <form method="GET" class="flex gap-2">
                <select name="status" onchange="this.form.submit()" class="px-3 py-2 bg-dark-50 text-white border border-dark-200 rounded-lg text-sm">
                    <option value="">Tous les statuts</option>
                    @foreach(\App\Models\SalesCommission::STATUS_LABELS as $value => $label)
                        <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </form>
        </div>
        @include('admin.sales.partials.commissions-table', ['commissions' => $commissions, 'showAgent' => false])
    </div>

    @if($unpaidAttempts->isNotEmpty())
        <div>
            <h2 class="text-lg font-semibold text-white mb-1">Souscriptions non payées avec ce code</h2>
            <p class="text-sm text-gray-400 mb-3">Paiement en attente ou échoué : aucune commission tant que le forfait n'est pas payé.</p>
            <div class="bg-dark-100 rounded-xl shadow-lg overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-gray-400 border-b border-dark-200">
                        <th class="px-4 py-3">Date</th><th class="px-4 py-3">Vendeur</th><th class="px-4 py-3">Forfait</th>
                        <th class="px-4 py-3 text-right">Montant</th><th class="px-4 py-3">Mode</th><th class="px-4 py-3">Statut</th>
                    </tr></thead>
                    <tbody>
                        @foreach($unpaidAttempts as $s)
                            <tr class="border-b border-dark-200">
                                <td class="px-4 py-3 text-gray-300">{{ $s->created_at->displayTz()->format('d/m/Y H:i') }}</td>
                                <td class="px-4 py-3 text-gray-200">{{ $s->user?->name }}</td>
                                <td class="px-4 py-3 text-gray-200">{{ $s->package?->name ?? ($s->metadata['package_name'] ?? '—') }}</td>
                                <td class="px-4 py-3 text-right text-white">{{ $fmt($s->amount_xaf) }}</td>
                                <td class="px-4 py-3 text-gray-400">{{ $s->payment_method }}</td>
                                <td class="px-4 py-3">
                                    <span class="px-3 py-1 {{ $s->status === 'pending' ? 'bg-yellow-500/20 text-yellow-400' : 'bg-red-500/20 text-red-400' }} text-xs font-semibold rounded-full">
                                        {{ $s->status === 'pending' ? 'Paiement en attente' : 'Échoué' }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
@endsection
