@extends('admin.layouts.app')
@section('title', 'Catégories du coffre-fort')
@section('content')
<div class="p-6 space-y-6">
    <h1 class="text-2xl font-bold text-white">Catégories du coffre-fort</h1>
    <a href="{{ route('admin.vault.index') }}" class="text-primary-400">Retour au coffre-fort</a>
    <div class="bg-dark-100 border border-dark-200 rounded-lg divide-y divide-dark-200">
        @forelse($categories as $category)
            <div class="p-4 flex items-center justify-between gap-4">
                <span class="text-white">{{ $category->name }}</span>
                <a href="{{ route('admin.vault.index', ['category' => $category->id]) }}" class="text-primary-400">{{ $category->credentials_count }} identifiant(s)</a>
            </div>
        @empty
            <p class="p-6 text-gray-400">Aucune catégorie enregistrée.</p>
        @endforelse
    </div>
</div>
@endsection
