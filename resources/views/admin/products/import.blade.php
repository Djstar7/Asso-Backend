@extends('admin.layouts.app')

@section('content')
<div class="p-6">
    <div class="mb-6 flex items-center gap-3">
        <a href="{{ route('admin.products.index', ['library' => 1]) }}" class="text-gray-400 hover:text-primary-600 transition-colors">
            <i class="fas fa-arrow-left"></i>
        </a>
        <div>
            <h1 class="text-2xl font-bold text-white">Importer des livres</h1>
            <p class="text-gray-400">Ajoutez ou mettez à jour des livres depuis un fichier CSV ou Excel</p>
        </div>
    </div>

    @if(session('import_report'))
        @php $report = session('import_report'); @endphp
        <div class="bg-dark-100 rounded-xl shadow-lg p-6 mb-6 border border-dark-200">
            <h3 class="text-lg font-bold text-white mb-3">Résultat de l'import</h3>
            <div class="flex flex-wrap gap-3 mb-4">
                <span class="px-3 py-1 text-sm rounded-full bg-green-500/20 text-green-300 border border-green-500/50">{{ $report['created'] }} créé(s)</span>
                <span class="px-3 py-1 text-sm rounded-full bg-blue-500/20 text-blue-300 border border-blue-500/50">{{ $report['updated'] }} mis à jour</span>
                <span class="px-3 py-1 text-sm rounded-full bg-red-500/20 text-red-300 border border-red-500/50">{{ count($report['errors']) }} erreur(s)</span>
            </div>
            @if(count($report['errors']))
                <ul class="space-y-1 text-sm text-red-300">
                    @foreach($report['errors'] as $error)
                        <li>Ligne {{ $error['line'] }} : {{ $error['message'] }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    <form action="{{ route('admin.books.import.store') }}" method="POST" enctype="multipart/form-data"
          class="bg-dark-100 rounded-xl shadow-lg p-6 border border-dark-200 space-y-4 max-w-2xl">
        @csrf

        <div>
            <label class="block text-sm font-medium text-white mb-2">Boutique <span class="text-red-500">*</span></label>
            <select name="shop_id" required class="w-full px-4 py-2 bg-dark-50 border border-dark-300 text-white rounded-lg">
                <option value="">Sélectionnez une boutique</option>
                @foreach($shops as $shop)
                    <option value="{{ $shop->id }}" @selected(old('shop_id') == $shop->id)>{{ $shop->name }}</option>
                @endforeach
            </select>
            @error('shop_id')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-white mb-2">Fichier (.csv ou .xlsx) <span class="text-red-500">*</span></label>
            <input type="file" name="file" accept=".csv,.txt,.xlsx" required
                   class="w-full px-4 py-2 bg-dark-50 border border-dark-300 text-white rounded-lg">
            @error('file')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
            <p class="mt-2 text-xs text-gray-400">
                Colonnes : titre, auteur, prix, categorie (nom d'une catégorie bibliothèque), isbn, editeur, langue (fr/en),
                format (paperback, hardcover, ebook, audio), pages, annee, stock, poids, description.
                Un livre dont l'ISBN existe déjà dans la boutique est mis à jour.
            </p>
        </div>

        <div class="flex gap-3">
            <button type="submit" class="px-5 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg transition-all">
                <i class="fas fa-file-import mr-2"></i>Importer
            </button>
            <a href="{{ route('admin.books.import.template') }}" class="px-5 py-2 bg-dark-50 border border-dark-300 text-white rounded-lg hover:border-primary-500 transition-all">
                <i class="fas fa-download mr-2"></i>Télécharger le modèle
            </a>
        </div>
    </form>
</div>
@endsection
