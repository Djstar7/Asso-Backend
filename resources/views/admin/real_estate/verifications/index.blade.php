@extends('admin.layouts.app')

@section('content')
<div class="p-6">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-white">Bailleurs à vérifier</h1>
        <p class="text-gray-400">CNI, preuve de propriété et vidéo du bailleur tenant ses documents. Ses annonces ne sont publiées qu'une fois le profil vérifié.</p>
    </div>

    @include('admin.real_estate._alerts')

    <div class="flex gap-2 mb-4">
        @foreach(['pending' => 'En attente', 'verified' => 'Vérifiés', 'rejected' => 'Refusés', 'all' => 'Tous'] as $key => $label)
            <a href="{{ route('admin.real-estate.verifications.index', ['status' => $key]) }}"
               class="px-3 py-1.5 text-sm rounded-lg {{ $status === $key ? 'bg-primary-500 text-white' : 'bg-dark-200 text-gray-300' }}">
                {{ $label }} @if($key !== 'all')({{ $counts[$key] ?? 0 }})@endif
            </a>
        @endforeach
    </div>

    <div class="bg-dark-100 rounded-xl border border-dark-200 divide-y divide-dark-200">
        @forelse($verifications as $v)
            <a href="{{ route('admin.real-estate.verifications.show', $v) }}" class="flex items-center justify-between p-4 hover:bg-dark-50">
                <div>
                    <p class="text-white font-medium">{{ $v->user?->name }}</p>
                    <p class="text-sm text-gray-400">{{ $v->user?->phone }} · envoyé le {{ $v->updated_at->format('d/m/Y H:i') }}</p>
                </div>
                @include('admin.real_estate._status', ['status' => $v->status])
            </a>
        @empty
            <p class="p-6 text-gray-400">Aucune demande.</p>
        @endforelse
    </div>
    <div class="mt-4">{{ $verifications->links() }}</div>
</div>
@endsection
