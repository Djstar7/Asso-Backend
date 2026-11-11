@extends('admin.layouts.app')

@section('title', $division->exists ? 'Modifier la rubrique' : 'Nouvelle rubrique')
@section('header', 'Rubriques')

@php $input = 'w-full px-4 py-2 bg-dark-50 text-white border border-dark-200 rounded-lg focus:outline-none focus:border-primary-500'; @endphp

@section('content')
<div class="p-6 max-w-3xl">
    <a href="{{ $division->exists ? route('admin.divisions.show', $division) : route('admin.divisions.index') }}" class="text-sm text-gray-400 hover:text-white"><i class="fas fa-arrow-left mr-1"></i>Retour</a>
    <h1 class="text-2xl font-bold text-white mt-2 mb-6">{{ $division->exists ? 'Modifier la rubrique ' . $division->name : 'Nouvelle rubrique' }}</h1>

    <form method="POST" action="{{ $division->exists ? route('admin.divisions.update', $division) : route('admin.divisions.store') }}"
          class="bg-dark-100 rounded-xl shadow-lg p-6 space-y-5" x-data="{ type: @js(old('type', $division->type)) }">
        @csrf
        @if($division->exists) @method('PUT') @endif

        <div class="grid md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm text-gray-300 mb-1">Nom <span class="text-red-500">*</span></label>
                <input type="text" name="name" value="{{ old('name', $division->name) }}" required maxlength="255" class="{{ $input }}" placeholder="Électronique">
                @error('name')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-1">Nom en anglais</label>
                <input type="text" name="name_en" value="{{ old('name_en', $division->name_en) }}" maxlength="255" class="{{ $input }}" placeholder="Electronics">
            </div>
        </div>

        <div>
            <label class="block text-sm text-gray-300 mb-1">Périmètre</label>
            <select name="type" x-model="type" class="{{ $input }}">
                @foreach(\App\Models\Division::TYPES as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            @error('type')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
        </div>

        <div x-show="type === 'category'" x-cloak>
            <label class="block text-sm text-gray-300 mb-2">Catégories de la rubrique</label>
            <p class="text-xs text-gray-500 mb-3">Une catégorie n'appartient qu'à une rubrique : celles déjà prises ailleurs ne sont pas proposées. Les ventes sont rattachées à la catégorie du produit au moment de la commande.</p>
            @php $selected = collect(old('categories', $division->categories?->pluck('id')->all() ?? []))->map(fn ($id) => (int) $id); @endphp
            <div class="grid sm:grid-cols-2 gap-2">
                @foreach($categories as $category)
                    <label class="flex items-center gap-2 px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-gray-200">
                        <input type="checkbox" name="categories[]" value="{{ $category->id }}" @checked($selected->contains($category->id)) class="rounded">
                        {{ $category->name }}
                    </label>
                @endforeach
            </div>
            @error('categories')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
            @error('categories.*')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
        </div>

        <div x-show="type === 'real_estate'" x-cloak class="text-sm text-gray-400">
            Le chef verra les réservations et annonces du module Immobilier, et touchera un pourcentage de la commission ASSO des réservations.
        </div>

        <label class="flex items-center gap-2 text-gray-200">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $division->is_active ?? true)) class="rounded">
            Rubrique active (désactivée : plus aucune commission sur les nouvelles ventes, l'espace du chef est fermé)
        </label>

        <button type="submit" class="px-6 py-2 bg-primary-500 text-white rounded-lg hover:bg-primary-600">Enregistrer</button>
    </form>
</div>
@endsection
