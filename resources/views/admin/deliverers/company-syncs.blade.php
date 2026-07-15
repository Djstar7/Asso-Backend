@extends('admin.layouts.app')

@section('content')
<div class="p-6">
    <!-- Header -->
    <div class="mb-6">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="{{ route('admin.deliverers.show', $company) }}" class="text-gray-400 hover:text-primary-500 transition-colors">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-white">Synchronisations - {{ $company->name }}</h1>
                    <p class="text-gray-400">Gestion des utilisateurs synchronisés</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-dark-100 rounded-xl border border-dark-200 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-400 text-sm">Total Syncs</p>
                    <p class="text-2xl font-bold text-white mt-1">{{ $company->codeSyncs->count() }}</p>
                </div>
                <div class="w-12 h-12 bg-primary-500/20 rounded-lg flex items-center justify-center">
                    <i class="fas fa-users text-primary-500 text-xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-dark-100 rounded-xl border border-dark-200 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-400 text-sm">Actifs</p>
                    <p class="text-2xl font-bold text-green-400 mt-1">{{ $company->codeSyncs->where('is_active', true)->where('is_banned', false)->count() }}</p>
                </div>
                <div class="w-12 h-12 bg-green-500/20 rounded-lg flex items-center justify-center">
                    <i class="fas fa-check-circle text-green-500 text-xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-dark-100 rounded-xl border border-dark-200 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-400 text-sm">Désynchronisés</p>
                    <p class="text-2xl font-bold text-yellow-400 mt-1">{{ $company->codeSyncs->where('is_active', false)->where('is_banned', false)->count() }}</p>
                </div>
                <div class="w-12 h-12 bg-yellow-500/20 rounded-lg flex items-center justify-center">
                    <i class="fas fa-unlink text-yellow-500 text-xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-dark-100 rounded-xl border border-dark-200 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-400 text-sm">Bannis</p>
                    <p class="text-2xl font-bold text-red-400 mt-1">{{ $company->codeSyncs->where('is_banned', true)->count() }}</p>
                </div>
                <div class="w-12 h-12 bg-red-500/20 rounded-lg flex items-center justify-center">
                    <i class="fas fa-ban text-red-500 text-xl"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Syncs Table -->
    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200">
        <div class="p-6 border-b border-dark-200">
            <h2 class="text-lg font-semibold text-white">Liste des synchronisations</h2>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-dark-200">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Utilisateur</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Code</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Statut</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Synchronisé le</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-dark-200">
                    @forelse($company->codeSyncs as $sync)
                        <tr class="hover:bg-dark-200/50 transition-colors">
                            <!-- User Info -->
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div class="w-10 h-10 rounded-full bg-gradient-to-br from-primary-500 to-primary-600 flex items-center justify-center text-white font-bold mr-3">
                                        {{ strtoupper(substr($sync->user->first_name ?? 'U', 0, 1)) }}
                                    </div>
                                    <div>
                                        <div class="text-sm font-medium text-white">
                                            {{ $sync->user->first_name ?? 'N/A' }} {{ $sync->user->last_name ?? '' }}
                                        </div>
                                        <div class="text-sm text-gray-400">{{ $sync->user->email ?? 'N/A' }}</div>
                                        <div class="text-xs text-gray-500">{{ $sync->user->phone ?? 'N/A' }}</div>
                                    </div>
                                </div>
                            </td>

                            <!-- Code -->
                            <td class="px-6 py-4 whitespace-nowrap">
                                <code class="bg-dark-300 px-3 py-1 rounded text-primary-400 font-mono text-sm">
                                    {{ $sync->syncCode->sync_code }}
                                </code>
                            </td>

                            <!-- Status -->
                            <td class="px-6 py-4 whitespace-nowrap">
                                @if($sync->is_banned)
                                    <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-900/30 text-red-400 border border-red-500/50">
                                        <i class="fas fa-ban mr-1"></i> Banni
                                    </span>
                                @elseif($sync->is_active)
                                    <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-900/30 text-green-400 border border-green-500/50">
                                        <i class="fas fa-check-circle mr-1"></i> Actif
                                    </span>
                                @else
                                    <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-900/30 text-yellow-400 border border-yellow-500/50">
                                        <i class="fas fa-unlink mr-1"></i> Désynchronisé
                                    </span>
                                @endif

                                @if($sync->is_banned && $sync->ban_reason)
                                    <div class="text-xs text-gray-400 mt-1">
                                        <i class="fas fa-info-circle mr-1"></i> {{ Str::limit($sync->ban_reason, 30) }}
                                    </div>
                                @endif
                            </td>

                            <!-- Synced At -->
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-400">
                                <div>{{ $sync->synced_at->format('d/m/Y H:i') }}</div>
                                @if($sync->unsynced_at)
                                    <div class="text-xs text-yellow-400">
                                        <i class="fas fa-unlink mr-1"></i> Désync: {{ $sync->unsynced_at->format('d/m/Y H:i') }}
                                    </div>
                                @endif
                                @if($sync->banned_at)
                                    <div class="text-xs text-red-400">
                                        <i class="fas fa-ban mr-1"></i> Banni: {{ $sync->banned_at->format('d/m/Y H:i') }}
                                    </div>
                                @endif
                            </td>

                            <!-- Actions -->
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <div class="flex items-center gap-2">
                                    @if($sync->is_banned)
                                        <!-- Unban -->
                                        <form action="{{ route('admin.deliverers.syncs.unban', $sync) }}" method="POST" class="inline">
                                            @csrf
                                            <button type="submit" class="px-3 py-1 bg-blue-600 text-white rounded hover:bg-blue-700 transition-colors text-xs"
                                                    data-confirm="Débannir cet utilisateur ?">
                                                <i class="fas fa-unlock mr-1"></i> Débannir
                                            </button>
                                        </form>
                                    @elseif($sync->is_active)
                                        <!-- Unsync -->
                                        <form action="{{ route('admin.deliverers.syncs.unsync', $sync) }}" method="POST" class="inline">
                                            @csrf
                                            <button type="submit" class="px-3 py-1 bg-yellow-600 text-white rounded hover:bg-yellow-700 transition-colors text-xs"
                                                    data-confirm="Désynchroniser cet utilisateur ?">
                                                <i class="fas fa-unlink mr-1"></i> Désynchroniser
                                            </button>
                                        </form>

                                        <!-- Ban -->
                                        <button onclick="showBanModal({{ $sync->id }}, '{{ $sync->user->first_name }} {{ $sync->user->last_name }}')"
                                                class="px-3 py-1 bg-red-600 text-white rounded hover:bg-red-700 transition-colors text-xs">
                                            <i class="fas fa-ban mr-1"></i> Bannir
                                        </button>
                                    @else
                                        <!-- Reactivate -->
                                        <form action="{{ route('admin.deliverers.syncs.reactivate', $sync) }}" method="POST" class="inline">
                                            @csrf
                                            <button type="submit" class="px-3 py-1 bg-green-600 text-white rounded hover:bg-green-700 transition-colors text-xs"
                                                    data-confirm="Réactiver cet utilisateur ?">
                                                <i class="fas fa-sync mr-1"></i> Réactiver
                                            </button>
                                        </form>
                                    @endif

                                    <!-- Delete -->
                                    <form action="{{ route('admin.deliverers.syncs.delete', $sync) }}" method="POST" class="inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="px-3 py-1 bg-gray-700 text-white rounded hover:bg-gray-800 transition-colors text-xs"
                                                data-confirm="Supprimer définitivement cette synchronisation ?">
                                            <i class="fas fa-trash mr-1"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center">
                                <div class="text-gray-400">
                                    <i class="fas fa-inbox text-4xl mb-3"></i>
                                    <p>Aucune synchronisation trouvée</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Ban Modal -->
