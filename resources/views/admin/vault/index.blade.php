@extends('admin.layouts.app')
@section('title', 'Vault - Credentials')
@section('content')
<div class="p-6 space-y-6">
    <div class="flex justify-between items-center">
        <div><h1 class="text-3xl font-bold text-gray-100"><i class="fas fa-lock mr-2"></i>Vault - Credentials</h1><p class="text-gray-400 mt-1">Gestionnaire sécurisé de mots de passe et credentials</p></div>
        <a href="{{ route('admin.vault.create') }}" class="px-6 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:from-primary-600 hover:to-primary-700"><i class="fas fa-plus mr-2"></i>Nouveau Credential</a>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="bg-gradient-to-br from-blue-600 to-blue-700 rounded-lg p-6 text-white"><p class="text-blue-100 text-sm">Total Credentials</p><p class="text-3xl font-bold mt-2">{{ $stats['total'] }}</p></div>
        <div class="bg-gradient-to-br from-yellow-600 to-yellow-700 rounded-lg p-6 text-white"><p class="text-yellow-100 text-sm">Favoris</p><p class="text-3xl font-bold mt-2">{{ $stats['favorites'] }}</p></div>
        <div class="bg-gradient-to-br from-purple-600 to-purple-700 rounded-lg p-6 text-white"><p class="text-purple-100 text-sm">Catégories</p><p class="text-3xl font-bold mt-2">{{ $stats['categories'] }}</p></div>
    </div>
    <div class="bg-dark-100 border border-dark-200 rounded-lg p-4">
        <form method="GET" class="flex flex-wrap gap-4">
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Rechercher..." class="flex-1 px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg text-white">
            <select name="category" class="px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg text-white"><option value="">Toutes les catégories</option>@foreach($categories as $cat)<option value="{{ $cat->id }}" {{ request('category') == $cat->id ? 'selected' : '' }}>{{ $cat->name }}</option>@endforeach</select>
            <button type="submit" class="px-6 py-2 bg-primary-600 hover:bg-primary-700 text-white rounded-lg">Filtrer</button>
            <a href="{{ route('admin.vault.index') }}" class="px-6 py-2 bg-dark-300 hover:bg-dark-400 text-white rounded-lg">Réinitialiser</a>
        </form>
    </div>
    @if($credentials->count())
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        @foreach($credentials as $cred)
        <div class="bg-dark-100 border border-dark-200 rounded-lg p-4 hover:bg-dark-50 transition-all">
            <div class="flex items-start justify-between mb-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-gradient-to-br from-primary-500 to-primary-600 rounded-lg flex items-center justify-center"><i class="fas fa-key text-white"></i></div>
                    <div><h3 class="text-white font-semibold">{{ $cred->name }}</h3><p class="text-gray-400 text-sm">{{ $cred->username }}</p></div>
                </div>
                <button onclick="toggleFavorite({{ $cred->id }}, this)" class="text-gray-400 hover:text-yellow-400"><i class="fas fa-star {{ $cred->is_favorite ? 'text-yellow-400' : '' }}"></i></button>
            </div>
            @if($cred->category)<span class="inline-block px-2 py-1 bg-primary-500/20 text-primary-400 rounded text-xs mb-3">{{ $cred->category->name }}</span>@endif
            <div class="space-y-2 mb-4">
                @if($cred->url)<p class="text-gray-400 text-sm truncate"><i class="fas fa-link mr-2"></i>{{ $cred->url }}</p>@endif
                <div class="flex items-center gap-2"><input type="password" value="••••••••" readonly id="pwd-{{ $cred->id }}" class="flex-1 px-3 py-2 bg-dark-50 border border-dark-300 rounded text-white text-sm"><button onclick="revealPassword({{ $cred->id }})" class="px-3 py-2 bg-primary-600 hover:bg-primary-700 text-white rounded text-sm"><i class="fas fa-eye"></i></button></div>
            </div>
            <div class="flex gap-2 pt-3 border-t border-dark-300"><a href="{{ route('admin.vault.edit', $cred) }}" class="flex-1 px-3 py-2 bg-dark-300 hover:bg-dark-400 text-white rounded text-sm text-center"><i class="fas fa-edit mr-1"></i>Modifier</a><form action="{{ route('admin.vault.destroy', $cred) }}" method="POST" data-confirm="Supprimer ?">@csrf @method('DELETE')<button class="px-3 py-2 bg-red-600 hover:bg-red-700 text-white rounded text-sm"><i class="fas fa-trash"></i></button></form></div>
        </div>
        @endforeach
    </div>
    @else
    <div class="bg-dark-100 border border-dark-200 rounded-lg p-12 text-center"><i class="fas fa-lock text-6xl text-gray-600 mb-4"></i><p class="text-gray-400 text-lg">Aucun credential</p><a href="{{ route('admin.vault.create') }}" class="inline-block mt-4 px-6 py-2 bg-primary-600 hover:bg-primary-700 text-white rounded-lg">Ajouter le premier</a></div>
    @endif
</div>
<script>
async function revealPassword(id) {
    try {
        const response = await fetch(`/admin/vault/${id}/reveal`);
        const data = await response.json();
        if (data.success) {
            const input = document.getElementById('pwd-' + id);
            input.type = 'text';
            input.value = data.password;
            setTimeout(() => { input.type = 'password'; input.value = '••••••••'; }, 5000);
        }
    } catch (error) {
        alert('Erreur');
    }
}
async function toggleFavorite(id, btn) {
    try {
        const response = await fetch(`/admin/vault/${id}/toggle-favorite`, { method: 'POST', headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' } });
        const data = await response.json();
        if (data.success) {
            const icon = btn.querySelector('i');
            if (data.is_favorite) {
                icon.classList.add('text-yellow-400');
            } else {
                icon.classList.remove('text-yellow-400');
            }
        }
    } catch (error) {
        alert('Erreur');
    }
}
</script>
@endsection
