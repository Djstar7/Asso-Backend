@extends('admin.layouts.app')

@section('title', 'Vérification DIASPO')
@section('header', 'Vérification DIASPO')

@section('content')
<div class="p-6">
    <!-- Back Button -->
    <div class="mb-6">
        <a href="{{ route('admin.diaspo.verifications.index') }}"
           class="inline-flex items-center text-gray-400 hover:text-primary-500 transition-colors">
            <i class="fas fa-arrow-left mr-2"></i>
            Retour à la liste
        </a>
    </div>

    <!-- User Info Card -->
    <div class="bg-dark-100 rounded-xl shadow-lg p-6 mb-6">
        <div class="flex items-center justify-between">
            <div class="flex items-center space-x-4">
                <div class="w-20 h-20 rounded-full bg-gradient-to-br from-primary-500 to-primary-600 flex items-center justify-center text-white font-bold text-2xl">
                    {{ strtoupper(substr($user->first_name ?? 'U', 0, 1)) }}{{ strtoupper(substr($user->last_name ?? '', 0, 1)) }}
                </div>
                <div>
                    <h1 class="text-2xl font-bold text-white">
                        {{ $user->first_name }} {{ $user->last_name }}
                    </h1>
                    <div class="flex items-center gap-4 text-gray-400 mt-2">
                        <span><i class="fas fa-phone mr-2"></i>{{ $user->phone }}</span>
                        @if($user->email)
                            <span><i class="fas fa-envelope mr-2"></i>{{ $user->email }}</span>
                        @endif
                    </div>
                    <div class="text-sm text-gray-500 mt-1">
                        <i class="fas fa-calendar mr-1"></i>
                        Soumis le {{ $user->updated_at->format('d/m/Y à H:i') }}
                    </div>
                </div>
            </div>

            <!-- Status Badge -->
            <div>
                @if($user->diaspo_verification_status === 'pending')
                    <span class="px-6 py-3 bg-yellow-500/20 text-yellow-400 text-lg font-semibold rounded-full inline-block">
                        <i class="fas fa-clock mr-2"></i>En attente
                    </span>
                @elseif($user->diaspo_verification_status === 'verified')
                    <span class="px-6 py-3 bg-green-500/20 text-green-400 text-lg font-semibold rounded-full inline-block">
                        <i class="fas fa-check-circle mr-2"></i>Vérifié
                    </span>
                    @if($user->diaspo_verified_at)
                        <div class="text-sm text-gray-400 mt-2 text-right">
                            Vérifié le {{ $user->diaspo_verified_at->format('d/m/Y à H:i') }}
                        </div>
                    @endif
                @elseif($user->diaspo_verification_status === 'rejected')
                    <span class="px-6 py-3 bg-red-500/20 text-red-400 text-lg font-semibold rounded-full inline-block">
                        <i class="fas fa-times-circle mr-2"></i>Rejeté
                    </span>
                @endif
            </div>
        </div>

        @if($user->diaspo_verification_status === 'rejected' && $user->diaspo_rejection_reason)
            <div class="mt-6 p-4 bg-red-900/20 border-l-4 border-red-500 rounded">
                <p class="text-red-400">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    <strong>Raison du rejet:</strong> {{ $user->diaspo_rejection_reason }}
                </p>
            </div>
        @endif
    </div>

    <!-- Documents Section -->
    <div class="bg-dark-100 rounded-xl shadow-lg p-6 mb-6">
        <h2 class="text-xl font-bold text-white mb-6 flex items-center">
            <i class="fas fa-id-card text-primary-500 mr-3"></i>
            Documents d'identité
        </h2>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Front Document -->
            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-white">Recto</h3>
                    @if($frontDocument)
                        <span class="text-sm text-gray-400">{{ $frontDocument->file_name }}</span>
                    @endif
                </div>
                @if($frontUrl)
                    <div class="relative group">
                        <img src="{{ $frontUrl }}"
                             alt="Document Recto"
                             class="w-full rounded-lg border-2 border-dark-200 hover:border-primary-500 transition-all cursor-pointer"
                             onclick="openImageModal('{{ $frontUrl }}', 'Recto')">
                        <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-30 transition-all rounded-lg flex items-center justify-center">
                            <i class="fas fa-search-plus text-white text-3xl opacity-0 group-hover:opacity-100 transition-all"></i>
                        </div>
                    </div>
                @else
                    <div class="bg-dark-50 rounded-lg p-8 text-center text-gray-500">
                        <i class="fas fa-image text-4xl mb-2"></i>
                        <p>Aucune image disponible</p>
                    </div>
                @endif
            </div>

            <!-- Back Document -->
            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-white">Verso</h3>
                    @if($backDocument)
                        <span class="text-sm text-gray-400">{{ $backDocument->file_name }}</span>
                    @endif
                </div>
                @if($backUrl)
                    <div class="relative group">
                        <img src="{{ $backUrl }}"
                             alt="Document Verso"
                             class="w-full rounded-lg border-2 border-dark-200 hover:border-primary-500 transition-all cursor-pointer"
                             onclick="openImageModal('{{ $backUrl }}', 'Verso')">
                        <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-30 transition-all rounded-lg flex items-center justify-center">
                            <i class="fas fa-search-plus text-white text-3xl opacity-0 group-hover:opacity-100 transition-all"></i>
                        </div>
                    </div>
                @else
                    <div class="bg-dark-50 rounded-lg p-8 text-center text-gray-500">
                        <i class="fas fa-image text-4xl mb-2"></i>
                        <p>Aucune image disponible</p>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Actions -->
    @if($user->diaspo_verification_status === 'pending')
        <div class="bg-dark-100 rounded-xl shadow-lg p-6">
            <h2 class="text-xl font-bold text-white mb-6 flex items-center">
                <i class="fas fa-tasks text-primary-500 mr-3"></i>
                Actions
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Approve Button -->
                <form action="{{ route('admin.diaspo.verifications.approve', $user->id) }}" method="POST" onsubmit="return confirm('Êtes-vous sûr de vouloir approuver cette vérification ?')">
                    @csrf
                    <button type="submit"
                            class="w-full px-6 py-4 bg-gradient-to-r from-green-500 to-green-600 text-white rounded-lg hover:shadow-lg transition-all duration-200 shadow-lg hover:shadow-xl flex items-center justify-center gap-3 text-lg font-semibold">
                        <i class="fas fa-check-circle"></i>
                        Approuver la vérification
                    </button>
                </form>

                <!-- Reject Button -->
                <button type="button"
                        onclick="openRejectModal()"
                        class="w-full px-6 py-4 bg-gradient-to-r from-red-500 to-red-600 text-white rounded-lg hover:shadow-lg transition-all duration-200 shadow-lg hover:shadow-xl flex items-center justify-center gap-3 text-lg font-semibold">
                    <i class="fas fa-times-circle"></i>
                    Rejeter la vérification
                </button>
            </div>
        </div>
    @endif
