@extends('admin.layouts.app')
@section('title', 'Uploader un document')
@section('content')
<div class="p-6">
    <div class="max-w-4xl mx-auto">
        <div class="bg-dark-100 border border-dark-200 rounded-lg p-6">
            <h1 class="text-2xl font-bold text-white mb-6">Uploader un nouveau document</h1>


            <form action="{{ route('admin.documents.store') }}" method="POST" enctype="multipart/form-data">
                @csrf

                <div class="space-y-6">
                    <!-- Titre -->
                    <div>
                        <label class="block text-gray-300 mb-2">Titre *</label>
                        <input type="text" name="title" value="{{ old('title') }}" required
                               class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg text-white">
                        @error('title')
                            <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Description -->
                    <div>
                        <label class="block text-gray-300 mb-2">Description</label>
                        <textarea name="description" rows="4"
                                  class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg text-white">{{ old('description') }}</textarea>
                    </div>

                    <!-- Catégorie -->
                    <div>
                        <label class="block text-gray-300 mb-2">Catégorie</label>
                        <select name="category_id" class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg text-white">
                            <option value="">Aucune catégorie</option>
                            @foreach($categories as $cat)
                                <option value="{{ $cat->id }}" {{ old('category_id') == $cat->id ? 'selected' : '' }}>
                                    {{ $cat->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Fichier -->
                    <div>
                        <label class="block text-gray-300 mb-2">Fichier *</label>
                        <input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.txt,.csv,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.odp,.rtf" required
                               class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg text-white">
                        @error('file')
                            <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Visibilité -->
                    <div>
                        <label class="block text-gray-300 mb-2">Visibilité *</label>
                        <select name="visibility" class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg text-white">
                            <option value="private" {{ old('visibility') == 'private' ? 'selected' : '' }}>Privé</option>
                            <option value="public" {{ old('visibility') == 'public' ? 'selected' : '' }}>Public</option>
                        </select>
                    </div>

                    <!-- Boutons -->
                    <div class="flex gap-3">
                        <button type="submit" class="px-6 py-2 bg-primary-600 hover:bg-primary-700 text-white rounded-lg">
                            Uploader
                        </button>
                        <a href="{{ route('admin.documents.index') }}" class="px-6 py-2 bg-dark-300 hover:bg-dark-400 text-white rounded-lg">
                            Annuler
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
