@extends('admin.layouts.app')

@section('content')
@php($in = 'w-full px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white focus:border-primary-500 focus:outline-none')
<div class="p-6">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-white">Réglages immobiliers</h1>
        <p class="text-gray-400">Majorations, commissions, délais de blocage, catalogue des équipements et demandes « Confier mon projet ».</p>
    </div>

    @include('admin.real_estate._alerts')

    <form method="POST" action="{{ route('admin.real-estate.settings.update') }}" class="bg-dark-100 rounded-xl border border-dark-200 p-6 mb-6">
        @csrf @method('PUT')
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            @foreach($labels as $key => [$title, $help])
                <div>
                    <label class="block text-sm text-gray-400 mb-1">{{ $title }}</label>
                    <input type="number" step="0.01" min="0" name="{{ $key }}" required value="{{ old($key, $values[$key]) }}" class="{{ $in }}">
                    @if($help)<p class="text-xs text-gray-500 mt-1">{{ $help }}</p>@endif
                </div>
            @endforeach
            <div>
                <label class="block text-sm text-gray-400 mb-1">Cabinet partenaire par défaut</label>
                <select name="immo_default_cabinet_id" required class="{{ $in }}">
                    <option value="">Sélectionner un cabinet</option>
                    @foreach($cabinets as $cabinet)
                        <option value="{{ $cabinet->id }}" @selected($defaultCabinetId === $cabinet->id)>{{ $cabinet->name }}</option>
                    @endforeach
                </select>
                <p class="text-xs text-gray-500 mt-1">Cabinet rattaché par défaut aux nouvelles annonces ; il reçoit la commission de 5 %, y compris pour les maisons proposées par un bailleur.</p>
            </div>
        </div>
        <div class="mt-4 flex justify-end"><button class="px-4 py-2 bg-primary-500 text-white rounded-lg hover:bg-primary-600"><i class="fas fa-save mr-2"></i>Enregistrer</button></div>
    </form>

    <div class="bg-dark-100 rounded-xl border border-dark-200 p-6 mb-6">
        <h2 class="text-lg font-semibold text-white mb-4"><i class="fas fa-check-square mr-2 text-primary-400"></i>Atouts, équipements et services</h2>
        <form method="POST" action="{{ route('admin.real-estate.amenities.store') }}" class="grid grid-cols-1 md:grid-cols-5 gap-3 mb-6">
            @csrf
            <select name="kind" class="{{ $in }}"><option value="highlight">Atout</option><option value="equipment">Équipement</option><option value="service">Service</option></select>
            <input name="name" required placeholder="Nom (FR)" class="{{ $in }}">
            <input name="name_en" placeholder="Name (EN)" class="{{ $in }}">
            <input name="icon" placeholder="Icône Material (ex. wifi)" class="{{ $in }}">
            <button class="px-4 py-2 bg-primary-500 text-white rounded-lg"><i class="fas fa-plus mr-1"></i>Ajouter</button>
        </form>
        <div class="divide-y divide-dark-200">
            @foreach($amenities as $a)
                <form method="POST" action="{{ route('admin.real-estate.amenities.update', $a) }}" class="grid grid-cols-1 md:grid-cols-7 gap-3 py-2 items-center">
                    @csrf @method('PUT')
                    <span class="text-xs text-gray-400">{{ ['highlight' => 'Atout', 'equipment' => 'Équipement', 'service' => 'Service'][$a->kind] }}</span>
                    <input name="name" value="{{ $a->name }}" class="{{ $in }} md:col-span-2">
                    <input name="name_en" value="{{ $a->getTranslation('name', 'en') }}" placeholder="EN" class="{{ $in }}">
                    <input name="icon" value="{{ $a->icon }}" class="{{ $in }}">
                    <label class="flex items-center gap-2 text-sm text-white"><input type="checkbox" name="is_active" value="1" @checked($a->is_active) class="rounded"> Actif</label>
                    <button class="px-3 py-2 text-sm bg-dark-200 text-white rounded-lg"><i class="fas fa-save"></i></button>
                </form>
            @endforeach
        </div>
    </div>

    <div class="bg-dark-100 rounded-xl border border-dark-200 p-6">
        <h2 class="text-lg font-semibold text-white mb-4"><i class="fas fa-drafting-compass mr-2 text-primary-400"></i>Demandes « Confier mon projet »</h2>
        <div class="divide-y divide-dark-200">
            @forelse($projects as $p)
                <div class="py-3 flex items-start justify-between gap-4">
                    <div class="text-sm">
                        <p class="text-white font-medium">{{ $p->name }} · {{ $p->phone }}</p>
                        <p class="text-gray-400">{{ $p->has_land ? 'A déjà un terrain' : 'Cherche un terrain' }} · {{ $p->city }}{{ $p->district ? ', '.$p->district : '' }}
                            @if($p->surface_m2) · {{ $p->surface_m2 }} m² @endif
                            @if($p->budget) · {{ number_format($p->budget, 0, ',', ' ') }} FCFA @endif</p>
                        @if($p->message)<p class="text-gray-300 mt-1">{{ $p->message }}</p>@endif
                        <p class="text-xs text-gray-500 mt-1">{{ $p->created_at->format('d/m/Y H:i') }}</p>
                    </div>
                    <form method="POST" action="{{ route('admin.real-estate.projects.update', $p) }}" class="flex gap-2">
                        @csrf @method('PUT')
                        <select name="status" class="px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white text-sm" onchange="this.form.submit()">
                            @foreach(['new' => 'Nouvelle', 'contacted' => 'Contacté', 'closed' => 'Clôturée'] as $k => $l)
                                <option value="{{ $k }}" @selected($p->status === $k)>{{ $l }}</option>
                            @endforeach
                        </select>
                    </form>
                </div>
            @empty
                <p class="text-gray-400">Aucune demande.</p>
            @endforelse
        </div>
    </div>
</div>
@endsection
