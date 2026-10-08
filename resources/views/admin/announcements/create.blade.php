@extends('admin.layouts.app')

@section('content')
<div class="p-6">
    <!-- Header -->
    <div class="mb-6">
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.announcements.index') }}"
               class="text-gray-400 hover:text-primary-600 transition-colors">
                <i class="fas fa-arrow-left"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-white">Nouvelle Annonce</h1>
                <p class="text-gray-400">Créez une nouvelle annonce pour vos utilisateurs</p>
            </div>
        </div>
    </div>

    @if(session('error'))
        <div class="mb-6 p-4 bg-red-900/20 border-l-4 border-red-500 rounded">
            <p class="text-red-300"><i class="fas fa-exclamation-circle mr-2"></i>{{ session('error') }}</p>
        </div>
    @endif

    <!-- Form -->
    <form action="{{ route('admin.announcements.store') }}" method="POST" x-data="{ targetType: 'all', channel: 'sms' }">
        @csrf

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Main Content -->
            <div class="lg:col-span-2 space-y-6">
                <!-- Message Content -->
                <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
                    <h3 class="text-lg font-semibold text-white mb-4 flex items-center">
                        <i class="fas fa-message text-primary-500 mr-2"></i>
                        Contenu du Message
                    </h3>

                    <div class="space-y-4">
                        <!-- Title -->
                        <x-admin.translatable name="title" label="Titre (optionnel)" :model="null"
                            placeholder="Ex: Nouvelle promotion" help="Titre de l'annonce (facultatif)" />

                        <!-- Message -->
                        <x-admin.translatable name="message" label="Message" :model="null" type="textarea" rows="6" required
                            placeholder="Écrivez votre message ici..."
                            help="Les utilisateurs en anglais reçoivent la version anglaise ; à défaut, le français." />
                    </div>
                </div>

                <!-- Target Selection -->
                <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
                    <h3 class="text-lg font-semibold text-white mb-4 flex items-center">
                        <i class="fas fa-users text-primary-500 mr-2"></i>
                        Destinataires
                    </h3>

                    <div class="space-y-4">
                        <!-- Target Type -->
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-3">
                                <i class="fas fa-bullseye text-primary-400 mr-1"></i>
                                Type de destinataire <span class="text-red-500">*</span>
                            </label>
                            <div class="grid grid-cols-2 gap-3">
                                <label class="relative flex items-center p-4 bg-dark-50 border-2 border-dark-300 rounded-lg cursor-pointer hover:bg-dark-200 transition-all"
                                       :class="targetType === 'all' ? 'border-primary-500 bg-primary-500/10' : ''">
                                    <input type="radio" name="target_type" value="all"
                                           x-model="targetType"
                                           checked
                                           class="sr-only">
                                    <div class="flex-1">
                                        <div class="flex items-center">
                                            <i class="fas fa-users text-primary-500 mr-2"></i>
                                            <span class="text-white font-medium">Tous les utilisateurs</span>
                                        </div>
                                        <p class="text-xs text-gray-400 mt-1">Envoyer à tous</p>
                                    </div>
                                    <i class="fas fa-check-circle text-primary-500"
                                       x-show="targetType === 'all'"></i>
                                </label>

                                <label class="relative flex items-center p-4 bg-dark-50 border-2 border-dark-300 rounded-lg cursor-pointer hover:bg-dark-200 transition-all"
                                       :class="targetType === 'specific' ? 'border-primary-500 bg-primary-500/10' : ''">
                                    <input type="radio" name="target_type" value="specific"
                                           x-model="targetType"
                                           class="sr-only">
                                    <div class="flex-1">
                                        <div class="flex items-center">
                                            <i class="fas fa-user text-primary-500 mr-2"></i>
                                            <span class="text-white font-medium">Utilisateur spécifique</span>
                                        </div>
                                        <p class="text-xs text-gray-400 mt-1">Cibler un seul utilisateur</p>
                                    </div>
                                    <i class="fas fa-check-circle text-primary-500"
                                       x-show="targetType === 'specific'"></i>
                                </label>
                            </div>
                            @error('target_type')
                                <p class="mt-1 text-sm text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- User Selection (shown only if specific) -->
                        <div x-show="targetType === 'specific'" x-cloak>
                            <label for="user_id" class="block text-sm font-medium text-gray-300 mb-2">
                                <i class="fas fa-user-check text-primary-400 mr-1"></i>
                                Sélectionner l'utilisateur <span class="text-red-500">*</span>
                            </label>
                            <select name="user_id" id="user_id"
                                    class="w-full px-4 py-3 bg-dark-50 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-primary-500 focus:border-transparent transition-all">
                                <option value="">-- Choisir un utilisateur --</option>
                                @foreach($users as $user)
                                    <option value="{{ $user->id }}" {{ old('user_id') == $user->id ? 'selected' : '' }}>
                                        {{ $user->name }} ({{ $user->email }})
                                    </option>
                                @endforeach
                            </select>
                            @error('user_id')
                                <p class="mt-1 text-sm text-red-400">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>
            </div>

            <!-- Settings Sidebar -->
            <div class="lg:col-span-1 space-y-6">
                <!-- Channel Selection -->
                <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
                    <h3 class="text-lg font-semibold text-white mb-4 flex items-center">
                        <i class="fas fa-paper-plane text-primary-500 mr-2"></i>
                        Canal d'envoi
                    </h3>

                    <div class="space-y-3">
                        <label class="relative flex items-center p-3 bg-dark-50 border-2 border-dark-300 rounded-lg cursor-pointer hover:bg-dark-200 transition-all"
                               :class="channel === 'sms' ? 'border-blue-500 bg-blue-500/10' : ''">
                            <input type="radio" name="channel" value="sms"
                                   x-model="channel"
                                   checked
                                   class="sr-only">
                            <i class="fas fa-sms text-blue-500 text-lg mr-3"></i>
                            <span class="text-white font-medium flex-1">SMS</span>
                            <i class="fas fa-check-circle text-blue-500"
                               x-show="channel === 'sms'"></i>
                        </label>

                        <label class="relative flex items-center p-3 bg-dark-50 border-2 border-dark-300 rounded-lg cursor-pointer hover:bg-dark-200 transition-all"
                               :class="channel === 'whatsapp' ? 'border-green-500 bg-green-500/10' : ''">
                            <input type="radio" name="channel" value="whatsapp"
                                   x-model="channel"
                                   class="sr-only">
                            <i class="fab fa-whatsapp text-green-500 text-lg mr-3"></i>
                            <span class="text-white font-medium flex-1">WhatsApp</span>
                            <i class="fas fa-check-circle text-green-500"
                               x-show="channel === 'whatsapp'"></i>
                        </label>

                        <label class="relative flex items-center p-3 bg-dark-50 border-2 border-dark-300 rounded-lg cursor-pointer hover:bg-dark-200 transition-all"
                               :class="channel === 'email' ? 'border-purple-500 bg-purple-500/10' : ''">
                            <input type="radio" name="channel" value="email"
                                   x-model="channel"
                                   class="sr-only">
                            <i class="fas fa-envelope text-purple-500 text-lg mr-3"></i>
                            <span class="text-white font-medium flex-1">Email</span>
                            <i class="fas fa-check-circle text-purple-500"
                               x-show="channel === 'email'"></i>
                        </label>

                        <label class="relative flex items-center p-3 bg-dark-50 border-2 border-dark-300 rounded-lg cursor-pointer hover:bg-dark-200 transition-all"
                               :class="channel === 'push' ? 'border-orange-500 bg-orange-500/10' : ''">
                            <input type="radio" name="channel" value="push"
                                   x-model="channel"
                                   class="sr-only">
                            <i class="fas fa-bell text-orange-500 text-lg mr-3"></i>
                            <span class="text-white font-medium flex-1">Push Notification</span>
                            <i class="fas fa-check-circle text-orange-500"
                               x-show="channel === 'push'"></i>
                        </label>
                    </div>
                    @error('channel')
                        <p class="mt-2 text-sm text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Scheduling (Optional) -->
                <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
                    <h3 class="text-lg font-semibold text-white mb-4 flex items-center">
                        <i class="fas fa-clock text-primary-500 mr-2"></i>
                        Programmation
                    </h3>

                    <div>
                        <label for="scheduled_at" class="block text-sm font-medium text-gray-300 mb-2">
                            <i class="fas fa-calendar text-primary-400 mr-1"></i>
                            Date/Heure d'envoi (optionnel)
                        </label>
                        <input type="datetime-local" name="scheduled_at" id="scheduled_at"
                               value="{{ old('scheduled_at') }}"
                               min="{{ now()->format('Y-m-d\TH:i') }}"
                               class="w-full px-4 py-3 bg-dark-50 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-primary-500 focus:border-transparent transition-all">
                        <p class="mt-1 text-xs text-gray-400">Laisser vide pour un envoi manuel</p>
                        @error('scheduled_at')
                            <p class="mt-1 text-sm text-red-400">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <!-- Info Box -->
                <div class="bg-blue-500/10 border border-blue-500/30 rounded-xl p-4">
                    <div class="flex items-start">
                        <i class="fas fa-info-circle text-blue-500 text-lg mt-0.5 mr-2"></i>
                        <div>
                            <h4 class="text-sm font-semibold text-blue-400 mb-2">Conseils</h4>
                            <ul class="text-xs text-blue-300/80 space-y-1">
                                <li>• Rédigez un message clair et concis</li>
                                <li>• Vérifiez le canal choisi</li>
                                <li>• Testez d'abord avec un utilisateur</li>
                                <li>• Programmez pour un envoi différé</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Push Notification Info -->
                <div x-show="channel === 'push'" x-cloak class="bg-orange-500/10 border border-orange-500/30 rounded-xl p-4">
                    <div class="flex items-start">
                        <i class="fas fa-bell text-orange-500 text-lg mt-0.5 mr-2"></i>
                        <div>
                            <h4 class="text-sm font-semibold text-orange-400 mb-2">Notification Push (FCM)</h4>
                            <ul class="text-xs text-orange-300/80 space-y-1">
                                <li>• Utilise Firebase Cloud Messaging</li>
                                <li>• Envoi via topics pour les groupes</li>
                                <li>• Envoi progressif par batch</li>
                                <li>• Gestion automatique des tokens invalides</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="mt-6 flex justify-between items-center">
            <a href="{{ route('admin.announcements.index') }}"
               class="px-6 py-3 bg-dark-300 text-white rounded-lg hover:bg-dark-400 transition-all shadow-md">
                <i class="fas fa-times mr-2"></i> Annuler
            </a>
            <div class="flex gap-3">
                <button type="submit"
                        class="px-8 py-3 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:from-primary-600 hover:to-primary-700 transition-all shadow-lg hover:shadow-xl">
                    <i class="fas fa-save mr-2"></i> Enregistrer le brouillon
                </button>
            </div>
        </div>
    </form>
</div>
@endsection
