@extends('admin.layouts.app')

@section('content')
<div class="p-6">
    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-bold text-white">Bypass OTP</h1>
            <p class="text-gray-400 mt-1">Gérez les numéros autorisés à bypasser l'OTP</p>
        </div>
        <button onclick="openAddModal()"
           class="px-6 py-3 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg transition-all duration-200 shadow-lg hover:shadow-xl flex items-center gap-2">
            <i class="fas fa-plus"></i>
            Ajouter un numéro
        </button>
    </div>

    @if(session('success'))
        <div class="mb-6 p-4 bg-green-900/20 border-l-4 border-green-500 rounded-lg">
            <div class="flex items-center">
                <i class="fas fa-check-circle text-green-500 mr-3"></i>
                <p class="text-green-400">{{ session('success') }}</p>
            </div>
        </div>
    @endif

    @if(session('error'))
        <div class="mb-6 p-4 bg-red-900/20 border-l-4 border-red-500 rounded-lg">
            <div class="flex items-center">
                <i class="fas fa-exclamation-circle text-red-500 mr-3"></i>
                <p class="text-red-400">{{ session('error') }}</p>
            </div>
        </div>
    @endif

    <!-- Info Box -->
    <div class="mb-6 p-4 bg-blue-900/20 border-l-4 border-blue-500 rounded-lg">
        <div class="flex items-start">
            <i class="fas fa-info-circle text-blue-500 mr-3 mt-1"></i>
            <div>
                <p class="text-blue-400 font-medium">À propos du bypass OTP</p>
                <p class="text-blue-300 text-sm mt-1">Les numéros ajoutés ici pourront se connecter via WhatsApp sans avoir besoin de saisir le code OTP. Utilisez cette fonctionnalité avec précaution pour des tests ou des comptes spécifiques.</p>
            </div>
        </div>
    </div>

    <!-- Table -->
    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-dark-200">
                    <tr>
                        <th class="px-6 py-4 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Numéro</th>
                        <th class="px-6 py-4 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Raison</th>
                        <th class="px-6 py-4 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Ajouté par</th>
                        <th class="px-6 py-4 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Date</th>
                        <th class="px-6 py-4 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Statut</th>
                        <th class="px-6 py-4 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-dark-200">
                    @forelse($bypassPhones as $phone)
                        <tr class="hover:bg-dark-50 transition-colors">
                            <td class="px-6 py-4">
                                <div class="flex items-center">
                                    <i class="fas fa-phone text-primary-500 mr-2"></i>
                                    <span class="text-white font-medium">{{ $phone->phone }}</span>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <span class="text-gray-300">{{ $phone->reason ?? 'Aucune raison spécifiée' }}</span>
                            </td>
                            <td class="px-6 py-4">
                                @if($phone->addedBy)
                                    <div class="flex items-center">
                                        <i class="fas fa-user-shield text-primary-500 mr-2"></i>
                                        <span class="text-gray-300">{{ $phone->addedBy->name ?? $phone->addedBy->email }}</span>
                                    </div>
                                @else
                                    <span class="text-gray-500">N/A</span>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                <span class="text-gray-300">{{ $phone->created_at->format('d/m/Y H:i') }}</span>
                            </td>
                            <td class="px-6 py-4">
                                @if($phone->is_active)
                                    <span class="px-3 py-1 bg-green-900/30 text-green-400 rounded-full text-xs font-medium">
                                        <i class="fas fa-check-circle mr-1"></i>Actif
                                    </span>
                                @else
                                    <span class="px-3 py-1 bg-gray-900/30 text-gray-400 rounded-full text-xs font-medium">
                                        <i class="fas fa-times-circle mr-1"></i>Inactif
                                    </span>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-2">
                                    <!-- Toggle Status -->
                                    <form action="{{ route('admin.otp-bypass.toggle', $phone->id) }}" method="POST" class="inline">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit"
                                                class="px-3 py-1.5 {{ $phone->is_active ? 'bg-gray-600 hover:bg-gray-700' : 'bg-green-600 hover:bg-green-700' }} text-white rounded-lg transition-colors text-sm"
                                                title="{{ $phone->is_active ? 'Désactiver' : 'Activer' }}">
                                            <i class="fas {{ $phone->is_active ? 'fa-pause' : 'fa-play' }}"></i>
                                        </button>
                                    </form>

                                    <!-- Edit -->
                                    <button onclick="openEditModal({{ json_encode($phone) }})"
                                            class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors text-sm"
                                            title="Modifier">
                                        <i class="fas fa-edit"></i>
                                    </button>

                                    <!-- Delete -->
                                    <form action="{{ route('admin.otp-bypass.destroy', $phone->id) }}" method="POST" class="inline"
                                          onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer ce numéro ?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit"
                                                class="px-3 py-1.5 bg-red-600 hover:bg-red-700 text-white rounded-lg transition-colors text-sm"
                                                title="Supprimer">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center">
                                <div class="flex flex-col items-center justify-center">
                                    <i class="fas fa-mobile-alt text-gray-600 text-4xl mb-3"></i>
                                    <p class="text-gray-400 text-lg">Aucun numéro de bypass configuré</p>
                                    <p class="text-gray-500 text-sm mt-1">Ajoutez des numéros pour permettre la connexion sans OTP</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        @if($bypassPhones->hasPages())
            <div class="px-6 py-4 border-t border-dark-200">
                {{ $bypassPhones->links() }}
            </div>
        @endif
    </div>
