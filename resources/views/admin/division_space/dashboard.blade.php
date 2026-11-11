@extends('admin.layouts.app')

@section('title', 'Ma rubrique')
@section('header', 'Ma rubrique')

@php $money = fn ($v) => number_format((float) $v, 0, ',', ' ') . ' FCFA'; @endphp

@section('content')
<div class="p-6 space-y-6">
    @include('admin.division_space._header', ['title' => 'Tableau de bord'])

    <div class="flex flex-wrap gap-2">
        @foreach($periods as $p)
            <a href="{{ route('admin.division.dashboard', $query + ['period' => $p]) }}"
               class="px-3 py-1 rounded-full text-xs {{ $periodKey === $p ? 'bg-primary-600 text-white' : 'bg-dark-100 text-gray-300' }}">
                {{ ['7d' => '7 jours', '30d' => '30 jours', '90d' => '90 jours', '365d' => '12 mois', 'all' => 'Tout'][$p] }}
            </a>
        @endforeach
    </div>

    @php
        $re = $division->isRealEstate();
        $cards = [
            [$re ? 'Montant des réservations' : 'Chiffre d\'affaires', $money($kpis['revenue']), 'fa-sack-dollar', 'Ventes réglées, hors remboursées'],
            ['Commission ASSO', $money($kpis['asso_commission']), 'fa-percent', 'Base de votre pourcentage'],
            [$re ? 'Réservations payées' : 'Commandes réglées', number_format($kpis['orders'], 0, ',', ' '), 'fa-receipt', null],
            [$re ? 'Réservations débloquées' : 'Articles vendus', number_format($kpis['quantity'], 0, ',', ' '), 'fa-box-open', null],
            [$re ? 'En attente de déblocage' : 'Commandes en cours', number_format($kpis['in_progress'], 0, ',', ' '), 'fa-hourglass-half', $re ? null : 'Payées, pas encore réglées'],
            [$re ? 'Vues des annonces' : 'Vues des produits', number_format($kpis['views'], 0, ',', ' '), 'fa-eye', null],
            [$re ? 'Annonces publiées' : 'Produits en ligne', number_format($kpis['products'], 0, ',', ' '), 'fa-store', null],
        ];
        if (! $re) $cards[] = ['Taux de conversion', number_format($kpis['conversion'], 1, ',', ' ') . ' %', 'fa-arrow-trend-up', 'Articles vendus / vues'];
    @endphp
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
        @foreach($cards as [$label, $value, $icon, $hint])
            <div class="bg-dark-100 rounded-xl p-5">
                <p class="text-sm text-gray-400"><i class="fas {{ $icon }} mr-1"></i>{{ $label }}</p>
                <p class="text-2xl font-bold text-white mt-1">{{ $value }}</p>
                @if($hint)<p class="text-xs text-gray-500 mt-1">{{ $hint }}</p>@endif
            </div>
        @endforeach
    </div>

    <div class="grid lg:grid-cols-3 gap-6">
        <div class="bg-dark-100 rounded-xl p-5 space-y-2">
            <h2 class="text-lg font-semibold text-white"><i class="fas fa-coins mr-2 text-primary-400"></i>{{ auth()->user()->isAdmin() ? 'Commissions de la rubrique' : 'Mes commissions' }}</h2>
            <p class="text-sm text-gray-400">En attente : <span class="text-amber-300 font-semibold">{{ $money($earnings['pending']) }}</span></p>
            <p class="text-sm text-gray-400">Versé : <span class="text-emerald-400 font-semibold">{{ $money($earnings['available']) }}</span></p>
            @if($earnings['clawback_due'] > 0)
                <p class="text-sm text-gray-400">Reste à retenir : <span class="text-red-300 font-semibold">{{ $money($earnings['clawback_due']) }}</span></p>
            @endif
            <p class="text-xs text-gray-500">Une commission est versée sur votre Wallet quand la vente est acquise : fin du délai de contrôle du client, sans litige ouvert.</p>
            <a href="{{ route('admin.division.earnings.index', $query) }}" class="inline-block mt-2 text-sm text-primary-400 hover:text-primary-300">Voir le détail <i class="fas fa-arrow-right ml-1"></i></a>
        </div>

        <div class="lg:col-span-2 bg-dark-100 rounded-xl overflow-x-auto">
            <h2 class="text-lg font-semibold text-white px-5 pt-5 pb-3">Meilleures ventes de la période</h2>
            <table class="w-full text-sm">
                <thead><tr class="text-left text-gray-400 border-b border-dark-200"><th class="px-4 py-2">Produit</th><th class="px-4 py-2 text-right">Quantité</th><th class="px-4 py-2 text-right">Chiffre d'affaires</th></tr></thead>
                <tbody>
                    @forelse($topProducts as $p)
                        <tr class="border-b border-dark-200">
                            <td class="px-4 py-2 text-white">{{ $p->name ?? 'Produit retiré' }}</td>
                            <td class="px-4 py-2 text-right text-gray-300">{{ (int) $p->qty }}</td>
                            <td class="px-4 py-2 text-right text-gray-200">{{ $money($p->revenue) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-4 py-6 text-center text-gray-500">{{ $division->isRealEstate() ? 'Voir l\'onglet Réservations.' : 'Aucune vente réglée sur la période.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
