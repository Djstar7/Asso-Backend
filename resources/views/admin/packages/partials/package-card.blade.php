<div class="bg-dark-100 border-2 {{ $package->is_popular ? 'border-primary-500' : 'border-dark-200' }} rounded-xl overflow-hidden hover:shadow-xl transition-all duration-300 relative">
    <!-- Badge Populaire -->
    @if($package->is_popular)
        <div class="absolute top-4 right-4 z-10">
            <span class="bg-primary-500 text-white px-3 py-1 rounded-full text-xs font-bold">
                <i class="fas fa-star mr-1"></i>
                POPULAIRE
            </span>
        </div>
    @endif

    <!-- Badge Statut -->
    <div class="absolute top-4 left-4 z-10">
        @if($package->is_active)
            <span class="bg-green-500 text-white px-3 py-1 rounded-full text-xs font-bold">
                <i class="fas fa-check-circle mr-1"></i>
                ACTIF
            </span>
        @else
            <span class="bg-gray-500 text-white px-3 py-1 rounded-full text-xs font-bold">
                <i class="fas fa-times-circle mr-1"></i>
                INACTIF
            </span>
        @endif
    </div>

    <!-- Header avec couleur selon le type -->
    <div class="bg-gradient-to-r from-{{ $package->type_color }}-600 to-{{ $package->type_color }}-700 p-6 pt-16">
        <div class="flex items-center justify-center mb-4">
            <div class="bg-white bg-opacity-20 p-4 rounded-full">
                <i class="fas {{ $package->type_icon }} text-white text-3xl"></i>
            </div>
        </div>
        <h3 class="text-xl font-bold text-white text-center">{{ $package->name }}</h3>
        <p class="text-center text-white text-opacity-90 text-sm mt-1">{{ $package->type_label }}</p>
    </div>

    <!-- Corps de la carte -->
    <div class="p-6">
        <!-- Prix -->
        <div class="text-center mb-6">
            <div class="text-4xl font-bold text-gray-100">
                {{ number_format($package->price, 0, ',', ' ') }}
                <span class="text-xl text-gray-400">XOF</span>
            </div>
            <div class="text-sm text-gray-400 mt-1">{{ $package->formatted_duration }}</div>
        </div>

        <!-- Description -->
        <p class="text-gray-300 text-sm mb-6 line-clamp-3">{{ $package->description }}</p>

        <!-- Caractéristiques spécifiques -->
        <div class="space-y-3 mb-6">
            @if($package->type === 'storage')
                <div class="flex items-center text-gray-300">
                    <i class="fas fa-database text-{{ $package->type_color }}-500 mr-3 w-5"></i>
                    <span class="text-sm font-medium">{{ $package->formatted_storage_size }}</span>
                </div>
            @endif

            @if($package->type === 'boost')
                <div class="flex items-center text-gray-300">
                    <i class="fas fa-users text-{{ $package->type_color }}-500 mr-3 w-5"></i>
                    <span class="text-sm font-medium">{{ number_format($package->reach_users) }} utilisateurs</span>
                </div>
            @endif

            @if($package->type === 'certification' && $package->benefits)
                <div class="space-y-2">
                    @foreach(array_slice($package->benefits, 0, 3) as $benefit)
                        <div class="flex items-start text-gray-300">
                            <i class="fas fa-check text-{{ $package->type_color }}-500 mr-2 mt-1"></i>
                            <span class="text-sm">{{ $benefit }}</span>
                        </div>
                    @endforeach
                    @if(count($package->benefits) > 3)
                        <div class="text-sm text-gray-400 ml-6">
                            + {{ count($package->benefits) - 3 }} autres avantages
                        </div>
                    @endif
                </div>
            @endif

            <div class="flex items-center text-gray-300">
                <i class="fas fa-clock text-{{ $package->type_color }}-500 mr-3 w-5"></i>
                <span class="text-sm">{{ $package->duration_days }} jours</span>
            </div>
        </div>

        <!-- Actions -->
        <div class="flex gap-2">
            <a href="{{ route('admin.packages.show', $package) }}"
                class="flex-1 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-center rounded-lg transition-colors text-sm">
                <i class="fas fa-eye mr-1"></i>
                Voir
            </a>
            <a href="{{ route('admin.packages.edit', $package) }}"
                class="flex-1 px-4 py-2 bg-primary-600 hover:bg-primary-700 text-white text-center rounded-lg transition-colors text-sm">
                <i class="fas fa-edit mr-1"></i>
                Modifier
            </a>
            <form action="{{ route('admin.packages.destroy', $package) }}" method="POST" class="inline"
                data-confirm="Êtes-vous sûr de vouloir supprimer ce package ?">
                @csrf
                @method('DELETE')
                <button type="submit"
                    class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg transition-colors text-sm">
                    <i class="fas fa-trash"></i>
                </button>
            </form>
        </div>
    </div>
</div>
