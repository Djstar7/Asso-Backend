@extends('admin.layouts.app')

@section('title', 'Mes gains')
@section('header', 'Ma rubrique')

@section('content')
@php extract(\App\Support\DivisionPresenter::helpers()) @endphp
<div class="p-6 space-y-6">
    @include('admin.division_space._header', ['title' => auth()->user()->isAdmin() ? 'Commissions' : 'Mes gains'])

    @unless($division)
        <p class="text-sm text-amber-300">Vous n'êtes plus chef d'une rubrique active. Vos commissions restent dues et votre solde reste retirable.</p>
    @endunless

    <div class="grid sm:grid-cols-2 xl:grid-cols-4 gap-4">
        @if($balance !== null)
            <div class="bg-dark-100 rounded-xl p-5"><p class="text-sm text-gray-400">Solde retirable</p><p class="text-2xl font-bold text-white mt-1">{{ $money($balance) }}</p>
                <a href="{{ route('admin.division.withdrawal.index', $query) }}" class="inline-block mt-2 text-sm text-primary-400 hover:text-primary-300">Retirer <i class="fas fa-arrow-right ml-1"></i></a></div>
        @endif
        <div class="bg-dark-100 rounded-xl p-5"><p class="text-sm text-gray-400">En attente</p><p class="text-2xl font-bold text-amber-300 mt-1">{{ $money($earnings['pending']) }}</p><p class="text-xs text-gray-500">Ventes pas encore acquises</p></div>
        <div class="bg-dark-100 rounded-xl p-5"><p class="text-sm text-gray-400">Versé sur le Wallet</p><p class="text-2xl font-bold text-emerald-400 mt-1">{{ $money($earnings['available']) }}</p></div>
        @if($earnings['clawback_due'] > 0)
            <div class="bg-dark-100 rounded-xl p-5"><p class="text-sm text-gray-400">Reste à retenir</p><p class="text-2xl font-bold text-red-300 mt-1">{{ $money($earnings['clawback_due']) }}</p><p class="text-xs text-gray-500">Ventes remboursées après versement, retenu sur les prochaines commissions</p></div>
        @endif
    </div>

    <div class="bg-dark-100 rounded-xl p-4 text-sm text-gray-400 space-y-1">
        <p><i class="fas fa-circle-info mr-1 text-primary-400"></i>Votre pourcentage porte sur la commission ASSO de chaque vente de votre rubrique (prix payé par l'acheteur moins la part du vendeur).</p>
        <p>Il est figé au jour de la vente. La commission passe « Versée » quand la vente est acquise (fin du délai de contrôle du client, sans litige ouvert) ; elle est annulée si la vente est remboursée avant.</p>
    </div>

    @include('admin.division_space._commissions', ['commissions' => $commissions, 'showChief' => auth()->user()->isAdmin()])
</div>
@endsection