<div id="banModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center">
    <div class="bg-dark-100 rounded-xl border border-dark-200 p-6 max-w-md w-full mx-4">
        <h3 class="text-xl font-bold text-white mb-4">
            <i class="fas fa-ban text-red-500 mr-2"></i> Bannir l'utilisateur
        </h3>

        <p class="text-gray-400 mb-4">
            Vous êtes sur le point de bannir <span id="banUserName" class="text-white font-semibold"></span>.
            Cet utilisateur ne pourra plus jamais resynchroniser avec ce code.
        </p>

        <form id="banForm" method="POST">
            @csrf
            <div class="mb-4">
                <label for="ban_reason" class="block text-sm font-medium text-gray-300 mb-2">
                    Raison du bannissement (optionnel)
                </label>
                <textarea id="ban_reason" name="ban_reason" rows="3"
                          class="w-full px-4 py-2 bg-dark-200 border border-dark-300 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-primary-500"
                          placeholder="Ex: Comportement inapproprié, fraude, etc."></textarea>
            </div>

            <div class="flex gap-3">
                <button type="button" onclick="hideBanModal()"
                        class="flex-1 px-4 py-2 bg-gray-700 text-white rounded-lg hover:bg-gray-800 transition-colors">
                    Annuler
                </button>
                <button type="submit"
                        class="flex-1 px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">
                    <i class="fas fa-ban mr-2"></i> Bannir
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function showBanModal(syncId, userName) {
    document.getElementById('banUserName').textContent = userName;
    document.getElementById('banForm').action = `/admin/deliverers/syncs/${syncId}/ban`;
    document.getElementById('banModal').classList.remove('hidden');
}

function hideBanModal() {
    document.getElementById('banModal').classList.add('hidden');
    document.getElementById('ban_reason').value = '';
}

// Close modal on backdrop click
document.getElementById('banModal').addEventListener('click', function(e) {
    if (e.target === this) {
        hideBanModal();
    }
});
</script>
@endsection