</div>

<!-- Add Modal -->
<div id="addModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center">
    <div class="bg-dark-100 rounded-xl shadow-2xl max-w-md w-full mx-4 border border-dark-200">
        <div class="p-6">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-xl font-bold text-white">Ajouter un numéro</h3>
                <button onclick="closeAddModal()" class="text-gray-400 hover:text-white transition-colors">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>

            <form action="{{ route('admin.otp-bypass.store') }}" method="POST">
                @csrf
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-white mb-2">
                            <i class="fas fa-phone text-primary-500 mr-1"></i>
                            Numéro de téléphone *
                        </label>
                        <input type="text"
                               name="phone"
                               required
                               placeholder="+221771234567"
                               class="w-full px-4 py-2 bg-dark-50 border border-dark-300 text-white placeholder-gray-400 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                        <p class="text-gray-400 text-xs mt-1">Format international recommandé (ex: +221771234567)</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-white mb-2">
                            <i class="fas fa-comment text-primary-500 mr-1"></i>
                            Raison (optionnel)
                        </label>
                        <textarea name="reason"
                                  rows="3"
                                  placeholder="Ex: Compte de test, administrateur, etc."
                                  class="w-full px-4 py-2 bg-dark-50 border border-dark-300 text-white placeholder-gray-400 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"></textarea>
                    </div>
                </div>

                <div class="flex justify-end gap-3 mt-6">
                    <button type="button"
                            onclick="closeAddModal()"
                            class="px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded-lg transition-colors">
                        Annuler
                    </button>
                    <button type="submit"
                            class="px-6 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg transition-all duration-200">
                        <i class="fas fa-plus mr-2"></i>Ajouter
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center">
    <div class="bg-dark-100 rounded-xl shadow-2xl max-w-md w-full mx-4 border border-dark-200">
        <div class="p-6">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-xl font-bold text-white">Modifier le numéro</h3>
                <button onclick="closeEditModal()" class="text-gray-400 hover:text-white transition-colors">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>

            <form id="editForm" method="POST">
                @csrf
                @method('PUT')
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-white mb-2">
                            <i class="fas fa-phone text-primary-500 mr-1"></i>
                            Numéro de téléphone *
                        </label>
                        <input type="text"
                               name="phone"
                               id="editPhone"
                               required
                               placeholder="+221771234567"
                               class="w-full px-4 py-2 bg-dark-50 border border-dark-300 text-white placeholder-gray-400 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-white mb-2">
                            <i class="fas fa-comment text-primary-500 mr-1"></i>
                            Raison (optionnel)
                        </label>
                        <textarea name="reason"
                                  id="editReason"
                                  rows="3"
                                  placeholder="Ex: Compte de test, administrateur, etc."
                                  class="w-full px-4 py-2 bg-dark-50 border border-dark-300 text-white placeholder-gray-400 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"></textarea>
                    </div>
                </div>

                <div class="flex justify-end gap-3 mt-6">
                    <button type="button"
                            onclick="closeEditModal()"
                            class="px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded-lg transition-colors">
                        Annuler
                    </button>
                    <button type="submit"
                            class="px-6 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg transition-all duration-200">
                        <i class="fas fa-save mr-2"></i>Enregistrer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

@push('scripts')
<script>
function openAddModal() {
    document.getElementById('addModal').classList.remove('hidden');
}

function closeAddModal() {
    document.getElementById('addModal').classList.add('hidden');
}

function openEditModal(phone) {
    const modal = document.getElementById('editModal');
    const form = document.getElementById('editForm');
    const phoneInput = document.getElementById('editPhone');
    const reasonInput = document.getElementById('editReason');

    // Set form action
    form.action = `/admin/otp-bypass/${phone.id}`;

    // Set values
    phoneInput.value = phone.phone;
    reasonInput.value = phone.reason || '';

    // Show modal
    modal.classList.remove('hidden');
}

function closeEditModal() {
    document.getElementById('editModal').classList.add('hidden');
}

// Close modals on ESC key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeAddModal();
        closeEditModal();
    }
});

// Close modals on outside click
document.getElementById('addModal').addEventListener('click', function(event) {
    if (event.target === this) {
        closeAddModal();
    }
});

document.getElementById('editModal').addEventListener('click', function(event) {
    if (event.target === this) {
        closeEditModal();
    }
});
</script>
@endpush
@endsection
