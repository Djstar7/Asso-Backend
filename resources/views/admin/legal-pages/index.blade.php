@extends('admin.layouts.app')

@section('title', 'Pages Légales')
@section('header', 'Gestion des Pages Légales')

@section('content')
<div class="space-y-6">
    <!-- Header Actions -->
    <div class="flex justify-between items-center">
        <p class="text-gray-400">Gérez les pages légales de votre plateforme (CGU, Politique de confidentialité, etc.)</p>
        <a href="{{ route('admin.legal-pages.create') }}"
           class="px-4 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:from-primary-600 hover:to-primary-700 transition-all shadow-md">
            <i class="fas fa-plus mr-2"></i> Nouvelle Page
        </a>
    </div>

    <!-- Legal Pages List -->
    <div class="bg-dark-100 rounded-lg shadow-sm border border-dark-200">
        @if($legalPages->isEmpty())
            <div class="p-12 text-center">
                <i class="fas fa-file-contract text-6xl text-gray-600 mb-4"></i>
                <h3 class="text-xl font-semibold text-white mb-2">Aucune page légale</h3>
                <p class="text-gray-400 mb-6">Commencez par créer votre première page légale</p>
                <a href="{{ route('admin.legal-pages.create') }}"
                   class="inline-flex items-center px-4 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:from-primary-600 hover:to-primary-700 transition-all shadow-md">
                    <i class="fas fa-plus mr-2"></i> Créer une page
                </a>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-dark-200">
                            <th class="px-6 py-4 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Titre</th>
                            <th class="px-6 py-4 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Slug</th>
                            <th class="px-6 py-4 text-center text-xs font-semibold text-gray-400 uppercase tracking-wider">Ordre</th>
                            <th class="px-6 py-4 text-center text-xs font-semibold text-gray-400 uppercase tracking-wider">Statut</th>
                            <th class="px-6 py-4 text-center text-xs font-semibold text-gray-400 uppercase tracking-wider">Date de création</th>
                            <th class="px-6 py-4 text-center text-xs font-semibold text-gray-400 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-dark-200">
                        @foreach($legalPages as $page)
                            <tr class="hover:bg-dark-50 transition-colors">
                                <td class="px-6 py-4">
                                    <div class="flex items-center">
                                        <i class="fas fa-file-alt text-primary-500 mr-3"></i>
                                        <span class="text-white font-medium">{{ $page->title }}</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <code class="text-xs text-gray-400 bg-dark-50 px-2 py-1 rounded">{{ $page->slug }}</code>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <span class="text-gray-300">{{ $page->order }}</span>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    @if($page->is_active)
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-green-500/10 text-green-500 border border-green-500/20">
                                            <i class="fas fa-check-circle mr-1"></i> Actif
                                        </span>
                                    @else
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-gray-500/10 text-gray-500 border border-gray-500/20">
                                            <i class="fas fa-times-circle mr-1"></i> Inactif
                                        </span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-center text-sm text-gray-400">
                                    {{ $page->created_at->format('d/m/Y') }}
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center justify-center space-x-2">
                                        <!-- Toggle Status -->
                                        <form action="{{ route('admin.legal-pages.toggle', $page) }}" method="POST" class="inline">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit"
                                                    class="p-2 text-gray-400 hover:text-primary-500 transition-colors"
                                                    title="{{ $page->is_active ? 'Désactiver' : 'Activer' }}">
                                                <i class="fas fa-{{ $page->is_active ? 'eye-slash' : 'eye' }}"></i>
                                            </button>
                                        </form>

                                        <!-- Edit -->
                                        <a href="{{ route('admin.legal-pages.edit', $page) }}"
                                           class="p-2 text-gray-400 hover:text-blue-500 transition-colors"
                                           title="Modifier">
                                            <i class="fas fa-edit"></i>
                                        </a>

                                        <!-- Delete -->
                                        <form action="{{ route('admin.legal-pages.destroy', $page) }}" method="POST" class="inline"
                                              data-confirm="Êtes-vous sûr de vouloir supprimer cette page ?">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit"
                                                    class="p-2 text-gray-400 hover:text-red-500 transition-colors"
                                                    title="Supprimer">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection
