@extends('admin.layouts.app')

@section('content')
@php
    $proofLabels = ['land_title' => 'Titre foncier', 'sale_deed' => 'Acte de vente / cession', 'certificate' => 'Attestation de propriété', 'lease_contract' => 'Contrat de bail', 'other' => 'Autre document'];
    $doc = fn ($f) => route('admin.real-estate.verifications.document', [$verification, $f]);
    $isPdf = str_ends_with(strtolower($verification->ownership_proof_path), '.pdf');
@endphp
<div class="p-6" x-data="{ reject: false }">
    <a href="{{ route('admin.real-estate.verifications.index') }}" class="text-sm text-gray-400 hover:text-white"><i class="fas fa-arrow-left mr-1"></i> Bailleurs</a>
    <div class="mt-1 mb-6 flex items-start justify-between flex-wrap gap-3">
        <div>
            <h1 class="text-2xl font-bold text-white">{{ $verification->user?->name }}</h1>
            <p class="text-gray-400">{{ $verification->user?->phone }} · {{ $verification->user?->email }}</p>
            <div class="mt-2">@include('admin.real_estate._status', ['status' => $verification->status])</div>
            @if($verification->reviewer)
                <p class="text-xs text-gray-500 mt-1">Revu par {{ $verification->reviewer->name }} le {{ $verification->reviewed_at?->format('d/m/Y H:i') }}</p>
            @endif
        </div>
        <div class="flex gap-2">
            @if($verification->status !== 'verified')
                <form method="POST" action="{{ route('admin.real-estate.verifications.approve', $verification) }}">@csrf
                    <button class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700"><i class="fas fa-check mr-2"></i>Valider le bailleur</button>
                </form>
            @endif
            <button type="button" @click="reject = !reject" class="px-4 py-2 bg-red-600/80 text-white rounded-lg hover:bg-red-700"><i class="fas fa-times mr-2"></i>Refuser</button>
        </div>
    </div>

    @include('admin.real_estate._alerts')

    <form x-show="reject" x-cloak method="POST" action="{{ route('admin.real-estate.verifications.reject', $verification) }}" class="mb-6 flex gap-2">@csrf
        <input name="reason" required placeholder="Motif (CNI illisible, document ne correspondant pas au bien, vidéo incomplète…)" class="w-full px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white">
        <button class="px-4 py-2 bg-red-600 text-white rounded-lg">Envoyer</button>
    </form>
    @if($verification->rejection_reason)
        <p class="mb-6 text-red-300">Motif du refus : {{ $verification->rejection_reason }}</p>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-dark-100 rounded-xl border border-dark-200 p-5">
            <h2 class="text-white font-semibold mb-3">Pièce d'identité ({{ strtoupper($verification->id_document_type) }})</h2>
            <div class="grid grid-cols-2 gap-3">
                <a href="{{ $doc('id_front') }}" target="_blank"><img src="{{ $doc('id_front') }}" class="rounded-lg w-full" alt="Recto"></a>
                @if($verification->id_back_path)
                    <a href="{{ $doc('id_back') }}" target="_blank"><img src="{{ $doc('id_back') }}" class="rounded-lg w-full" alt="Verso"></a>
                @endif
            </div>
        </div>
        <div class="bg-dark-100 rounded-xl border border-dark-200 p-5">
            <h2 class="text-white font-semibold mb-3">Preuve de propriété : {{ $proofLabels[$verification->ownership_proof_type] ?? $verification->ownership_proof_type }}</h2>
            @if($isPdf)
                <a href="{{ $doc('ownership_proof') }}" target="_blank" class="text-primary-400"><i class="fas fa-file-pdf mr-1"></i>Ouvrir le PDF</a>
            @else
                <a href="{{ $doc('ownership_proof') }}" target="_blank"><img src="{{ $doc('ownership_proof') }}" class="rounded-lg w-full" alt=""></a>
            @endif
        </div>
        <div class="bg-dark-100 rounded-xl border border-dark-200 p-5">
            <h2 class="text-white font-semibold mb-3">Vidéo : le bailleur tient ses documents</h2>
            <video src="{{ $doc('video') }}" controls class="rounded-lg w-full max-h-96"></video>
        </div>
        <div class="bg-dark-100 rounded-xl border border-dark-200 p-5">
            <h2 class="text-white font-semibold mb-3">Annonces du bailleur</h2>
            @forelse($listings as $l)
                <a href="{{ route('admin.real-estate.listings.edit', $l) }}" class="flex justify-between py-2 border-b border-dark-200 text-sm">
                    <span class="text-white">{{ $l->title }}</span>@include('admin.real_estate._status', ['status' => $l->status])
                </a>
            @empty
                <p class="text-gray-400 text-sm">Aucune annonce.</p>
            @endforelse
        </div>
    </div>
</div>
@endsection
