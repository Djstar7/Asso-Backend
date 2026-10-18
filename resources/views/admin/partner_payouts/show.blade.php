@extends('admin.layouts.app')

@section('title', 'Paiements — ' . $partner->name)
@section('header', 'Paiements du partenaire')

@section('content')
<div class="p-6 space-y-6">
    <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4">
        <div>
            <h1 class="text-3xl font-bold text-gray-100">Paiements — {{ $partner->name }}</h1>
            <p class="text-gray-400 mt-1">
                Le Wallet du partenaire reçoit ses {{ $partner->service_type === \App\Models\DelivererCompany::SERVICE_MARITIME ? 'frets maritimes' : 'courses' }} à chaque règlement.
                Versez-lui ce solde comme pour le retrait ASSO : Mobile Money vers un opérateur du pays, ou virement sur son IBAN.
            </p>
        </div>
        <a href="{{ $backUrl }}" class="px-3 py-2 text-sm rounded-lg bg-dark-200 hover:bg-dark-300 text-gray-200">
            <i class="fas fa-arrow-left mr-1"></i>{{ $partner->name }}
        </a>
    </div>

    @include('admin.withdrawals.panel', ['historyTitle' => 'Derniers paiements'])
</div>
@endsection
