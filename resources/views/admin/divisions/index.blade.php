@extends('admin.layouts.app')

@section('title', 'Rubriques')
@section('header', 'Rubriques')

@php $money = fn ($v) => number_format((float) $v, 0, ',', ' ') . ' FCFA'; @endphp

@section('content')
<div class="p-6 space-y-6">
    <div class="flex flex-col md:flex-row md:justify-between md:items-center gap-4">
        <div>
            <h1 class="text-2xl font-bold text-white">Rubriques et chefs de rubrique</h1>
            <p class="text-gray-400 mt-1">Chaque chef ne voit que les ventes, produits et trafic de sa rubrique et touche un pourcentage de la commission ASSO de ces ventes.</p>
        </div>
        <a href="{{ route('admin.divisions.create') }}" class="px-4 py-2 bg-primary-500 text-white rounded-lg hover:bg-primary-600 transition-all">
            <i class="fas fa-plus mr-2"></i>Nouvelle rubrique
        </a>
    </div>

    @if($divisions->isEmpty())
        <div class="bg-dark-100 rounded-xl shadow-lg p-12 text-center">
            <i class="fas fa-sitemap text-6xl text-gray-600 mb-4"></i>
            <h3 class="text-xl font-semibold text-white mb-2">Aucune rubrique</h3>
            <p class="text-gray-400">Créez une rubrique (ex. Électronique), rattachez-lui ses catégories, puis nommez son chef.</p>
        </div>
    @else
        <div class="bg-dark-100 rounded-xl shadow-lg overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-400 border-b border-dark-200">
                        <th class="px-4 py-3">Rubrique</th>
                        <th class="px-4 py-3">Périmètre</th>
                        <th class="px-4 py-3">Chef en poste</th>
                        <th class="px-4 py-3 text-right">Taux</th>
                        <th class="px-4 py-3 text-right">En attente</th>
                        <th class="px-4 py-3 text-right">Versé</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($divisions as $division)
                        @php $t = ($totals[$division->id] ?? collect())->keyBy('status'); $head = $division->currentHead; @endphp
                        <tr class="border-b border-dark-200 hover:bg-dark-50/50">
                            <td class="px-4 py-3">
                                <div class="text-white font-medium">{{ $division->name }}</div>
                                @unless($division->is_active)<span class="text-xs text-amber-400">Désactivée</span>@endunless
                            </td>
                            <td class="px-4 py-3 text-gray-300">
                                @if($division->isRealEstate())
                                    <span class="px-2 py-0.5 bg-dark-50 border border-dark-200 text-xs rounded">Module Immobilier</span>
                                @else
                                    @foreach($division->categories as $category)
                                        <span class="inline-block px-2 py-0.5 mb-1 bg-dark-50 border border-dark-200 text-xs rounded">{{ $category->name }}</span>
                                    @endforeach
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @if($head)
                                    <div class="text-white">{{ $head->user?->name }}</div>
                                    <div class="text-xs text-gray-500">{{ $head->user?->email }}</div>
                                @else
                                    <span class="text-amber-400">Aucun chef</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right text-white">{{ $head ? rtrim(rtrim(number_format((float) $head->commission_rate, 2, ',', ''), '0'), ',') . ' %' : '—' }}</td>
                            <td class="px-4 py-3 text-right text-gray-300">{{ $money($t['pending']->total ?? 0) }}</td>
                            <td class="px-4 py-3 text-right text-emerald-400">{{ $money($t['available']->total ?? 0) }}</td>
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('admin.divisions.show', $division) }}" class="px-3 py-1.5 bg-primary-600 text-white text-sm rounded-lg hover:bg-primary-700"><i class="fas fa-eye mr-1"></i>Gérer</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
