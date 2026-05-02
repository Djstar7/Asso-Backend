@extends('admin.layouts.app')

@section('title', 'Vérifications DIASPO')
@section('header', 'Vérifications DIASPO')

@section('content')
<div class="p-6">
    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-bold text-white">Vérifications DIASPO</h1>
            <p class="text-gray-400 mt-1">Validez les documents d'identité des utilisateurs</p>
        </div>
    </div>

    <!-- Status Tabs -->
    <div class="bg-dark-100 rounded-xl shadow-lg p-4 mb-6">
        <div class="flex gap-2 flex-wrap">
            <a href="{{ route('admin.diaspo.verifications.index', ['status' => 'pending']) }}"
               class="px-6 py-3 rounded-lg transition-all {{ $status === 'pending' ? 'bg-gradient-to-r from-primary-500 to-primary-600 text-white shadow-md' : 'bg-dark-50 text-gray-300 hover:bg-dark-200' }}">
                <i class="fas fa-clock mr-2"></i>
                En attente
                <span class="ml-2 px-2 py-0.5 bg-yellow-500 text-dark-100 text-xs font-bold rounded-full">
                    {{ $counts['pending'] }}
                </span>
            </a>
            <a href="{{ route('admin.diaspo.verifications.index', ['status' => 'verified']) }}"
               class="px-6 py-3 rounded-lg transition-all {{ $status === 'verified' ? 'bg-gradient-to-r from-primary-500 to-primary-600 text-white shadow-md' : 'bg-dark-50 text-gray-300 hover:bg-dark-200' }}">
                <i class="fas fa-check-circle mr-2"></i>
                Vérifiés
                <span class="ml-2 px-2 py-0.5 bg-green-500 text-white text-xs font-bold rounded-full">
                    {{ $counts['verified'] }}
                </span>
            </a>
            <a href="{{ route('admin.diaspo.verifications.index', ['status' => 'rejected']) }}"
               class="px-6 py-3 rounded-lg transition-all {{ $status === 'rejected' ? 'bg-gradient-to-r from-primary-500 to-primary-600 text-white shadow-md' : 'bg-dark-50 text-gray-300 hover:bg-dark-200' }}">
                <i class="fas fa-times-circle mr-2"></i>
                Rejetés
                <span class="ml-2 px-2 py-0.5 bg-red-500 text-white text-xs font-bold rounded-full">
                    {{ $counts['rejected'] }}
                </span>
            </a>
            <a href="{{ route('admin.diaspo.verifications.index', ['status' => 'all']) }}"
               class="px-6 py-3 rounded-lg transition-all {{ $status === 'all' ? 'bg-gradient-to-r from-primary-500 to-primary-600 text-white shadow-md' : 'bg-dark-50 text-gray-300 hover:bg-dark-200' }}">
                <i class="fas fa-list mr-2"></i>
                Tous
            </a>
        </div>
    </div>

    <!-- Verifications List -->
    @if($verifications->isEmpty())
        <div class="bg-dark-100 rounded-xl shadow-lg p-12 text-center">
            <i class="fas fa-id-card text-6xl text-gray-600 mb-4"></i>
            <h3 class="text-xl font-semibold text-white mb-2">Aucune vérification</h3>
            <p class="text-gray-400">
                @if($status === 'pending')
                    Il n'y a pas de vérifications en attente pour le moment.
                @elseif($status === 'verified')
                    Aucune vérification approuvée.
                @elseif($status === 'rejected')
                    Aucune vérification rejetée.
                @else
                    Aucune vérification trouvée.
                @endif
            </p>
        </div>
    @else
        <div class="grid gap-4">
            @foreach($verifications as $verification)
                <div class="bg-dark-100 rounded-xl shadow-lg hover:shadow-xl transition-all">
                    <div class="p-6">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center space-x-4 flex-1">
                                <!-- User Avatar -->
                                <div class="w-16 h-16 rounded-full bg-gradient-to-br from-primary-500 to-primary-600 flex items-center justify-center text-white font-bold text-xl flex-shrink-0">
                                    {{ strtoupper(substr($verification->first_name ?? 'U', 0, 1)) }}{{ strtoupper(substr($verification->last_name ?? '', 0, 1)) }}
                                </div>

                                <!-- User Info -->
                                <div class="flex-1">
                                    <h3 class="text-lg font-semibold text-white">
                                        {{ $verification->first_name }} {{ $verification->last_name }}
                                    </h3>
                                    <div class="flex items-center gap-4 text-sm text-gray-400 mt-1">
                                        <span><i class="fas fa-phone mr-1"></i>{{ $verification->phone }}</span>
                                        @if($verification->email)
                                            <span><i class="fas fa-envelope mr-1"></i>{{ $verification->email }}</span>
                                        @endif
                                    </div>
                                    <div class="text-xs text-gray-500 mt-1">
                                        <i class="fas fa-calendar mr-1"></i>
                                        Soumis le {{ $verification->updated_at->format('d/m/Y à H:i') }}
                                    </div>
                                </div>
                            </div>

                            <!-- Status Badge -->
                            <div class="flex items-center gap-3">
                                @if($verification->diaspo_verification_status === 'pending')
                                    <span class="px-4 py-2 bg-yellow-500/20 text-yellow-400 text-sm font-semibold rounded-full">
                                        <i class="fas fa-clock mr-1"></i>En attente
                                    </span>
                                @elseif($verification->diaspo_verification_status === 'verified')
                                    <span class="px-4 py-2 bg-green-500/20 text-green-400 text-sm font-semibold rounded-full">
                                        <i class="fas fa-check-circle mr-1"></i>Vérifié
                                    </span>
                                @elseif($verification->diaspo_verification_status === 'rejected')
                                    <span class="px-4 py-2 bg-red-500/20 text-red-400 text-sm font-semibold rounded-full">
                                        <i class="fas fa-times-circle mr-1"></i>Rejeté
                                    </span>
                                @endif

                                <a href="{{ route('admin.diaspo.verifications.show', $verification->id) }}"
                                   class="px-6 py-3 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg transition-all duration-200 shadow-lg hover:shadow-xl flex items-center gap-2">
                                    <i class="fas fa-eye"></i>
                                    Examiner
                                </a>
                            </div>
                        </div>

                        @if($verification->diaspo_verification_status === 'rejected' && $verification->diaspo_rejection_reason)
                            <div class="mt-4 p-3 bg-red-900/20 border-l-4 border-red-500 rounded">
                                <p class="text-sm text-red-400">
                                    <i class="fas fa-exclamation-triangle mr-2"></i>
                                    <strong>Raison du rejet:</strong> {{ $verification->diaspo_rejection_reason }}
                                </p>
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        <!-- Pagination -->
        <div class="mt-6">
            {{ $verifications->links() }}
        </div>
    @endif
</div>
@endsection
