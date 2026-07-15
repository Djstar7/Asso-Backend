@extends('admin.layouts.app')

@section('content')
<div class="p-6">
    <!-- Header -->
    <div class="mb-6">
        <div class="flex items-center gap-3 mb-2">
            <a href="{{ route('admin.fcm-tokens.index') }}"
               class="text-gray-400 hover:text-primary-600 transition-colors">
                <i class="fas fa-arrow-left"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-white">Tokens FCM - {{ $user->name }}</h1>
                <p class="text-gray-400">Détails des tokens de notification push</p>
            </div>
        </div>
    </div>

    <!-- User Info Card -->
    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6 mb-6">
        <div class="flex items-start justify-between">
            <div class="flex items-center">
                <div class="w-16 h-16 rounded-full bg-gradient-to-br from-primary-500 to-primary-600 flex items-center justify-center text-white font-bold text-2xl mr-4">
                    {{ strtoupper(substr($user->name ?? 'U', 0, 1)) }}
                </div>
                <div>
                    <h2 class="text-xl font-bold text-white">{{ $user->name }}</h2>
                    <p class="text-gray-400">{{ $user->email }}</p>
                    <p class="text-sm text-gray-500">{{ $user->phone ?? 'Pas de téléphone' }}</p>
                </div>
            </div>
            <div class="text-right">
                <div class="mb-2">
                    <span class="text-sm text-gray-400">Total de tokens:</span>
                    <span class="ml-2 text-xl font-bold text-white">{{ $user->deviceTokens->count() }}</span>
                </div>
                <div>
                    <span class="text-sm text-gray-400">Tokens actifs:</span>
                    <span class="ml-2 text-xl font-bold text-green-400">{{ $user->deviceTokens->where('is_active', true)->count() }}</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Info Box -->
    @if($user->deviceTokens->where('is_active', true)->count() > 0)
        <div class="bg-green-500/10 border border-green-500/30 rounded-xl p-4 mb-6">
            <div class="flex items-start">
                <i class="fas fa-check-circle text-green-500 text-lg mt-0.5 mr-3"></i>
                <div>
                    <h4 class="text-sm font-semibold text-green-400 mb-1">Utilisateur notifiable</h4>
                    <p class="text-xs text-green-300/80">
                        Cet utilisateur peut recevoir des notifications push car il a {{ $user->deviceTokens->where('is_active', true)->count() }} token(s) actif(s).
                    </p>
                </div>
            </div>
        </div>
    @else
        <div class="bg-red-500/10 border border-red-500/30 rounded-xl p-4 mb-6">
            <div class="flex items-start">
                <i class="fas fa-exclamation-triangle text-red-500 text-lg mt-0.5 mr-3"></i>
                <div>
                    <h4 class="text-sm font-semibold text-red-400 mb-1">Utilisateur non notifiable</h4>
                    <p class="text-xs text-red-300/80">
                        Cet utilisateur ne peut pas recevoir de notifications push car il n'a aucun token actif.
                    </p>
                </div>
            </div>
        </div>
    @endif

    <!-- Tokens List -->
    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 overflow-hidden">
        <div class="px-6 py-4 bg-dark-50 border-b border-dark-200 flex items-center justify-between">
            <h3 class="text-lg font-semibold text-white">
                <i class="fas fa-mobile-alt text-primary-500 mr-2"></i>
                Liste des Tokens FCM
            </h3>
            <span class="text-sm text-gray-400">{{ $user->deviceTokens->count() }} token(s)</span>
        </div>

        @if($user->deviceTokens->count() > 0)
            <div class="divide-y divide-dark-200">
                @foreach($user->deviceTokens as $token)
                    <div class="p-6 hover:bg-dark-50 transition-colors">
                        <div class="flex items-start justify-between">
                            <div class="flex-1">
                                <!-- Token Status & Platform -->
                                <div class="flex items-center gap-3 mb-3">
                                    @if($token->is_active)
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-green-500/20 text-green-400">
                                            <i class="fas fa-check-circle mr-1"></i> Actif
                                        </span>
                                    @else
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-gray-500/20 text-gray-400">
                                            <i class="fas fa-times-circle mr-1"></i> Inactif
                                        </span>
                                    @endif

                                    @if($token->platform === 'android')
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-green-500/20 text-green-400">
                                            <i class="fab fa-android mr-1"></i> Android
                                        </span>
                                    @elseif($token->platform === 'ios')
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-blue-500/20 text-blue-400">
                                            <i class="fab fa-apple mr-1"></i> iOS
                                        </span>
                                    @else
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-gray-500/20 text-gray-400">
                                            <i class="fas fa-mobile mr-1"></i> {{ $token->platform ?? 'Inconnu' }}
                                        </span>
                                    @endif
                                </div>

                                <!-- Device Info -->
                                <div class="mb-3">
                                    <p class="text-sm text-gray-300 mb-1">
                                        <i class="fas fa-mobile-alt text-primary-400 mr-2"></i>
                                        <span class="font-medium">Appareil:</span>
                                        {{ $token->device_name ?? 'Inconnu' }}
                                        @if($token->device_model)
                                            <span class="text-gray-500">({{ $token->device_model }})</span>
                                        @endif
                                    </p>
                                </div>

                                <!-- Token Value -->
                                <div class="mb-3">
                                    <p class="text-xs text-gray-400 mb-1">Token FCM:</p>
                                    <div class="flex items-center gap-2">
                                        <code class="flex-1 px-3 py-2 bg-dark-50 border border-dark-300 rounded text-xs text-gray-300 font-mono break-all">
                                            {{ $token->token }}
                                        </code>
                                        <button onclick="navigator.clipboard.writeText('{{ $token->token }}')"
                                                class="px-3 py-2 bg-primary-500/20 text-primary-400 rounded hover:bg-primary-500/30 transition-all text-xs">
                                            <i class="fas fa-copy"></i>
                                        </button>
                                    </div>
                                </div>

                                <!-- Timestamps -->
                                <div class="grid grid-cols-2 gap-4 text-xs text-gray-400">
                                    <div>
                                        <i class="fas fa-calendar-plus text-primary-400 mr-1"></i>
                                        <span class="font-medium">Créé:</span>
                                        {{ $token->created_at->format('d/m/Y H:i') }}
                                    </div>
                                    <div>
                                        <i class="fas fa-clock text-primary-400 mr-1"></i>
                                        <span class="font-medium">Dernière utilisation:</span>
                                        {{ $token->last_used_at ? $token->last_used_at->diffForHumans() : 'Jamais' }}
                                    </div>
                                </div>
                            </div>

                            <!-- Actions -->
                            <div class="flex flex-col gap-2 ml-4">
                                <form action="{{ route('admin.fcm-tokens.token.toggle', $token) }}" method="POST" class="inline">
                                    @csrf
                                    <button type="submit"
                                            class="px-4 py-2 rounded-lg transition-all text-sm {{ $token->is_active ? 'bg-yellow-500/20 text-yellow-400 hover:bg-yellow-500/30' : 'bg-green-500/20 text-green-400 hover:bg-green-500/30' }}">
                                        <i class="fas fa-{{ $token->is_active ? 'ban' : 'check' }} mr-1"></i>
                                        {{ $token->is_active ? 'Désactiver' : 'Activer' }}
                                    </button>
                                </form>

                                <form action="{{ route('admin.fcm-tokens.token.destroy', $token) }}" method="POST"
                                      data-confirm="Êtes-vous sûr de vouloir supprimer ce token ?" class="inline">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit"
                                            class="px-4 py-2 bg-red-500/20 text-red-400 rounded-lg hover:bg-red-500/30 transition-all text-sm">
                                        <i class="fas fa-trash mr-1"></i> Supprimer
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="p-12 text-center">
                <i class="fas fa-mobile-alt text-gray-500 text-5xl mb-4"></i>
                <p class="text-gray-400 text-lg">Aucun token FCM trouvé pour cet utilisateur</p>
                <p class="text-gray-500 text-sm mt-2">L'utilisateur doit se connecter sur l'application mobile pour générer un token</p>
            </div>
        @endif
    </div>
</div>
@endsection
