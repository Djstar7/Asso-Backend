@extends('admin.layouts.app')

@section('title', 'Documents')

@section('content')
<div class="p-6 space-y-6">
    <!-- Header -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-gray-100">Documents</h1>
            <p class="text-gray-400 mt-1">Gestion des documents de l'entreprise</p>
        </div>
        <div class="flex gap-3">
            <a href="{{ route('admin.documents.categories') }}" 
               class="px-4 py-2 bg-dark-100 border border-dark-200 text-white rounded-lg hover:bg-dark-200 transition-colors">
                <i class="fas fa-folder mr-2"></i>Catégories
            </a>
            <a href="{{ route('admin.documents.create') }}" 
               class="px-6 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:from-primary-600 hover:to-primary-700 transition-all shadow-lg">
                <i class="fas fa-upload mr-2"></i>Uploader un document
            </a>
        </div>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
        <div class="bg-gradient-to-br from-blue-600 to-blue-700 rounded-lg p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-blue-100 text-sm font-medium">Total Documents</p>
                    <p class="text-3xl font-bold mt-2">{{ number_format($stats['total']) }}</p>
                </div>
                <div class="bg-blue-500 bg-opacity-30 p-3 rounded-lg">
                    <i class="fas fa-file text-2xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-gradient-to-br from-purple-600 to-purple-700 rounded-lg p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-purple-100 text-sm font-medium">Taille Totale</p>
                    <p class="text-3xl font-bold mt-2">{{ number_format($stats['total_size'] / 1024 / 1024, 2) }} MB</p>
                </div>
                <div class="bg-purple-500 bg-opacity-30 p-3 rounded-lg">
                    <i class="fas fa-hdd text-2xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-gradient-to-br from-green-600 to-green-700 rounded-lg p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-green-100 text-sm font-medium">Catégories</p>
                    <p class="text-3xl font-bold mt-2">{{ number_format($stats['categories']) }}</p>
                </div>
                <div class="bg-green-500 bg-opacity-30 p-3 rounded-lg">
                    <i class="fas fa-folder text-2xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-gradient-to-br from-orange-600 to-orange-700 rounded-lg p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-orange-100 text-sm font-medium">Téléchargements</p>
                    <p class="text-3xl font-bold mt-2">{{ number_format($stats['downloads']) }}</p>
                </div>
                <div class="bg-orange-500 bg-opacity-30 p-3 rounded-lg">
                    <i class="fas fa-download text-2xl"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="bg-dark-100 border border-dark-200 rounded-lg p-4">
        <form method="GET" class="flex flex-wrap gap-4">
            <div class="flex-1 min-w-[200px]">
                <input type="text" name="search" value="{{ request('search') }}"
                    placeholder="Rechercher un document..."
                    class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg text-gray-100 focus:ring-2 focus:ring-primary-500">
            </div>
            <select name="category" class="px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg text-gray-100">
                <option value="">Toutes les catégories</option>
                @foreach($categories as $category)
                    <option value="{{ $category->id }}" {{ request('category') == $category->id ? 'selected' : '' }}>
                        {{ $category->name }}
                    </option>
                @endforeach
            </select>
            <button type="submit" class="px-6 py-2 bg-primary-600 hover:bg-primary-700 text-white rounded-lg transition-colors">
                <i class="fas fa-filter mr-2"></i>Filtrer
            </button>
            <a href="{{ route('admin.documents.index') }}" class="px-6 py-2 bg-dark-50 border border-dark-200 hover:bg-dark-100 text-gray-100 rounded-lg transition-colors">
                Réinitialiser
            </a>
        </form>
    </div>

    <!-- Documents List -->
    <div class="bg-dark-100 border border-dark-200 rounded-lg overflow-hidden">
        @if($documents->count())
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 p-6">
                @foreach($documents as $document)
                    <div class="bg-dark-50 border border-dark-300 rounded-lg p-4 hover:bg-dark-100 transition-all">
                        <div class="flex items-start gap-4">
                            <!-- Icon based on file type -->
                            <div class="w-12 h-12 bg-gradient-to-br from-primary-500 to-primary-600 rounded-lg flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-file-{{ $document->file_type == 'pdf' ? 'pdf' : ($document->file_type == 'docx' || $document->file_type == 'doc' ? 'word' : ($document->file_type == 'xlsx' || $document->file_type == 'xls' ? 'excel' : 'alt')) }} text-white text-xl"></i>
                            </div>
                            
                            <div class="flex-1 min-w-0">
                                <h3 class="text-white font-semibold truncate">{{ $document->title }}</h3>
                                <p class="text-gray-400 text-sm mt-1 truncate">{{ $document->file_name }}</p>
                                
                                <!-- Category -->
                                @if($document->category)
                                    <span class="inline-block mt-2 px-2 py-1 bg-primary-500/20 text-primary-400 rounded text-xs">
                                        {{ $document->category->name }}
                                    </span>
                                @endif
                                
                                <!-- Metadata -->
                                <div class="flex items-center gap-3 mt-3 text-xs text-gray-500">
                                    <span><i class="fas fa-database mr-1"></i>{{ $document->formatted_size }}</span>
                                    <span><i class="fas fa-download mr-1"></i>{{ $document->download_count }}</span>
                                    <span><i class="fas fa-clock mr-1"></i>{{ $document->created_at->diffForHumans() }}</span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Actions -->
                        <div class="flex gap-2 mt-4 pt-4 border-t border-dark-300">
                            <a href="{{ route('admin.documents.download', $document) }}" 
                               class="flex-1 px-3 py-2 bg-primary-600 hover:bg-primary-700 text-white rounded text-sm text-center transition-colors">
                                <i class="fas fa-download mr-1"></i>Télécharger
                            </a>
                            <a href="{{ route('admin.documents.edit', $document) }}" 
                               class="px-3 py-2 bg-dark-300 hover:bg-dark-400 text-white rounded text-sm transition-colors">
                                <i class="fas fa-edit"></i>
                            </a>
                            <form action="{{ route('admin.documents.destroy', $document) }}" method="POST" class="inline" 
                                  data-confirm="Supprimer ce document ?">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="px-3 py-2 bg-red-600 hover:bg-red-700 text-white rounded text-sm transition-colors">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>

            <!-- Pagination -->
            <div class="px-6 py-4 border-t border-dark-200">
                {{ $documents->links() }}
            </div>
        @else
            <div class="p-12 text-center">
                <i class="fas fa-inbox text-6xl text-gray-600 mb-4"></i>
                <p class="text-gray-400 text-lg">Aucun document trouvé</p>
                <a href="{{ route('admin.documents.create') }}" class="inline-block mt-4 px-6 py-2 bg-primary-600 hover:bg-primary-700 text-white rounded-lg transition-colors">
                    Uploader le premier document
                </a>
            </div>
        @endif
    </div>
</div>
@endsection
