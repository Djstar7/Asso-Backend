@extends('admin.layouts.app')

@section('content')
<div class="p-6">
    <!-- Header -->
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-white">Bannières</h1>
            <p class="text-gray-400">Gérez toutes les bannières publicitaires de la plateforme</p>
        </div>
        <a href="{{ route('admin.banners.create') }}"
           class="px-4 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg transition-all">
            <i class="fas fa-plus mr-2"></i>
            Nouvelle Bannière
        </a>
    </div>

    @if(session('success'))
        <div class="mb-6 p-4 bg-green-900/20 border-l-4 border-green-500 rounded">
            <p class="text-green-300"><i class="fas fa-check-circle mr-2"></i>{{ session('success') }}</p>
        </div>
    @endif

    @if(session('error'))
        <div class="mb-6 p-4 bg-red-900/20 border-l-4 border-red-500 rounded">
            <p class="text-red-300"><i class="fas fa-exclamation-circle mr-2"></i>{{ session('error') }}</p>
        </div>
    @endif

    <!-- Banners List -->
    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200">
        @if($banners->count() > 0)
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-dark-200">
                    <thead class="bg-dark-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-white uppercase tracking-wider">Aperçu</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-white uppercase tracking-wider">Informations</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-white uppercase tracking-wider">Position</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-white uppercase tracking-wider">Statut</th>
                            <th class="px-6 py-3 text-right text-xs font-semibold text-white uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-dark-100 divide-y divide-dark-200">
                        @foreach($banners as $banner)
                            <tr class="hover:bg-dark-50 transition-colors">
                                <!-- Image Preview -->
                                <td class="px-6 py-4">
                                    <div class="h-20 w-32 rounded-lg overflow-hidden bg-dark-200">
                                        <img src="{{ asset('storage/' . $banner->image_path) }}"
                                             alt="{{ $banner->title }}"
                                             class="w-full h-full object-cover">
                                    </div>
                                </td>

                                <!-- Information -->
                                <td class="px-6 py-4">
                                    <div class="text-sm">
                                        @if($banner->title)
                                            <div class="font-medium text-white mb-1">{{ $banner->title }}</div>
                                        @else
                                            <div class="font-medium text-gray-500 italic mb-1">Sans titre</div>
                                        @endif

                                        @if($banner->description)
                                            <div class="text-gray-400 text-xs line-clamp-2">{{ Str::limit($banner->description, 100) }}</div>
                                        @endif

                                        @if($banner->link)
                                            <div class="mt-2">
                                                <a href="{{ $banner->link }}" target="_blank"
                                                   class="text-primary-400 hover:text-primary-300 text-xs inline-flex items-center">
                                                    <i class="fas fa-external-link-alt mr-1"></i>
                                                    Lien externe
                                                </a>
                                            </div>
                                        @endif
                                    </div>
                                </td>

                                <!-- Position -->
                                <td class="px-6 py-4">
                                    <span class="px-3 py-1 text-sm font-semibold rounded-full {{ $banner->position > 0 ? 'bg-blue-500/20 text-blue-300 border border-blue-500/50' : 'bg-gray-500/20 text-gray-400 border border-gray-500/50' }}">
                                        {{ $banner->position > 0 ? 'Position ' . $banner->position : 'Non défini' }}
                                    </span>
                                </td>

                                <!-- Status -->
                                <td class="px-6 py-4">
                                    <form action="{{ route('admin.banners.toggle-status', $banner) }}" method="POST" class="inline">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit"
                                                class="px-3 py-1 text-sm font-semibold rounded-full transition-all {{ $banner->is_active ? 'bg-green-500/20 text-green-300 border border-green-500/50 hover:bg-green-500/30' : 'bg-gray-500/20 text-gray-400 border border-gray-500/50 hover:bg-gray-500/30' }}">
                                            <i class="fas fa-{{ $banner->is_active ? 'check-circle' : 'times-circle' }} mr-1"></i>
                                            {{ $banner->is_active ? 'Active' : 'Inactive' }}
                                        </button>
                                    </form>
                                </td>

                                <!-- Actions -->
                                <td class="px-6 py-4 text-right text-sm">
                                    <div class="flex justify-end gap-2">
                                        <a href="{{ route('admin.banners.show', $banner) }}"
                                           class="px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
                                           title="Voir">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="{{ route('admin.banners.edit', $banner) }}"
                                           class="px-3 py-1.5 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg transition-all"
                                           title="Modifier">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <form action="{{ route('admin.banners.destroy', $banner) }}" method="POST"
                                              data-confirm="Êtes-vous sûr de vouloir supprimer cette bannière ?"
                                              class="inline">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit"
                                                    class="px-3 py-1.5 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors"
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

            <!-- Pagination -->
            <div class="px-6 py-4 border-t border-dark-200">
                {{ $banners->links() }}
            </div>
        @else
            <div class="text-center py-12 text-gray-400">
                <i class="fas fa-image text-6xl text-gray-600 mb-4"></i>
                <p class="text-lg">Aucune bannière trouvée</p>
                <p class="text-sm mt-2">Créez votre première bannière pour commencer</p>
            </div>
        @endif
    </div>
</div>
@endsection
