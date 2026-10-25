@extends('admin.layouts.app')

@section('title', 'Services Externes')
@section('header', 'Configuration des Services')

@section('content')
<div class="space-y-6" x-data="{ activeService: 'nexaah' }">
    <!-- Service OTP par défaut -->
    <div class="bg-gradient-to-br from-blue-500/10 to-blue-600/5 rounded-xl shadow-lg border border-blue-500/20 p-6">
        <form action="{{ route('admin.settings.services.update') }}" method="POST">
            @csrf
            @method('PUT')
            <input type="hidden" name="service_type" value="otp_default">

            <div class="flex items-center justify-between">
                <div class="flex items-center">
                    <div class="w-16 h-16 bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl flex items-center justify-center mr-4 shadow-lg">
                        <i class="fas fa-shield-alt text-3xl text-white"></i>
                    </div>
                    <div>
                        <h3 class="text-2xl font-bold text-white">Service OTP par défaut</h3>
                        <p class="text-gray-400 mt-1">Choisissez le service utilisé par défaut pour l'envoi des codes OTP</p>
                    </div>
                </div>
            </div>

            <div class="mt-6 grid grid-cols-1 md:grid-cols-3 gap-4">
                @php
                    $defaultOtpService = $smsSettings['otp_default_service']->value ?? 'auto';
                @endphp

                <!-- Option Auto -->
                <label class="relative flex items-center p-4 border-2 rounded-lg cursor-pointer transition-all
                    {{ $defaultOtpService === 'auto' ? 'border-blue-500 bg-blue-500/10' : 'border-dark-300 hover:border-dark-200' }}">
                    <input type="radio" name="otp_default_service" value="auto"
                           {{ $defaultOtpService === 'auto' ? 'checked' : '' }}
                           class="sr-only peer">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center">
                            <i class="fas fa-magic text-white text-lg"></i>
                        </div>
                        <div>
                            <div class="font-semibold text-white">Automatique</div>
                            <div class="text-xs text-gray-400">WhatsApp prioritaire</div>
                        </div>
                    </div>
                    <div class="absolute top-2 right-2">
                        <i class="fas fa-check-circle text-blue-500 text-xl {{ $defaultOtpService === 'auto' ? '' : 'hidden' }}"></i>
                    </div>
                </label>

                <!-- Option WhatsApp -->
                <label class="relative flex items-center p-4 border-2 rounded-lg cursor-pointer transition-all
                    {{ $defaultOtpService === 'whatsapp' ? 'border-green-500 bg-green-500/10' : 'border-dark-300 hover:border-dark-200' }}">
                    <input type="radio" name="otp_default_service" value="whatsapp"
                           {{ $defaultOtpService === 'whatsapp' ? 'checked' : '' }}
                           class="sr-only peer">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-green-500 to-green-600 flex items-center justify-center">
                            <i class="fab fa-whatsapp text-white text-lg"></i>
                        </div>
                        <div>
                            <div class="font-semibold text-white">WhatsApp</div>
                            <div class="text-xs text-gray-400">Toujours WhatsApp</div>
                        </div>
                    </div>
                    <div class="absolute top-2 right-2">
                        <i class="fas fa-check-circle text-green-500 text-xl {{ $defaultOtpService === 'whatsapp' ? '' : 'hidden' }}"></i>
                    </div>
                </label>

                <!-- Option SMS -->
                <label class="relative flex items-center p-4 border-2 rounded-lg cursor-pointer transition-all
                    {{ $defaultOtpService === 'sms' ? 'border-purple-500 bg-purple-500/10' : 'border-dark-300 hover:border-dark-200' }}">
                    <input type="radio" name="otp_default_service" value="sms"
                           {{ $defaultOtpService === 'sms' ? 'checked' : '' }}
                           class="sr-only peer">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-purple-500 to-purple-600 flex items-center justify-center">
                            <i class="fas fa-sms text-white text-lg"></i>
                        </div>
                        <div>
                            <div class="font-semibold text-white">SMS</div>
                            <div class="text-xs text-gray-400">Toujours SMS</div>
                        </div>
                    </div>
                    <div class="absolute top-2 right-2">
                        <i class="fas fa-check-circle text-purple-500 text-xl {{ $defaultOtpService === 'sms' ? '' : 'hidden' }}"></i>
                    </div>
                </label>
            </div>

            <div class="mt-4 flex justify-end">
                <button type="submit"
                        class="px-6 py-2 bg-gradient-to-r from-blue-500 to-blue-600 text-white rounded-lg hover:from-blue-600 hover:to-blue-700 transition-all shadow-lg hover:shadow-xl">
                    <i class="fas fa-save mr-2"></i> Enregistrer le choix
                </button>
            </div>

            <div class="mt-4 bg-blue-500/10 border border-blue-500/30 rounded-lg p-4">
                <div class="flex items-start">
                    <i class="fas fa-info-circle text-blue-500 text-lg mr-3 mt-0.5"></i>
                    <div class="text-sm text-blue-300/80">
                        <strong>Comment ça fonctionne ?</strong>
                        <ul class="mt-2 space-y-1 list-disc list-inside">
                            <li><strong>Automatique :</strong> WhatsApp en priorité, puis SMS si WhatsApp échoue</li>
                            <li><strong>WhatsApp :</strong> Force l'utilisation de WhatsApp uniquement</li>
                            <li><strong>SMS :</strong> Force l'utilisation de SMS (Nexaah) uniquement</li>
                        </ul>
                        <p class="mt-2">Le service choisi doit être activé et correctement configuré ci-dessous.</p>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Sticky Tabs Navigation -->
    <div class="bg-dark-100 rounded-lg shadow-lg border border-dark-200 sticky top-0 z-10">
        <div class="border-b border-dark-200">
            <nav class="flex space-x-4 px-6" aria-label="Services Tabs">
                <button @click="activeService = 'nexaah'"
                        :class="activeService === 'nexaah' ? 'border-purple-500 text-purple-500' : 'border-transparent text-gray-400 hover:text-gray-300 hover:border-gray-300'"
                        class="py-4 px-1 border-b-2 font-medium text-sm transition-colors">
                    <i class="fas fa-sms mr-2"></i> Nexaah SMS
                </button>
                <button @click="activeService = 'whatsapp'"
                        :class="activeService === 'whatsapp' ? 'border-green-500 text-green-500' : 'border-transparent text-gray-400 hover:text-gray-300 hover:border-gray-300'"
                        class="py-4 px-1 border-b-2 font-medium text-sm transition-colors">
                    <i class="fab fa-whatsapp mr-2"></i> WhatsApp Business
                </button>
            </nav>
        </div>
    </div>

    <!-- Nexaah SMS Tab -->
    <div x-show="activeService === 'nexaah'" x-cloak>
        <form action="{{ route('admin.settings.services.update') }}" method="POST">
            @csrf
            @method('PUT')
            <input type="hidden" name="service_type" value="nexaah">

            <!-- Service Header Card -->
            <div class="bg-gradient-to-br from-purple-500/10 to-purple-600/5 rounded-xl shadow-lg border border-purple-500/20 p-6 mb-6">
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <div class="w-16 h-16 bg-gradient-to-br from-purple-500 to-purple-600 rounded-xl flex items-center justify-center mr-4 shadow-lg">
                            <i class="fas fa-sms text-3xl text-white"></i>
                        </div>
                        <div>
                            <h3 class="text-2xl font-bold text-white">Nexaah SMS</h3>
                            <p class="text-gray-400 mt-1">Service d'envoi de SMS en Afrique via l'API Nexaah</p>
                        </div>
                    </div>
                    <!-- Enable/Disable Toggle -->
                    <label class="relative inline-flex items-center cursor-pointer" x-data="{ enabled: {{ old('nexaah_enabled', $smsSettings['nexaah_enabled']->value ?? '0') == '1' ? 'true' : 'false' }} }">
                        <input type="checkbox" x-model="enabled" name="nexaah_enabled" value="1"
                               {{ old('nexaah_enabled', $smsSettings['nexaah_enabled']->value ?? '0') == '1' ? 'checked' : '' }}
                               class="sr-only peer">
                        <div class="w-16 h-8 bg-dark-300 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-purple-500/20 rounded-full peer peer-checked:after:translate-x-8 peer-checked:after:border-white after:content-[''] after:absolute after:top-0.5 after:left-[4px] after:bg-white after:rounded-full after:h-7 after:w-7 after:transition-all peer-checked:bg-gradient-to-r peer-checked:from-purple-500 peer-checked:to-purple-600 shadow-inner"></div>
                        <span class="ml-3 text-sm font-medium text-gray-300">
                            <span x-show="enabled" class="text-purple-400">Activé</span>
                            <span x-show="!enabled" class="text-gray-500">Désactivé</span>
                        </span>
                    </label>
                </div>
            </div>

            <!-- Configuration Card -->
            <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6 mb-6">
                <h4 class="text-lg font-semibold text-white mb-6 flex items-center">
                    <i class="fas fa-key text-purple-500 mr-2"></i>
                    Configuration Nexah API
                </h4>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- URL de base -->
                    <div class="md:col-span-2">
                        <label for="nexaah_base_url" class="block text-sm font-medium text-gray-300 mb-2">
                            <i class="fas fa-link text-purple-400 mr-1"></i> URL de base <span class="text-red-500">*</span>
                        </label>
                        <input type="url" name="nexaah_base_url" id="nexaah_base_url"
                               value="{{ old('nexaah_base_url', $smsSettings['nexaah_base_url']->value ?? 'https://smsvas.com/bulk/public/index.php/api/v1') }}"
                               class="w-full px-4 py-3 bg-dark-50 border border-dark-300 rounded-lg text-white placeholder-gray-500 focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all"
                               placeholder="https://smsvas.com/bulk/public/index.php/api/v1">
                        <p class="mt-1 text-xs text-gray-500">URL de base de l'API Nexah</p>
                    </div>

                    <!-- Endpoint d'envoi -->
                    <div>
                        <label for="nexaah_send_endpoint" class="block text-sm font-medium text-gray-300 mb-2">
                            <i class="fas fa-paper-plane text-purple-400 mr-1"></i> Endpoint d'envoi <span class="text-red-500">*</span>
                        </label>
                        <input type="text" name="nexaah_send_endpoint" id="nexaah_send_endpoint"
                               value="{{ old('nexaah_send_endpoint', $smsSettings['nexaah_send_endpoint']->value ?? '/sendsms') }}"
                               class="w-full px-4 py-3 bg-dark-50 border border-dark-300 rounded-lg text-white placeholder-gray-500 focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all"
                               placeholder="/sendsms">
                        <p class="mt-1 text-xs text-gray-500">Endpoint pour l'envoi de SMS</p>
                    </div>

                    <!-- Endpoint crédits -->
                    <div>
                        <label for="nexaah_credits_endpoint" class="block text-sm font-medium text-gray-300 mb-2">
                            <i class="fas fa-coins text-purple-400 mr-1"></i> Endpoint crédits <span class="text-red-500">*</span>
                        </label>
                        <input type="text" name="nexaah_credits_endpoint" id="nexaah_credits_endpoint"
                               value="{{ old('nexaah_credits_endpoint', $smsSettings['nexaah_credits_endpoint']->value ?? '/smscredit') }}"
                               class="w-full px-4 py-3 bg-dark-50 border border-dark-300 rounded-lg text-white placeholder-gray-500 focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all"
                               placeholder="/smscredit">
                        <p class="mt-1 text-xs text-gray-500">Endpoint pour vérifier les crédits</p>
                    </div>

                    <!-- Utilisateur (email) -->
                    <div>
                        <label for="nexaah_user" class="block text-sm font-medium text-gray-300 mb-2">
                            <i class="fas fa-user text-purple-400 mr-1"></i> Utilisateur <span class="text-red-500">*</span>
                        </label>
                        <input type="text" name="nexaah_user" id="nexaah_user"
                               value="{{ old('nexaah_user', $smsSettings['nexaah_user']->value ?? '') }}"
                               class="w-full px-4 py-3 bg-dark-50 border border-dark-300 rounded-lg text-white placeholder-gray-500 focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all"
                               placeholder="steve.boussa@outlook.com">
                        <p class="mt-1 text-xs text-gray-500">Email de votre compte Nexah</p>
                    </div>

                    <!-- Mot de passe -->
                    <div>
                        <label for="nexaah_password" class="block text-sm font-medium text-gray-300 mb-2">
                            <i class="fas fa-lock text-purple-400 mr-1"></i> Mot de passe <span class="text-red-500">*</span>
                        </label>
                        <div class="relative">
                            <input type="password" name="nexaah_password" id="nexaah_password"
                                   value="{{ old('nexaah_password', $smsSettings['nexaah_password']->value ?? '') }}"
                                   class="w-full px-4 py-3 bg-dark-50 border border-dark-300 rounded-lg text-white placeholder-gray-500 focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all"
                                   placeholder="••••••••">
                            <button type="button" onclick="togglePassword('nexaah_password')"
                                    class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-white">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <p class="mt-1 text-xs text-gray-500">Mot de passe de votre compte Nexah</p>
                    </div>

                    <!-- Sender ID -->
                    <div class="md:col-span-2">
                        <label for="nexaah_sender_id" class="block text-sm font-medium text-gray-300 mb-2">
                            <i class="fas fa-signature text-purple-400 mr-1"></i> Sender ID <span class="text-red-500">*</span>
                        </label>
                        <input type="text" name="nexaah_sender_id" id="nexaah_sender_id"
                               value="{{ old('nexaah_sender_id', $smsSettings['nexaah_sender_id']->value ?? 'ASSO') }}"
                               maxlength="11"
                               class="w-full px-4 py-3 bg-dark-50 border border-dark-300 rounded-lg text-white placeholder-gray-500 focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all"
                               placeholder="ASSO">
                        <p class="mt-1 text-xs text-gray-500">Nom affiché comme expéditeur (max 11 caractères alphanumériques)</p>
                    </div>
                </div>
            </div>

            <!-- Info Box -->
            <div class="bg-purple-500/10 border border-purple-500/30 rounded-xl p-6 mb-6">
                <div class="flex items-start">
                    <div class="flex-shrink-0">
                        <i class="fas fa-info-circle text-purple-500 text-2xl mt-1"></i>
                    </div>
                    <div class="ml-4">
                        <h4 class="text-base font-semibold text-purple-400 mb-2">Comment configurer Nexah SMS ?</h4>
                        <ul class="text-sm text-purple-300/80 space-y-2">
                            <li class="flex items-start">
                                <i class="fas fa-check-circle text-purple-500 mr-2 mt-0.5"></i>
                                <span>Contactez Nexah pour créer un compte SMS</span>
                            </li>
                            <li class="flex items-start">
                                <i class="fas fa-check-circle text-purple-500 mr-2 mt-0.5"></i>
                                <span>Récupérez vos identifiants (email et mot de passe)</span>
                            </li>
                            <li class="flex items-start">
                                <i class="fas fa-check-circle text-purple-500 mr-2 mt-0.5"></i>
                                <span>Renseignez l'URL de base et les endpoints fournis</span>
                            </li>
                            <li class="flex items-start">
                                <i class="fas fa-check-circle text-purple-500 mr-2 mt-0.5"></i>
                                <span>Configurez votre Sender ID et rechargez votre compte</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Submit Buttons -->
            <div class="flex justify-between items-center">
                <a href="{{ route('admin.settings.index') }}"
                   class="px-6 py-3 bg-dark-300 text-white rounded-lg hover:bg-dark-400 transition-all shadow-md">
                    <i class="fas fa-arrow-left mr-2"></i> Retour
                </a>
                <button type="submit"
                        class="px-8 py-3 bg-gradient-to-r from-purple-500 to-purple-600 text-white rounded-lg hover:from-purple-600 hover:to-purple-700 transition-all shadow-lg hover:shadow-xl">
                    <i class="fas fa-save mr-2"></i> Enregistrer la configuration
                </button>
            </div>
        </form>
    </div>

    <!-- WhatsApp Business Tab -->
    <div x-show="activeService === 'whatsapp'" x-cloak>
        <form action="{{ route('admin.settings.services.update') }}" method="POST">
            @csrf
            @method('PUT')
            <input type="hidden" name="service_type" value="whatsapp">

            <!-- Service Header Card -->
            <div class="bg-gradient-to-br from-green-500/10 to-green-600/5 rounded-xl shadow-lg border border-green-500/20 p-6 mb-6">
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <div class="w-16 h-16 bg-gradient-to-br from-green-500 to-green-600 rounded-xl flex items-center justify-center mr-4 shadow-lg">
                            <i class="fab fa-whatsapp text-3xl text-white"></i>
                        </div>
                        <div>
                            <h3 class="text-2xl font-bold text-white">WhatsApp Business API</h3>
                            <p class="text-gray-400 mt-1">Envoyez des messages via l'API officielle WhatsApp Business</p>
                        </div>
                    </div>
                    <!-- Enable/Disable Toggle -->
                    <label class="relative inline-flex items-center cursor-pointer" x-data="{ enabled: {{ old('whatsapp_enabled', $whatsappSettings['whatsapp_enabled']->value ?? '0') == '1' ? 'true' : 'false' }} }">
                        <input type="checkbox" x-model="enabled" name="whatsapp_enabled" value="1"
                               {{ old('whatsapp_enabled', $whatsappSettings['whatsapp_enabled']->value ?? '0') == '1' ? 'checked' : '' }}
                               class="sr-only peer">
                        <div class="w-16 h-8 bg-dark-300 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-green-500/20 rounded-full peer peer-checked:after:translate-x-8 peer-checked:after:border-white after:content-[''] after:absolute after:top-0.5 after:left-[4px] after:bg-white after:rounded-full after:h-7 after:w-7 after:transition-all peer-checked:bg-gradient-to-r peer-checked:from-green-500 peer-checked:to-green-600 shadow-inner"></div>
                        <span class="ml-3 text-sm font-medium text-gray-300">
                            <span x-show="enabled" class="text-green-400">Activé</span>
                            <span x-show="!enabled" class="text-gray-500">Désactivé</span>
                        </span>
                    </label>
                </div>
            </div>

            <!-- Configuration Card -->
            <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6 mb-6">
                <h4 class="text-lg font-semibold text-white mb-6 flex items-center">
                    <i class="fas fa-key text-green-500 mr-2"></i>
                    Configuration WhatsApp API
                </h4>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- API Token -->
                    <div class="md:col-span-2">
                        <label for="whatsapp_api_token" class="block text-sm font-medium text-gray-300 mb-2">
                            <i class="fas fa-key text-green-400 mr-1"></i> API Token <span class="text-red-500">*</span>
                        </label>
                        <div class="relative">
                            <input type="password" name="whatsapp_api_token" id="whatsapp_api_token"
                                   value="{{ old('whatsapp_api_token', $whatsappSettings['whatsapp_api_token']->value ?? '') }}"
                                   class="w-full px-4 py-3 bg-dark-50 border border-dark-300 rounded-lg text-white placeholder-gray-500 focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all"
                                   placeholder="EAAKKZAdFNYMIBO0hDRveRPtiyxXUUn17odxTOdKKqOErdNqZCyUolMWrTXY2ZAsNw9BmZAON1NjqQalUExLXbfazZBO9X0HnPvQ0v3lG7xLEyDuiWZC8ZCcu5QGY7FYlZA6ynaG4el8gekN1fY3C0zQRZAzDbTmkRSM2IVoiv7vdPPMy7yAB8RdDirnFoMSZAENwZDZD">
                            <button type="button" onclick="togglePassword('whatsapp_api_token')"
                                    class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-white">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <p class="mt-1 text-xs text-gray-500">Token d'accès depuis Meta Business Suite</p>
                    </div>

                    <!-- Phone Number ID -->
                    <div class="md:col-span-2">
                        <label for="whatsapp_phone_number_id" class="block text-sm font-medium text-gray-300 mb-2">
                            <i class="fas fa-phone text-green-400 mr-1"></i> Phone Number ID <span class="text-red-500">*</span>
                        </label>
                        <input type="text" name="whatsapp_phone_number_id" id="whatsapp_phone_number_id"
                               value="{{ old('whatsapp_phone_number_id', $whatsappSettings['whatsapp_phone_number_id']->value ?? '') }}"
                               class="w-full px-4 py-3 bg-dark-50 border border-dark-300 rounded-lg text-white placeholder-gray-500 focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all"
                               placeholder="672988359234155">
                        <p class="mt-1 text-xs text-gray-500">ID du numéro de téléphone WhatsApp Business</p>
                    </div>

                    <!-- API Version -->
                    <div>
                        <label for="whatsapp_api_version" class="block text-sm font-medium text-gray-300 mb-2">
                            <i class="fas fa-code-branch text-green-400 mr-1"></i> Version API <span class="text-red-500">*</span>
                        </label>
                        <input type="text" name="whatsapp_api_version" id="whatsapp_api_version"
                               value="{{ old('whatsapp_api_version', $whatsappSettings['whatsapp_api_version']->value ?? 'v22.0') }}"
                               class="w-full px-4 py-3 bg-dark-50 border border-dark-300 rounded-lg text-white placeholder-gray-500 focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all"
                               placeholder="v22.0">
                    </div>

                    <!-- Template Language -->
                    <div>
                        <label for="whatsapp_template_language" class="block text-sm font-medium text-gray-300 mb-2">
                            <i class="fas fa-language text-green-400 mr-1"></i> Langue du template <span class="text-red-500">*</span>
                        </label>
                        <input type="text" name="whatsapp_template_language" id="whatsapp_template_language"
                               value="{{ old('whatsapp_template_language', $whatsappSettings['whatsapp_template_language']->value ?? 'fr') }}"
                               class="w-full px-4 py-3 bg-dark-50 border border-dark-300 rounded-lg text-white placeholder-gray-500 focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all"
                               placeholder="fr">
                        <p class="mt-1 text-xs text-gray-500">Ex: fr, en, fr_FR, en_US</p>
                    </div>

                    <!-- Template Name -->
                    <div class="md:col-span-2">
                        <label for="whatsapp_template_name" class="block text-sm font-medium text-gray-300 mb-2">
                            <i class="fas fa-file-alt text-green-400 mr-1"></i> Nom du template <span class="text-red-500">*</span>
                        </label>
                        <input type="text" name="whatsapp_template_name" id="whatsapp_template_name"
                               value="{{ old('whatsapp_template_name', $whatsappSettings['whatsapp_template_name']->value ?? '') }}"
                               class="w-full px-4 py-3 bg-dark-50 border border-dark-300 rounded-lg text-white placeholder-gray-500 focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all"
                               placeholder="ngoma_auth_fr">
                        <p class="mt-1 text-xs text-gray-500">Nom du template approuvé dans WhatsApp Business Manager</p>
                    </div>
                </div>
            </div>

            <!-- Info Box -->
            <div class="bg-green-500/10 border border-green-500/30 rounded-xl p-6 mb-6">
                <div class="flex items-start">
                    <div class="flex-shrink-0">
                        <i class="fas fa-info-circle text-green-500 text-2xl mt-1"></i>
                    </div>
                    <div class="ml-4">
                        <h4 class="text-base font-semibold text-green-400 mb-2">Aide</h4>
                        <p class="text-sm text-green-300/80 mb-3"><strong>Configuration requise:</strong></p>
                        <ul class="text-sm text-green-300/80 space-y-2">
                            <li class="flex items-start">
                                <i class="fas fa-check-circle text-green-500 mr-2 mt-0.5"></i>
                                <span>Compte Meta Business</span>
                            </li>
                            <li class="flex items-start">
                                <i class="fas fa-check-circle text-green-500 mr-2 mt-0.5"></i>
                                <span>WhatsApp Business API</span>
                            </li>
                            <li class="flex items-start">
                                <i class="fas fa-check-circle text-green-500 mr-2 mt-0.5"></i>
                                <span>Template de message approuvé</span>
                            </li>
                            <li class="flex items-start">
                                <i class="fas fa-check-circle text-green-500 mr-2 mt-0.5"></i>
                                <span>Numéro WhatsApp vérifié</span>
                            </li>
                        </ul>
                        <hr class="my-3 border-green-500/20">
                        <p class="text-sm text-green-300/80 mb-2"><strong>Où trouver ces informations:</strong></p>
                        <ol class="text-sm text-green-300/80 space-y-1 list-decimal list-inside">
                            <li>Connectez-vous à <a href="https://business.facebook.com" target="_blank" class="underline hover:text-green-300">Meta Business Suite</a></li>
                            <li>Accédez à WhatsApp Manager</li>
                            <li>Sélectionnez votre compte WhatsApp Business</li>
                            <li><strong>API Token:</strong> Paramètres → API Token</li>
                            <li><strong>Phone Number ID:</strong> Numéros de téléphone</li>
                        </ol>
                    </div>
                </div>
            </div>

            <!-- Submit Buttons -->
            <div class="flex justify-between items-center">
                <a href="{{ route('admin.settings.index') }}"
                   class="px-6 py-3 bg-dark-300 text-white rounded-lg hover:bg-dark-400 transition-all shadow-md">
                    <i class="fas fa-arrow-left mr-2"></i> Retour
                </a>
                <button type="submit"
                        class="px-8 py-3 bg-gradient-to-r from-green-500 to-green-600 text-white rounded-lg hover:from-green-600 hover:to-green-700 transition-all shadow-lg hover:shadow-xl">
                    <i class="fas fa-save mr-2"></i> Enregistrer la configuration
                </button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
function togglePassword(fieldId) {
    const field = document.getElementById(fieldId);
    const icon = event.target.closest('button').querySelector('i');

    if (field.type === 'password') {
        field.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        field.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}
</script>
@endpush
@endsection
