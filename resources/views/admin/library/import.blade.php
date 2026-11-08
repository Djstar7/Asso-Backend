@extends('admin.layouts.app')

@section('content')
@php($in = 'w-full px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white focus:border-primary-500 focus:outline-none')
<div class="p-6">
    @include('admin.library._header', ['title' => 'Importer des livres', 'subtitle' => 'Ajoutez ou mettez à jour des livres depuis un fichier CSV ou Excel.'])

    @if($report = session('import_report'))
        <div class="bg-dark-100 rounded-xl border border-dark-200 p-6 mb-6" role="status">
            <h2 class="text-lg font-semibold text-white mb-3">Résultat de l’import — {{ $report['shop'] }}</h2>
            <div class="flex flex-wrap gap-3 mb-4">
                <span class="px-3 py-1 text-sm rounded-full bg-green-500/20 text-green-300">{{ $report['created'] }} créé(s)</span>
                <span class="px-3 py-1 text-sm rounded-full bg-blue-500/20 text-blue-300">{{ $report['updated'] }} mis à jour</span>
                <span class="px-3 py-1 text-sm rounded-full {{ count($report['errors']) ? 'bg-red-500/20 text-red-300' : 'bg-dark-50 text-gray-400' }}">{{ count($report['errors']) }} ligne(s) en erreur</span>
            </div>
            @if(count($report['errors']))
                <ul class="space-y-1 text-sm text-red-300 max-h-72 overflow-y-auto">
                    @foreach($report['errors'] as $error)
                        <li>Ligne {{ $error['line'] }} : {{ $error['message'] }}</li>
                    @endforeach
                </ul>
            @endif
            @if($report['created'] + $report['updated'] > 0)
                <a href="{{ route('admin.library.books.index') }}" class="inline-block mt-4 text-primary-300 hover:underline">Voir les livres</a>
            @endif
        </div>
    @endif

    <div class="grid grid-cols-1 xl:grid-cols-5 gap-6">
        <form action="{{ route('admin.library.import.store') }}" method="POST" enctype="multipart/form-data"
              class="xl:col-span-3 bg-dark-100 rounded-xl border border-dark-200 p-6 space-y-4">
            @csrf
            <div>
                <label for="import_shop" class="block text-sm text-gray-300 mb-1">Boutique qui vend les livres <span class="text-red-500">*</span></label>
                <select name="shop_id" id="import_shop" required class="{{ $in }}">
                    <option value="">Sélectionnez une boutique</option>
                    @foreach($shops as $shop)
                        <option value="{{ $shop->id }}" @selected(old('shop_id') == $shop->id)>{{ $shop->name }}</option>
                    @endforeach
                </select>
                @error('shop_id')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="import_file" class="block text-sm text-gray-300 mb-1">Fichier (.csv ou .xlsx, 5 Mo max) <span class="text-red-500">*</span></label>
                <input type="file" name="file" id="import_file" accept=".csv,.txt,.xlsx" required class="{{ $in }}">
                @error('file')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
            </div>

            <div class="flex gap-3 flex-wrap">
                <button type="submit" @disabled($shelves->isEmpty()) class="px-5 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg disabled:opacity-50">
                    <i class="fas fa-file-import mr-2"></i>Importer
                </button>
                <a href="{{ route('admin.library.import.template') }}" class="px-5 py-2 bg-dark-50 border border-dark-300 text-white rounded-lg hover:border-primary-500">
                    <i class="fas fa-download mr-2"></i>Télécharger le modèle
                </a>
            </div>
            @if($shelves->isEmpty())
                <p class="text-sm text-amber-300">Aucun rayon : <a href="{{ route('admin.library.shelves.index') }}" class="underline">créez-en un</a> avant d’importer.</p>
            @endif
        </form>

        <aside class="xl:col-span-2 bg-dark-100 rounded-xl border border-dark-200 p-6 text-sm text-gray-300 space-y-3">
            <h2 class="text-white font-semibold">Format du fichier</h2>
            <p>Première ligne : les en-têtes, dans n’importe quel ordre.</p>
            <p class="font-mono text-xs text-gray-400 break-words">{{ implode(', ', $columns) }}</p>
            <ul class="list-disc pl-5 space-y-1">
                <li><b class="text-white">titre, auteur, prix, rayon</b> sont obligatoires.</li>
                <li><b class="text-white">rayon</b> : nom d’un rayon ; <b class="text-white">genre</b> (facultatif) : un genre de ce rayon.</li>
                <li><b class="text-white">langue</b> : fr ou en. <b class="text-white">format</b> : paperback, hardcover, ebook ou audio.</li>
                <li><b class="text-white">poids</b> en kg ; vide = {{ str_replace('.', ',', \App\Imports\BooksImport::DEFAULT_WEIGHT_KG) }} kg.</li>
                <li>Un ISBN déjà présent dans la boutique met le livre à jour au lieu de le dupliquer.</li>
            </ul>
            @if($shelves->isNotEmpty())
                <h3 class="text-white font-semibold pt-2">Rayons disponibles</h3>
                <ul class="space-y-1">
                    @foreach($shelves as $shelf)
                        <li><span class="text-white">{{ $shelf->name }}</span>@if($shelf->subcategories->isNotEmpty())<span class="text-gray-500"> — {{ $shelf->subcategories->pluck('name')->implode(', ') }}</span>@endif</li>
                    @endforeach
                </ul>
            @endif
        </aside>
    </div>
</div>
@endsection
