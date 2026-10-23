@extends('admin.layouts.app')

@section('content')
<div class="p-6" x-data="{ showCreate: {{ $errors->any() ? 'true' : 'false' }} }">
    <div class="mb-6 flex items-center justify-between flex-wrap gap-3">
        <div>
            <h1 class="text-2xl font-bold text-white">Cabinets partenaires</h1>
            <p class="text-gray-400">Cabinets fonciers et juridiques référencés par ASSO : ils proposent les terrains, ASSO les publie.</p>
        </div>
        <button type="button" @click="showCreate = !showCreate" class="px-4 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg">
            <i class="fas fa-plus mr-2"></i> Nouveau cabinet
        </button>
    </div>

    @include('admin.real_estate._alerts')

    <div x-show="showCreate" x-cloak class="bg-dark-100 rounded-xl border border-primary-500/40 p-6 mb-6">
        <form method="POST" action="{{ route('admin.real-estate.cabinets.store') }}" enctype="multipart/form-data">
            @csrf
            @include('admin.real_estate.cabinets._fields', ['cabinet' => null])
            <div class="mt-4 flex justify-end"><button class="px-4 py-2 bg-primary-500 text-white rounded-lg hover:bg-primary-600"><i class="fas fa-save mr-2"></i>Créer</button></div>
        </form>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
        @forelse($cabinets as $cab)
            <div class="bg-dark-100 rounded-xl border border-dark-200 p-5 hover:border-primary-500/60 transition-all">
                <a href="{{ route('admin.real-estate.cabinets.edit', $cab) }}" class="block">
                    <div class="flex items-center gap-3">
                        @if($cab->logo_path)
                            <img src="{{ media_url($cab->logo_path) }}" class="w-14 h-14 rounded-full object-cover bg-white" alt="">
                        @else
                            <div class="w-14 h-14 rounded-full bg-dark-50 flex items-center justify-center text-gray-400"><i class="fas fa-landmark"></i></div>
                        @endif
                        <div>
                            <p class="text-white font-semibold">{{ $cab->name }}</p>
                            <p class="text-sm text-gray-400">{{ $cab->headquarters_city }} · {{ $cab->listings_count }} terrain(s)</p>
                        </div>
                    </div>
                    <p class="mt-3 text-xs {{ $cab->is_active ? 'text-green-300' : 'text-gray-500' }}">{{ $cab->is_active ? 'Actif' : 'Inactif' }}</p>
                </a>
                <div class="mt-4 flex justify-end">
                    @include('admin.real_estate.cabinets._delete', ['cabinet' => $cab])
                </div>
            </div>
        @empty
            <p class="text-gray-400">Aucun cabinet pour le moment.</p>
        @endforelse
    </div>
</div>
@endsection
