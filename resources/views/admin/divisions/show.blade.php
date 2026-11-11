@extends('admin.layouts.app')

@section('title', 'Rubrique ' . $division->name)
@section('header', 'Rubriques')

@php
    $money = fn ($v) => number_format((float) $v, 0, ',', ' ') . ' FCFA';
    $rate = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ',') . ' %';
    $input = 'w-full px-4 py-2 bg-dark-50 text-white border border-dark-200 rounded-lg focus:outline-none focus:border-primary-500';
    $head = $division->currentHead;
@endphp

@section('content')
<div class="p-6 space-y-6">
    <div class="flex flex-col md:flex-row md:justify-between md:items-start gap-4">
        <div>
            <a href="{{ route('admin.divisions.index') }}" class="text-sm text-gray-400 hover:text-white"><i class="fas fa-arrow-left mr-1"></i>Rubriques</a>
            <h1 class="text-2xl font-bold text-white mt-2">{{ $division->name }}</h1>
            <p class="text-gray-400 mt-1">
                @if($division->isRealEstate())
                    Module Immobilier
                @else
                    {{ $division->categories->pluck('name')->implode(', ') ?: 'Aucune catégorie' }}
                @endif
                @unless($division->is_active) · <span class="text-amber-400">désactivée</span>@endunless
            </p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('admin.division.dashboard', ['division' => $division->id]) }}" class="px-4 py-2 bg-dark-200 text-gray-200 rounded-lg hover:bg-dark-300"><i class="fas fa-eye mr-1"></i>Voir son espace</a>
            <a href="{{ route('admin.divisions.edit', $division) }}" class="px-4 py-2 bg-primary-500 text-white rounded-lg hover:bg-primary-600"><i class="fas fa-edit mr-1"></i>Modifier</a>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        @foreach(['pending' => ['En attente', 'text-amber-300'], 'available' => ['Versé au chef', 'text-emerald-400'], 'cancelled' => ['Annulé', 'text-gray-400']] as $status => [$label, $tone])
            <div class="bg-dark-100 rounded-xl p-5">
                <p class="text-sm text-gray-400">{{ $label }}</p>
                <p class="text-2xl font-bold {{ $tone }} mt-1">{{ $money($summary[$status]->total ?? 0) }}</p>
                <p class="text-xs text-gray-500">{{ (int) ($summary[$status]->n ?? 0) }} vente(s)</p>
            </div>
        @endforeach
    </div>

    <div class="grid lg:grid-cols-2 gap-6">
        {{-- Chef en poste --}}
        <div class="bg-dark-100 rounded-xl shadow-lg p-6 space-y-4">
            <h2 class="text-lg font-semibold text-white"><i class="fas fa-user-tie mr-2 text-primary-400"></i>Chef en poste</h2>
            @if($head)
                <div>
                    <p class="text-white font-medium">{{ $head->user?->name }}</p>
                    <p class="text-sm text-gray-400">{{ $head->user?->email }} · depuis le {{ $head->started_at->displayTz()->format('d/m/Y H:i') }}</p>
                    <p class="text-sm text-gray-300 mt-1">{{ $rate($head->commission_rate) }} de la commission ASSO des ventes de la rubrique</p>
                </div>
                <form method="POST" action="{{ route('admin.divisions.heads.rate', $division) }}" class="flex gap-2 items-end"
                      data-confirm="Le nouveau taux s'appliquera aux ventes à partir de maintenant. Les ventes passées gardent l'ancien. Confirmer ?">
                    @csrf
                    <div class="flex-1">
                        <label class="block text-sm text-gray-300 mb-1">Nouveau pourcentage</label>
                        <input type="number" name="commission_rate" min="0" max="100" step="0.01" required class="{{ $input }}" value="{{ old('commission_rate') }}">
                    </div>
                    <button class="px-4 py-2 bg-primary-600 text-white rounded-lg hover:bg-primary-700">Changer</button>
                </form>
                @error('commission_rate')<p class="text-xs text-red-400">{{ $message }}</p>@enderror
                <div class="flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('admin.divisions.heads.resend', [$division, $head]) }}" data-confirm="Générer un nouveau mot de passe et l'envoyer à {{ $head->user?->email }} ?">
                        @csrf
                        <button class="px-3 py-1.5 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700"><i class="fas fa-paper-plane mr-1"></i>Renvoyer les accès</button>
                    </form>
                    <form method="POST" action="{{ route('admin.divisions.heads.end', $division) }}" data-confirm="Mettre fin au mandat de {{ $head->user?->name }} ? Il perd l'accès à la rubrique mais garde ses commissions et son retrait.">
                        @csrf
                        <button class="px-3 py-1.5 bg-red-600 text-white text-sm rounded-lg hover:bg-red-700"><i class="fas fa-user-slash mr-1"></i>Mettre fin au mandat</button>
                    </form>
                </div>
            @else
                <p class="text-amber-300 text-sm">Aucun chef : les ventes de la rubrique ne génèrent pas de commission.</p>
            @endif
        </div>

        {{-- Nommer --}}
        <div class="bg-dark-100 rounded-xl shadow-lg p-6" x-data="{ mode: @js(old('mode', $chiefs->isEmpty() ? 'new' : 'new')) }">
            <h2 class="text-lg font-semibold text-white mb-1"><i class="fas fa-user-plus mr-2 text-primary-400"></i>{{ $head ? 'Remplacer le chef' : 'Nommer le chef' }}</h2>
            <p class="text-xs text-gray-500 mb-4">Le pourcentage porte sur la commission ASSO de chaque vente (prix acheteur moins part vendeur), jamais sur le prix payé. Il est figé à la vente et versé sur le Wallet du chef quand la vente est acquise.</p>
            <form method="POST" action="{{ route('admin.divisions.heads.store', $division) }}" class="space-y-3"
                  @if($head) data-confirm="{{ $head->user?->name }} ne sera plus chef de cette rubrique. Confirmer ?" @endif>
                @csrf
                <div class="flex gap-4 text-sm text-gray-200">
                    <label><input type="radio" name="mode" value="new" x-model="mode"> Nouveau compte</label>
                    @if($chiefs->isNotEmpty())<label><input type="radio" name="mode" value="existing" x-model="mode"> Chef existant</label>@endif
                </div>
                <div x-show="mode === 'new'" class="grid md:grid-cols-2 gap-3">
                    <input type="text" name="first_name" value="{{ old('first_name') }}" placeholder="Prénom" class="{{ $input }}">
                    <input type="text" name="last_name" value="{{ old('last_name') }}" placeholder="Nom" class="{{ $input }}">
                    <input type="email" name="email" value="{{ old('email') }}" placeholder="E-mail (reçoit ses identifiants)" class="{{ $input }} md:col-span-2">
                </div>
                <div x-show="mode === 'existing'" x-cloak>
                    <select name="user_id" class="{{ $input }}">
                        <option value="">Choisir un chef</option>
                        @foreach($chiefs as $chief)
                            <option value="{{ $chief->id }}" @selected(old('user_id') == $chief->id)>{{ $chief->first_name }} {{ $chief->last_name }} ({{ $chief->email }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm text-gray-300 mb-1">Pourcentage de la commission ASSO</label>
                    <input type="number" name="commission_rate" min="0" max="100" step="0.01" required value="{{ old('commission_rate') }}" class="{{ $input }}" placeholder="20">
                </div>
                @foreach(['mode', 'user_id', 'first_name', 'last_name', 'email', 'commission_rate'] as $field)
                    @error($field)<p class="text-xs text-red-400">{{ $message }}</p>@enderror
                @endforeach
                <button class="px-6 py-2 bg-primary-500 text-white rounded-lg hover:bg-primary-600">{{ $head ? 'Remplacer' : 'Nommer' }}</button>
            </form>
        </div>
    </div>

    {{-- Historique des mandats --}}
    <div class="bg-dark-100 rounded-xl shadow-lg overflow-x-auto">
        <h2 class="text-lg font-semibold text-white px-6 pt-5 pb-3">Historique des mandats</h2>
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-400 border-b border-dark-200">
                <th class="px-4 py-2">Chef</th><th class="px-4 py-2 text-right">Taux</th><th class="px-4 py-2">Début</th><th class="px-4 py-2">Fin</th><th class="px-4 py-2">Motif</th><th class="px-4 py-2">Par</th>
            </tr></thead>
            <tbody>
                @forelse($division->heads as $h)
                    <tr class="border-b border-dark-200">
                        <td class="px-4 py-2 text-white">{{ $h->user?->name }}</td>
                        <td class="px-4 py-2 text-right text-gray-200">{{ $rate($h->commission_rate) }}</td>
                        <td class="px-4 py-2 text-gray-300">{{ $h->started_at->displayTz()->format('d/m/Y H:i') }}</td>
                        <td class="px-4 py-2 text-gray-300">{{ $h->ended_at?->displayTz()->format('d/m/Y H:i') ?? 'En poste' }}</td>
                        <td class="px-4 py-2 text-gray-400">{{ $h->end_reason ?? '—' }}</td>
                        <td class="px-4 py-2 text-gray-400">{{ $h->creator?->name ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-gray-500">Aucun mandat.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @include('admin.division_space._commissions', ['commissions' => $commissions, 'showChief' => true])
</div>
@endsection
