@extends('admin.layouts.app')

@section('title', 'Paramètres de Paiement')
@section('header', 'Configuration des Paiements')

@section('content')
<div class="space-y-6" x-data="{ activePayment: 'paypal' }">
    <!-- Sticky Tabs Navigation -->
    <div class="bg-dark-100 rounded-lg shadow-lg border border-dark-200 sticky top-0 z-10">
        <div class="border-b border-dark-200">
            <nav class="flex space-x-4 px-6" aria-label="Payment Tabs">
                <button @click="activePayment = 'paypal'"
                        :class="activePayment === 'paypal' ? 'border-blue-500 text-blue-500' : 'border-transparent text-gray-400 hover:text-gray-300 hover:border-gray-300'"
                        class="py-4 px-1 border-b-2 font-medium text-sm transition-colors">
                    <i class="fab fa-paypal mr-2"></i> PayPal
                </button>
                <button @click="activePayment = 'kpay'"
                        :class="activePayment === 'kpay' ? 'border-purple-500 text-purple-500' : 'border-transparent text-gray-400 hover:text-gray-300 hover:border-gray-300'"
                        class="py-4 px-1 border-b-2 font-medium text-sm transition-colors">
                    <i class="fas fa-mobile-alt mr-2"></i> KPay
                </button>
            </nav>
        </div>
    </div>

    <!-- PayPal Tab -->
    <div x-show="activePayment === 'paypal'" x-cloak>
        <form action="{{ route('admin.settings.payments.update') }}" method="POST">
            @csrf
            @method('PUT')
            <input type="hidden" name="payment_type" value="paypal">

            <!-- Service Header Card -->
            <div class="bg-gradient-to-br from-blue-500/10 to-blue-600/5 rounded-xl shadow-lg border border-blue-500/20 p-6 mb-6">
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <div class="w-16 h-16 bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl flex items-center justify-center mr-4 shadow-lg">
                            <i class="fab fa-paypal text-3xl text-white"></i>
                        </div>
                        <div>
                            <h3 class="text-2xl font-bold text-white">PayPal</h3>
                            <p class="text-gray-400 mt-1">Acceptez les paiements via PayPal dans le monde entier</p>
                        </div>
                    </div>
                    <!-- Enable/Disable Toggle -->
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="paypal_enabled" value="1"
                               {{ old('paypal_enabled', $paymentSettings['paypal_enabled']->value ?? '0') == '1' ? 'checked' : '' }}
                               class="sr-only peer">
                        <div class="w-16 h-8 bg-dark-300 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-500/20 rounded-full peer peer-checked:after:translate-x-8 peer-checked:after:border-white after:content-[''] after:absolute after:top-0.5 after:left-[4px] after:bg-white after:rounded-full after:h-7 after:w-7 after:transition-all peer-checked:bg-gradient-to-r peer-checked:from-blue-500 peer-checked:to-blue-600 shadow-inner"></div>
                        <span class="ml-3 text-sm font-medium text-gray-300">
                            <span x-show="$el.previousElementSibling.querySelector('input').checked" class="text-blue-400">Activé</span>
                            <span x-show="!$el.previousElementSibling.querySelector('input').checked" class="text-gray-500">Désactivé</span>
                        </span>
                    </label>
                </div>
            </div>

            <!-- Important Notice -->
            <div class="bg-blue-500/10 border border-blue-500/30 rounded-xl p-4 mb-6">
                <div class="flex items-start">
                    <div class="flex-shrink-0">
                        <i class="fas fa-info-circle text-blue-500 text-xl"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm text-blue-300">
                            <strong>Important:</strong> PayPal accepte les paiements par carte bancaire (Visa, MasterCard, Amex) sans compte PayPal requis.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Configuration Card -->
            <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6 mb-6">
                <h4 class="text-lg font-semibold text-white mb-6 flex items-center">
                    <i class="fas fa-cog text-blue-500 mr-2"></i>
                    Configuration de l'API PayPal
                </h4>

                <div class="space-y-6">
                    <!-- Environment Mode -->
                    <div>
                        <label for="paypal_mode" class="block text-sm font-medium text-gray-300 mb-2">
                            <i class="fas fa-server text-blue-400 mr-1"></i> Mode d'exécution <span class="text-red-500">*</span>
                        </label>
                        <select name="paypal_mode" id="paypal_mode"
                                class="w-full px-4 py-3 bg-dark-50 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all"
                                required>
                            <option value="sandbox" {{ old('paypal_mode', $paymentSettings['paypal_mode']->value ?? 'sandbox') == 'sandbox' ? 'selected' : '' }}>Sandbox (Test)</option>
                            <option value="live" {{ old('paypal_mode', $paymentSettings['paypal_mode']->value ?? '') == 'live' ? 'selected' : '' }}>Live (Production)</option>
                        </select>
                        <p class="mt-1 text-xs text-gray-500">Utilisez "Sandbox" pour les tests, "Live" pour la production</p>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Client ID -->
                        <div>
                            <label for="paypal_client_id" class="block text-sm font-medium text-gray-300 mb-2">
                                <i class="fas fa-key text-blue-400 mr-1"></i> Client ID <span class="text-red-500">*</span>
                            </label>
                            <div class="relative">
                                <input type="text" name="paypal_client_id" id="paypal_client_id"
                                       value="{{ old('paypal_client_id', $paymentSettings['paypal_client_id']->value ?? '') }}"
                                       class="w-full px-4 py-3 bg-dark-50 border border-dark-300 rounded-lg text-white placeholder-gray-500 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all"
                                       placeholder="Entrez votre PayPal Client ID">
                            </div>
                        </div>

                        <!-- Client Secret -->
                        <div>
                            <label for="paypal_client_secret" class="block text-sm font-medium text-gray-300 mb-2">
                                <i class="fas fa-lock text-blue-400 mr-1"></i> Client Secret <span class="text-red-500">*</span>
                            </label>
                            <div class="relative">
                                <input type="password" name="paypal_client_secret" id="paypal_client_secret"
                                       value="{{ old('paypal_client_secret', $paymentSettings['paypal_client_secret']->value ?? '') }}"
                                       class="w-full px-4 py-3 bg-dark-50 border border-dark-300 rounded-lg text-white placeholder-gray-500 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all"
                                       placeholder="Entrez votre PayPal Client Secret">
                                <button type="button" onclick="togglePassword('paypal_client_secret')"
                                        class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-white">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Currency -->
                        <div class="md:col-span-2">
                            <label for="paypal_currency" class="block text-sm font-medium text-gray-300 mb-2">
                                <i class="fas fa-dollar-sign text-blue-400 mr-1"></i> Devise <span class="text-red-500">*</span>
                            </label>
                            <select name="paypal_currency" id="paypal_currency"
                                    class="w-full px-4 py-3 bg-dark-50 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all">
                                <option value="USD" {{ old('paypal_currency', $paymentSettings['paypal_currency']->value ?? 'USD') == 'USD' ? 'selected' : '' }}>USD - Dollar américain</option>
                                <option value="EUR" {{ old('paypal_currency', $paymentSettings['paypal_currency']->value ?? '') == 'EUR' ? 'selected' : '' }}>EUR - Euro</option>
                                <option value="GBP" {{ old('paypal_currency', $paymentSettings['paypal_currency']->value ?? '') == 'GBP' ? 'selected' : '' }}>GBP - Livre Sterling</option>
                            </select>
                            <p class="mt-1 text-xs text-gray-500">Devise utilisée pour tous les paiements PayPal</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Help Box -->
            <div class="bg-blue-500/10 border border-blue-500/30 rounded-xl p-6 mb-6">
                <div class="flex items-start">
                    <div class="flex-shrink-0">
                        <i class="fas fa-question-circle text-blue-500 text-2xl mt-1"></i>
                    </div>
                    <div class="ml-4">
                        <h4 class="text-base font-semibold text-blue-400 mb-3">Aide PayPal</h4>

                        <div class="mb-3">
                            <p class="text-sm font-medium text-blue-300 mb-2">Configuration requise:</p>
                            <ul class="text-sm text-blue-300/80 space-y-1 ml-4">
                                <li>• Compte PayPal Business ou Developer</li>
                                <li>• Client ID et Secret</li>
                                <li>• URLs de retour et annulation</li>
                            </ul>
                        </div>

                        <div class="mb-3">
                            <p class="text-sm font-medium text-blue-300 mb-2">Où trouver vos credentials:</p>
                            <ul class="text-sm text-blue-300/80 space-y-1 ml-4">
                                <li>• Connectez-vous au <a href="https://developer.paypal.com" target="_blank" class="underline hover:text-blue-300">PayPal Developer Dashboard</a></li>
                                <li>• Accédez à "My Apps & Credentials"</li>
                                <li>• Créez une app ou sélectionnez-en une existante</li>
                                <li>• Copiez le Client ID et Secret</li>
                            </ul>
                        </div>

                        <div class="mb-3">
                            <p class="text-sm font-medium text-blue-300 mb-2">Modes disponibles:</p>
                            <ul class="text-sm text-blue-300/80 space-y-1 ml-4">
                                <li>• <strong>Sandbox:</strong> Pour les tests (utilise des credentials de test)</li>
                                <li>• <strong>Live:</strong> Pour la production (transactions réelles)</li>
                            </ul>
                        </div>

                        <div>
                            <p class="text-sm font-medium text-blue-300 mb-2">Méthodes de paiement acceptées:</p>
                            <ul class="text-sm text-blue-300/80 space-y-1 ml-4">
                                <li>• Compte PayPal</li>
                                <li>• Visa, MasterCard, American Express</li>
                                <li>• Cartes de débit</li>
                                <li>• Discover (selon la région)</li>
                            </ul>
                        </div>

                        <div class="mt-3 p-3 bg-blue-500/20 rounded-lg">
                            <p class="text-sm text-blue-200">
                                <strong>Note:</strong> Les clients peuvent payer par carte bancaire SANS avoir de compte PayPal.
                            </p>
                        </div>
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
                        class="px-8 py-3 bg-gradient-to-r from-blue-500 to-blue-600 text-white rounded-lg hover:from-blue-600 hover:to-blue-700 transition-all shadow-lg hover:shadow-xl">
                    <i class="fas fa-save mr-2"></i> Enregistrer la configuration
                </button>
            </div>
        </form>
    </div>


    <!-- KPay Tab -->
    <div x-show="activePayment === 'kpay'" x-cloak>
        <form action="{{ route('admin.settings.payments.update') }}" method="POST">
            @csrf
            @method('PUT')
            <input type="hidden" name="payment_type" value="kpay">

            <!-- Service Header Card -->
            <div class="bg-gradient-to-br from-purple-500/10 to-purple-600/5 rounded-xl shadow-lg border border-purple-500/20 p-6 mb-6">
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <div class="w-16 h-16 bg-gradient-to-br from-purple-500 to-purple-600 rounded-xl flex items-center justify-center mr-4 shadow-lg">
                            <i class="fas fa-mobile-alt text-3xl text-white"></i>
                        </div>
                        <div>
                            <h3 class="text-2xl font-bold text-white">KPay</h3>
                            <p class="text-gray-400 mt-1">Solution de paiement mobile money pour l'Afrique</p>
                        </div>
                    </div>
                    <!-- Enable/Disable Toggle -->
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="kpay_enabled" value="1"
                               {{ old('kpay_enabled', $paymentSettings['kpay_enabled']->value ?? '0') == '1' ? 'checked' : '' }}
                               class="sr-only peer">
                        <div class="w-16 h-8 bg-dark-300 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-purple-500/20 rounded-full peer peer-checked:after:translate-x-8 peer-checked:after:border-white after:content-[''] after:absolute after:top-0.5 after:left-[4px] after:bg-white after:rounded-full after:h-7 after:w-7 after:transition-all peer-checked:bg-gradient-to-r peer-checked:from-purple-500 peer-checked:to-purple-600 shadow-inner"></div>
                        <span class="ml-3 text-sm font-medium text-gray-300">
                            <span x-show="$el.previousElementSibling.querySelector('input').checked" class="text-purple-400">Activé</span>
                            <span x-show="!$el.previousElementSibling.querySelector('input').checked" class="text-gray-500">Désactivé</span>
                        </span>
                    </label>
                </div>
            </div>

            <!-- Important Notice -->
            <div class="bg-purple-500/10 border border-purple-500/30 rounded-xl p-4 mb-6">
                <div class="flex items-start">
                    <div class="flex-shrink-0">
                        <i class="fas fa-info-circle text-purple-500 text-xl"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm text-purple-300">
                            <strong>Important:</strong> KPay utilise l'API v2 avec authentification Bearer Token.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Configuration Card -->
            <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6 mb-6">
                <h4 class="text-lg font-semibold text-white mb-6 flex items-center">
                    <i class="fas fa-cog text-purple-500 mr-2"></i>
                    Configuration de l'API KPay
                </h4>

                <div class="space-y-6">
                    <!-- URL de base -->
                    <div>
                        <label for="kpay_base_url" class="block text-sm font-medium text-gray-300 mb-2">
                            <i class="fas fa-globe text-purple-400 mr-1"></i> URL de base <span class="text-red-500">*</span>
                        </label>
                        <input type="url" name="kpay_base_url" id="kpay_base_url"
                               value="{{ old('kpay_base_url', $paymentSettings['kpay_base_url']->value ?? 'https://api-v2.kpay.com') }}"
                               class="w-full px-4 py-3 bg-dark-50 border border-dark-300 rounded-lg text-white placeholder-gray-500 focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all"
                               placeholder="https://api-v2.kpay.com">
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- App Key -->
                        <div>
                            <label for="kpay_app_key" class="block text-sm font-medium text-gray-300 mb-2">
                                <i class="fas fa-key text-purple-400 mr-1"></i> App Key <span class="text-red-500">*</span>
                            </label>
                            <div class="relative">
                                <input type="text" name="kpay_app_key" id="kpay_app_key"
                                       value="{{ old('kpay_app_key', $paymentSettings['kpay_app_key']->value ?? '') }}"
                                       class="w-full px-4 py-3 bg-dark-50 border border-dark-300 rounded-lg text-white placeholder-gray-500 focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all"
                                       placeholder="Entrez votre KPay App Key">
                            </div>
                        </div>

                        <!-- Secret Key -->
                        <div>
                            <label for="kpay_secret_key" class="block text-sm font-medium text-gray-300 mb-2">
                                <i class="fas fa-lock text-purple-400 mr-1"></i> Secret Key <span class="text-red-500">*</span>
                            </label>
                            <div class="relative">
                                <input type="password" name="kpay_secret_key" id="kpay_secret_key"
                                       value="{{ old('kpay_secret_key', $paymentSettings['kpay_secret_key']->value ?? '') }}"
                                       class="w-full px-4 py-3 bg-dark-50 border border-dark-300 rounded-lg text-white placeholder-gray-500 focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all"
                                       placeholder="Entrez votre KPay Secret Key">
                                <button type="button" onclick="togglePassword('kpay_secret_key')"
                                        class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-white">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Callback URL -->
                        <div class="md:col-span-2">
                            <label for="kpay_callback_url" class="block text-sm font-medium text-gray-300 mb-2">
                                <i class="fas fa-link text-purple-400 mr-1"></i> Callback URL <span class="text-red-500">*</span>
                            </label>
                            <input type="url" name="kpay_callback_url" id="kpay_callback_url"
                                   value="{{ old('kpay_callback_url', $paymentSettings['kpay_callback_url']->value ?? url('/api/webhooks/kpay')) }}"
                                   class="w-full px-4 py-3 bg-dark-50 border border-dark-300 rounded-lg text-white placeholder-gray-500 focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all"
                                   placeholder="https://votre-site.com/api/webhooks/kpay">
                            <p class="mt-1 text-xs text-gray-500">URL publique pour recevoir les notifications de paiement (doit être accessible depuis Internet)</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Advanced Settings -->
            <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6 mb-6">
                <h4 class="text-lg font-semibold text-white mb-6 flex items-center">
                    <i class="fas fa-sliders-h text-purple-500 mr-2"></i>
                    Paramètres avancés
                </h4>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <!-- Timeout init paiement -->
                    <div>
                        <label for="kpay_timeout_init" class="block text-sm font-medium text-gray-300 mb-2">
                            Timeout init paiement (s)
                        </label>
                        <input type="number" name="kpay_timeout_init" id="kpay_timeout_init"
                               value="{{ old('kpay_timeout_init', $paymentSettings['kpay_timeout_init']->value ?? '30') }}"
                               min="1" max="120"
                               class="w-full px-4 py-3 bg-dark-50 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all">
                    </div>

                    <!-- Timeout vérif statut -->
                    <div>
                        <label for="kpay_timeout_verify" class="block text-sm font-medium text-gray-300 mb-2">
                            Timeout vérif statut (s)
                        </label>
                        <input type="number" name="kpay_timeout_verify" id="kpay_timeout_verify"
                               value="{{ old('kpay_timeout_verify', $paymentSettings['kpay_timeout_verify']->value ?? '30') }}"
                               min="1" max="120"
                               class="w-full px-4 py-3 bg-dark-50 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all">
                    </div>

                    <!-- Timeout token -->
                    <div>
                        <label for="kpay_timeout_token" class="block text-sm font-medium text-gray-300 mb-2">
                            Timeout token (s)
                        </label>
                        <input type="number" name="kpay_timeout_token" id="kpay_timeout_token"
                               value="{{ old('kpay_timeout_token', $paymentSettings['kpay_timeout_token']->value ?? '30') }}"
                               min="1" max="120"
                               class="w-full px-4 py-3 bg-dark-50 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all">
                    </div>

                    <!-- Durée cache token -->
                    <div>
                        <label for="kpay_token_cache_duration" class="block text-sm font-medium text-gray-300 mb-2">
                            Durée cache token (s)
                        </label>
                        <input type="number" name="kpay_token_cache_duration" id="kpay_token_cache_duration"
                               value="{{ old('kpay_token_cache_duration', $paymentSettings['kpay_token_cache_duration']->value ?? '3000') }}"
                               min="60" max="3600"
                               class="w-full px-4 py-3 bg-dark-50 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all">
                        <p class="mt-1 text-xs text-gray-500">3000s = 50 min (token expire à 60 min)</p>
                    </div>

                    <!-- Nombre de tentatives -->
                    <div>
                        <label for="kpay_retry_attempts" class="block text-sm font-medium text-gray-300 mb-2">
                            Nombre de tentatives
                        </label>
                        <input type="number" name="kpay_retry_attempts" id="kpay_retry_attempts"
                               value="{{ old('kpay_retry_attempts', $paymentSettings['kpay_retry_attempts']->value ?? '5') }}"
                               min="1" max="10"
                               class="w-full px-4 py-3 bg-dark-50 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all">
                    </div>

                    <!-- Délai entre tentatives -->
                    <div>
                        <label for="kpay_retry_delay" class="block text-sm font-medium text-gray-300 mb-2">
                            Délai entre tentatives (s)
                        </label>
                        <input type="text" name="kpay_retry_delay" id="kpay_retry_delay"
                               value="{{ old('kpay_retry_delay', $paymentSettings['kpay_retry_delay']->value ?? '0.5') }}"
                               class="w-full px-4 py-3 bg-dark-50 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all">
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
                        <h4 class="text-base font-semibold text-purple-400 mb-2">Comment configurer KPay ?</h4>
                        <ul class="text-sm text-purple-300/80 space-y-2">
                            <li class="flex items-start">
                                <i class="fas fa-check-circle text-purple-500 mr-2 mt-0.5"></i>
                                <span>Créez un compte sur <a href="https://kpay.com" target="_blank" class="underline hover:text-purple-300">kpay.com</a></span>
                            </li>
                            <li class="flex items-start">
                                <i class="fas fa-check-circle text-purple-500 mr-2 mt-0.5"></i>
                                <span>Accédez à votre tableau de bord et récupérez vos clés API (App Key et Secret Key)</span>
                            </li>
                            <li class="flex items-start">
                                <i class="fas fa-check-circle text-purple-500 mr-2 mt-0.5"></i>
                                <span>Configurez les webhooks pour recevoir les notifications de paiement</span>
                            </li>
                            <li class="flex items-start">
                                <i class="fas fa-check-circle text-purple-500 mr-2 mt-0.5"></i>
                                <span>Testez en mode Sandbox avant de passer en production</span>
                            </li>
                            <li class="flex items-start">
                                <i class="fas fa-check-circle text-purple-500 mr-2 mt-0.5"></i>
                                <span>Assurez-vous que votre URL de callback est correctement configurée</span>
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
