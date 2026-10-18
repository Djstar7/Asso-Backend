@extends('admin.layouts.app')

@section('title', 'Retrait ASSO')
@section('header', 'Retrait ASSO')

@section('content')
<div class="p-6 space-y-6">
    <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4">
        <div>
            <h1 class="text-3xl font-bold text-gray-100">Retrait ASSO</h1>
            <p class="text-gray-400 mt-1">
                Fonds du Wallet du compte plateforme
                <span class="text-gray-300">{{ $account->email }}</span>, qui reçoit les commissions ASSO.
                Pas de remittance : les fonds de chaque pays se retirent vers un opérateur de ce pays, ou par virement sur l'IBAN.
            </p>
        </div>
        <a href="{{ route('admin.revenue.index') }}" class="px-3 py-2 text-sm rounded-lg bg-dark-200 hover:bg-dark-300 text-gray-200">
            <i class="fas fa-arrow-left mr-1"></i>Revenus ASSO
        </a>
    </div>

    @include('admin.withdrawals.panel', ['historyTitle' => 'Derniers retraits ASSO'])
</div>
@endsection
