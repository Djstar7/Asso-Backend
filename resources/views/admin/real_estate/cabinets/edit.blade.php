@extends('admin.layouts.app')

@section('content')
<div class="p-6">
    <div class="mb-6 flex items-center justify-between flex-wrap gap-3">
        <div>
            <a href="{{ route('admin.real-estate.cabinets.index') }}" class="text-sm text-gray-400 hover:text-white"><i class="fas fa-arrow-left mr-1"></i> Cabinets</a>
            <h1 class="text-2xl font-bold text-white mt-1">{{ $cabinet->name }}</h1>
            <p class="text-gray-400">Compte : {{ $cabinet->user?->email ?? '—' }}</p>
        </div>
        <div class="flex items-center gap-3 flex-wrap">
            <a href="{{ route('admin.real-estate.listings.create', ['cabinet_id' => $cabinet->id]) }}" class="px-4 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg">
                <i class="fas fa-plus mr-2"></i> Ajouter un terrain
            </a>
            @include('admin.real_estate.cabinets._delete')
        </div>
    </div>

    @include('admin.real_estate._alerts')

    <form method="POST" action="{{ route('admin.real-estate.cabinets.update', $cabinet) }}" enctype="multipart/form-data" class="bg-dark-100 rounded-xl border border-dark-200 p-6 mb-6">
        @csrf @method('PUT')
        @include('admin.real_estate.cabinets._fields', ['cabinet' => $cabinet])
        <div class="mt-4 flex items-center justify-between flex-wrap gap-3">
            <div class="flex gap-6">
                <label class="flex items-center gap-2 text-white"><input type="checkbox" name="is_active" value="1" @checked($cabinet->is_active) class="rounded"> Actif</label>
                <label class="flex items-center gap-2 text-white"><input type="checkbox" name="is_verified" value="1" @checked($cabinet->is_verified) class="rounded"> Badge vérifié</label>
            </div>
            <button class="px-4 py-2 bg-primary-500 text-white rounded-lg hover:bg-primary-600"><i class="fas fa-save mr-2"></i>Enregistrer</button>
        </div>
    </form>

    <div class="bg-dark-100 rounded-xl border border-dark-200 p-6">
        <h2 class="text-lg font-semibold text-white mb-4"><i class="fas fa-mountain mr-2 text-primary-400"></i>Terrains du cabinet</h2>
        <div class="divide-y divide-dark-200">
            @forelse($cabinet->listings as $l)
                <a href="{{ route('admin.real-estate.listings.edit', $l) }}" class="flex items-center justify-between py-3 hover:bg-dark-50 px-2 rounded">
                    <div>
                        <p class="text-white">{{ $l->title }} <span class="text-xs text-gray-500">{{ $l->reference }}</span></p>
                        <p class="text-sm text-gray-400">{{ $l->surfaceRangeLabel() }} · {{ $l->land_titled ? 'Titré' : 'Non titré' }}</p>
                    </div>
                    @include('admin.real_estate._status', ['status' => $l->status])
                </a>
            @empty
                <p class="text-gray-400">Aucun terrain. Utilisez « Ajouter un terrain » ou la saisie rapide depuis la page Annonces.</p>
            @endforelse
        </div>
    </div>
</div>
@endsection