</div>

<!-- Image Modal -->
<div id="imageModal" class="fixed inset-0 bg-black bg-opacity-75 z-50 hidden flex items-center justify-center p-4" onclick="closeImageModal()">
    <div class="relative max-w-6xl max-h-full">
        <button onclick="closeImageModal()" class="absolute -top-10 right-0 text-white text-2xl hover:text-primary-500">
            <i class="fas fa-times"></i>
        </button>
        <img id="modalImage" src="" alt="" class="max-w-full max-h-[90vh] rounded-lg">
        <div id="modalTitle" class="text-white text-center mt-4 text-lg font-semibold"></div>
    </div>
</div>

<!-- Reject Modal -->
<div id="rejectModal" class="fixed inset-0 bg-black bg-opacity-75 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-dark-100 rounded-xl shadow-2xl max-w-md w-full p-6">
        <h3 class="text-xl font-bold text-white mb-4 flex items-center">
            <i class="fas fa-times-circle text-red-500 mr-3"></i>
            Rejeter la vérification
        </h3>

        <form action="{{ route('admin.diaspo.verifications.reject', $user->id) }}" method="POST">
            @csrf
            <div class="mb-4">
                <label class="block text-sm font-medium text-white mb-2">
                    Raison du rejet <span class="text-red-500">*</span>
                </label>
                <textarea name="reason"
                          required
                          rows="4"
                          maxlength="500"
                          class="w-full px-4 py-2 bg-dark-50 border border-dark-300 text-white placeholder-gray-400 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-red-500"
                          placeholder="Expliquez pourquoi ce document est rejeté (max 500 caractères)"></textarea>
                <p class="text-xs text-gray-400 mt-1">Cette raison sera communiquée à l'utilisateur</p>
            </div>

            <div class="flex gap-3">
                <button type="button"
                        onclick="closeRejectModal()"
                        class="flex-1 px-4 py-2 bg-dark-50 text-gray-300 rounded-lg hover:bg-dark-200 transition-all">
                    Annuler
                </button>
                <button type="submit"
                        class="flex-1 px-4 py-2 bg-gradient-to-r from-red-500 to-red-600 text-white rounded-lg hover:shadow-lg transition-all">
                    Confirmer le rejet
                </button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
function openImageModal(url, title) {
    document.getElementById('modalImage').src = url;
    document.getElementById('modalTitle').textContent = title;
    document.getElementById('imageModal').classList.remove('hidden');
    document.getElementById('imageModal').classList.add('flex');
}

function closeImageModal() {
    document.getElementById('imageModal').classList.add('hidden');
    document.getElementById('imageModal').classList.remove('flex');
}

function openRejectModal() {
    document.getElementById('rejectModal').classList.remove('hidden');
    document.getElementById('rejectModal').classList.add('flex');
}

function closeRejectModal() {
    document.getElementById('rejectModal').classList.add('hidden');
    document.getElementById('rejectModal').classList.remove('flex');
}

// Close modals on ESC key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeImageModal();
        closeRejectModal();
    }
});
</script>
@endpush
@endsection
