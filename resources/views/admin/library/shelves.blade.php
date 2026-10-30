@extends('admin.layouts.app')

@section('content')
@php($in = 'w-full px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white focus:border-primary-500 focus:outline-none')
<div class="p-6" x-data="{ create: {{ $errors->any() && ! old('_shelf') ? 'true' : 'false' }}, editing: @js(old('_shelf')) }">
    @include('admin.library._header', ['title' => 'Rayons et genres', 'subtitle' => 'Les rayons classent les livres ; les genres affinent chaque rayon.'])

    <div class="mb-4 flex justify-end">
        <button type="button" @click="create = !create" class="px-4 py-2 bg-dark-200 text-white rounded-lg hover:bg-dark-50">
            <i class="fas fa-plus mr-2"></i>Nouveau rayon
        </button>
    </div>

    <div x-show="create" x-cloak class="bg-dark-100 rounded-xl border border-primary-500/40 p-6 mb-6">
        <form method="POST" action="{{ route('admin.library.shelves.store') }}" class="grid grid-cols-1 md:grid-cols-2 gap-4">
            @csrf
            <div>
                <label for="shelf_name" class="block text-sm text-gray-300 mb-1">Nom (français) <span class="text-red-500">*</span></label>
                <input id="shelf_name" name="name" required value="{{ old('_shelf') ? '' : old('name') }}" placeholder="Romans" class="{{ $in }}">
                @if(! old('_shelf'))@error('name')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror @endif
            </div>
            <div>
                <label for="shelf_name_en" class="block text-sm text-gray-300 mb-1">Nom (anglais) <span class="text-red-500">*</span></label>
                <input id="shelf_name_en" name="name_en" required value="{{ old('_shelf') ? '' : old('name_en') }}" placeholder="Novels" class="{{ $in }}">
                @if(! old('_shelf'))@error('name_en')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror @endif
            </div>
            <div class="md:col-span-2">
                <label for="shelf_description" class="block text-sm text-gray-300 mb-1">Description</label>
                <textarea id="shelf_description" name="description" rows="2" class="{{ $in }}">{{ old('_shelf') ? '' : old('description') }}</textarea>
            </div>
            <div class="md:col-span-2 flex justify-end">
                <button class="px-4 py-2 bg-primary-500 text-white rounded-lg hover:bg-primary-600"><i class="fas fa-save mr-2"></i>Créer le rayon</button>
            </div>
        </form>
    </div>

    <div class="space-y-4">
        @forelse($shelves as $shelf)
            <section class="bg-dark-100 rounded-xl border border-dark-200 p-6">
                <div class="flex items-start justify-between gap-4 flex-wrap">
                    <div>
                        <h2 class="text-lg font-semibold text-white">{{ $shelf->name }} <span class="text-sm font-normal text-gray-500">/ {{ $shelf->name_en }}</span></h2>
                        <p class="text-sm text-gray-400">
                            <a href="{{ route('admin.library.books.index', ['category_id' => $shelf->id]) }}" class="hover:text-primary-300">{{ $shelf->products_count }} livre(s)</a>
                            @if($shelf->description) · {{ $shelf->description }}@endif
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <button type="button" @click="editing = editing === {{ $shelf->id }} ? null : {{ $shelf->id }}" class="px-3 py-1.5 bg-dark-200 text-white rounded-lg hover:bg-dark-50 text-sm">
                            <i class="fas fa-pen mr-1"></i>Modifier
                        </button>
                        @if($shelf->products_count === 0)
                            <form method="POST" action="{{ route('admin.library.shelves.destroy', $shelf) }}" data-confirm="Supprimer le rayon « {{ $shelf->name }} » et ses genres ?">
                                @csrf @method('DELETE')
                                <button class="px-3 py-1.5 bg-red-500/20 text-red-300 rounded-lg hover:bg-red-500/30 text-sm"><i class="fas fa-trash mr-1"></i>Supprimer</button>
                            </form>
                        @endif
                    </div>
                </div>

                <form x-show="editing === {{ $shelf->id }}" x-cloak method="POST" action="{{ route('admin.library.shelves.update', $shelf) }}" class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                    @csrf @method('PUT')
                    <input type="hidden" name="_shelf" value="{{ $shelf->id }}">
                    @php($mine = (int) old('_shelf') === $shelf->id)
                    <div>
                        <label class="block text-sm text-gray-300 mb-1">Nom (français)</label>
                        <input name="name" required value="{{ $mine ? old('name') : $shelf->name }}" class="{{ $in }}">
                        @if($mine)@error('name')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror @endif
                    </div>
                    <div>
                        <label class="block text-sm text-gray-300 mb-1">Nom (anglais)</label>
                        <input name="name_en" required value="{{ $mine ? old('name_en') : $shelf->name_en }}" class="{{ $in }}">
                        @if($mine)@error('name_en')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror @endif
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm text-gray-300 mb-1">Description</label>
                        <textarea name="description" rows="2" class="{{ $in }}">{{ $mine ? old('description') : $shelf->description }}</textarea>
                    </div>
                    <div class="md:col-span-2 flex justify-end">
                        <button class="px-4 py-2 bg-primary-500 text-white rounded-lg hover:bg-primary-600"><i class="fas fa-save mr-2"></i>Enregistrer</button>
                    </div>
                </form>

                <div class="mt-4 border-t border-dark-200 pt-4">
                    <h3 class="text-sm font-semibold text-gray-300 mb-2">Genres</h3>
                    <div class="flex flex-wrap gap-2 mb-3">
                        @forelse($shelf->subcategories as $genre)
                            <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-dark-50 text-sm text-white">
                                {{ $genre->name }}
                                <span class="text-xs text-gray-500">{{ $genre->products_count }}</span>
                                @if($genre->products_count === 0)
                                    <form method="POST" action="{{ route('admin.library.genres.destroy', $genre) }}" data-confirm="Supprimer le genre « {{ $genre->name }} » ?">
                                        @csrf @method('DELETE')
                                        <button class="text-gray-400 hover:text-red-300" title="Supprimer"><i class="fas fa-times"></i><span class="sr-only">Supprimer {{ $genre->name }}</span></button>
                                    </form>
                                @endif
                            </span>
                        @empty
                            <span class="text-sm text-gray-500">Aucun genre.</span>
                        @endforelse
                    </div>
                    <form method="POST" action="{{ route('admin.library.genres.store', $shelf) }}" class="flex flex-wrap gap-2">
                        @csrf
                        <input name="name" required placeholder="Genre (français)" class="{{ $in }} md:w-56" aria-label="Genre en français">
                        <input name="name_en" required placeholder="Genre (anglais)" class="{{ $in }} md:w-56" aria-label="Genre en anglais">
                        <button class="px-3 py-2 bg-dark-200 text-white rounded-lg hover:bg-dark-50 text-sm"><i class="fas fa-plus mr-1"></i>Ajouter</button>
                    </form>
                </div>
            </section>
        @empty
            <div class="bg-dark-100 rounded-xl border border-dark-200 p-10 text-center text-gray-400">
                Aucun rayon. Créez le premier avec « Nouveau rayon ».
            </div>
        @endforelse
    </div>
</div>
@endsection
