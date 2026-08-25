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
                <button @click="activePayment = 'direct'"
                        :class="activePayment === 'direct' ? 'border-emerald-500 text-emerald-500' : 'border-transparent text-gray-400 hover:text-gray-300 hover:border-gray-300'"
                        class="py-4 px-1 border-b-2 font-medium text-sm transition-colors">
                    <i class="fas fa-credit-card mr-2"></i> Encaissement direct
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
                               {{ old('kpay_enabled', ($kpayEnabled ?? false) ? '1' : '0') == '1' ? 'checked' : '' }}
                               class="sr-only peer">
                        <div class="w-16 h-8 bg-dark-300 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-purple-500/20 rounded-full peer peer-checked:after:translate-x-8 peer-checked:after:border-white after:content-[''] after:absolute after:top-0.5 after:left-[4px] after:bg-white after:rounded-full after:h-7 after:w-7 after:transition-all peer-checked:bg-gradient-to-r peer-checked:from-purple-500 peer-checked:to-purple-600 shadow-inner"></div>
                        <span class="ml-3 text-sm font-medium text-gray-300">
                            <span x-show="$el.previousElementSibling.querySelector('input').checked" class="text-purple-400">Activé</span>
                            <span x-show="!$el.previousElementSibling.querySelector('input').checked" class="text-gray-500">Désactivé</span>
                        </span>
                    </label>
                </div>
            </div>

            <!-- Statut + Test de connexion -->
            @php
                $kpayConfigured = !empty($kpayConfig['api_key']) && !empty($kpayConfig['secret_key']);
                $kpayMode = $kpayConfig['mode'] ?? 'sandbox';
            @endphp
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div class="bg-dark-100 rounded-xl border border-dark-200 p-4 flex items-center">
                    <div class="w-10 h-10 rounded-lg flex items-center justify-center mr-3 {{ $kpayConfigured ? 'bg-green-500/15 text-green-400' : 'bg-yellow-500/15 text-yellow-400' }}">
                        <i class="fas {{ $kpayConfigured ? 'fa-check' : 'fa-exclamation' }}"></i>
                    </div>
                    <div>
                        <p class="text-xs text-gray-400">Clés API</p>
                        <p class="text-sm font-semibold {{ $kpayConfigured ? 'text-green-400' : 'text-yellow-400' }}">{{ $kpayConfigured ? 'Configurées' : 'À renseigner' }}</p>
                    </div>
                </div>
                <div class="bg-dark-100 rounded-xl border border-dark-200 p-4 flex items-center">
                    <div class="w-10 h-10 rounded-lg flex items-center justify-center mr-3 {{ $kpayMode === 'live' ? 'bg-red-500/15 text-red-400' : 'bg-blue-500/15 text-blue-400' }}">
                        <i class="fas fa-{{ $kpayMode === 'live' ? 'rocket' : 'flask' }}"></i>
                    </div>
                    <div>
                        <p class="text-xs text-gray-400">Environnement</p>
                        <p class="text-sm font-semibold text-white">{{ $kpayMode === 'live' ? 'Production' : 'Sandbox (test)' }}</p>
                    </div>
                </div>
                <div class="bg-dark-100 rounded-xl border border-dark-200 p-4 flex items-center justify-between">
                    <div>
                        <p class="text-xs text-gray-400">Connexion</p>
                        <p id="kpay-test-result" class="text-sm font-semibold text-gray-300">Non testée</p>
                    </div>
                    <button type="button" id="kpay-test-btn"
                            class="ml-2 px-3 py-2 bg-purple-500/20 text-purple-300 rounded-lg hover:bg-purple-500/30 transition-all text-sm whitespace-nowrap">
                        <i class="fas fa-plug mr-1"></i> Tester
                    </button>
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
                            <strong>Important :</strong> KPay utilise l'API v1 avec authentification par en-têtes
                            <code class="px-1 bg-purple-500/20 rounded">X-API-Key</code> et
                            <code class="px-1 bg-purple-500/20 rounded">X-Secret-Key</code>.
                            L'environnement (sandbox/production) est déduit du préfixe de la clé
                            (<code class="px-1 bg-purple-500/20 rounded">kpay_test_</code> ou
                            <code class="px-1 bg-purple-500/20 rounded">kpay_live_</code>).
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
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- URL de base -->
                        <div>
                            <label for="kpay_base_url" class="block text-sm font-medium text-gray-300 mb-2">
                                <i class="fas fa-globe text-purple-400 mr-1"></i> URL de base <span class="text-red-500">*</span>
                            </label>
                            <input type="url" name="kpay_base_url" id="kpay_base_url"
                                   value="{{ old('kpay_base_url', $kpayConfig['base_url'] ?? 'https://admin.kpay.site') }}"
                                   class="w-full px-4 py-3 bg-dark-50 border border-dark-300 rounded-lg text-white placeholder-gray-500 focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all"
                                   placeholder="https://admin.kpay.site">
                        </div>

                        <!-- Environnement -->
                        <div>
                            <label for="kpay_mode" class="block text-sm font-medium text-gray-300 mb-2">
                                <i class="fas fa-toggle-on text-purple-400 mr-1"></i> Environnement
                            </label>
                            <select name="kpay_mode" id="kpay_mode"
                                    class="w-full px-4 py-3 bg-dark-50 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all">
                                <option value="sandbox" {{ old('kpay_mode', $kpayConfig['mode'] ?? 'sandbox') == 'sandbox' ? 'selected' : '' }}>Sandbox (test)</option>
                                <option value="live" {{ old('kpay_mode', $kpayConfig['mode'] ?? 'sandbox') == 'live' ? 'selected' : '' }}>Production (live)</option>
                            </select>
                            <p class="mt-1 text-xs text-gray-500">Généralement déduit du préfixe de la clé (kpay_test_ / kpay_live_).</p>
                        </div>

                        <!-- API Key -->
                        <div>
                            <label for="kpay_api_key" class="block text-sm font-medium text-gray-300 mb-2">
                                <i class="fas fa-key text-purple-400 mr-1"></i> API Key (X-API-Key) <span class="text-red-500">*</span>
                            </label>
                            <input type="text" name="kpay_api_key" id="kpay_api_key"
                                   value="{{ old('kpay_api_key', $kpayConfig['api_key'] ?? '') }}"
                                   class="w-full px-4 py-3 bg-dark-50 border border-dark-300 rounded-lg text-white placeholder-gray-500 focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all"
                                   placeholder="kpay_test_... ou kpay_live_...">
                        </div>

                        <!-- Secret Key -->
                        <div>
                            <label for="kpay_secret_key" class="block text-sm font-medium text-gray-300 mb-2">
                                <i class="fas fa-lock text-purple-400 mr-1"></i> Secret Key (X-Secret-Key) <span class="text-red-500">*</span>
                            </label>
                            <div class="relative">
                                <input type="password" name="kpay_secret_key" id="kpay_secret_key"
                                       value=""
                                       class="w-full px-4 py-3 bg-dark-50 border border-dark-300 rounded-lg text-white placeholder-gray-500 focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all"
                                       placeholder="{{ !empty($kpayConfig['secret_key']) ? '•••••••• (laisser vide pour conserver)' : 'sk_test_... ou sk_live_...' }}">
                                <button type="button" onclick="togglePassword('kpay_secret_key')"
                                        class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-white">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Webhook Secret -->
                        <div class="md:col-span-2">
                            <label for="kpay_webhook_secret" class="block text-sm font-medium text-gray-300 mb-2">
                                <i class="fas fa-shield-alt text-purple-400 mr-1"></i> Webhook Secret (signature HMAC)
                            </label>
                            <div class="relative">
                                <input type="password" name="kpay_webhook_secret" id="kpay_webhook_secret"
                                       value=""
                                       class="w-full px-4 py-3 bg-dark-50 border border-dark-300 rounded-lg text-white placeholder-gray-500 focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all"
                                       placeholder="{{ !empty($kpayConfig['webhook_secret']) ? '•••••••• (laisser vide pour conserver)' : 'Secret de signature des webhooks KPay' }}">
                                <button type="button" onclick="togglePassword('kpay_webhook_secret')"
                                        class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-white">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <p class="mt-1 text-xs text-gray-500">Utilisé pour vérifier la signature <code>X-KPAY-Signature</code> des webhooks entrants.</p>
                        </div>

                        <!-- Callback URL (lecture seule) -->
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-300 mb-2">
                                <i class="fas fa-link text-purple-400 mr-1"></i> URL de webhook (à déclarer dans le dashboard KPay)
                            </label>
                            <input type="text" readonly
                                   value="{{ url('/api/v1/payments/webhook/kpay') }}"
                                   class="w-full px-4 py-3 bg-dark-200 border border-dark-300 rounded-lg text-gray-400 cursor-not-allowed">
                            <p class="mt-1 text-xs text-gray-500">URL publique à configurer côté KPay pour recevoir les notifications (doit être accessible depuis Internet ; en local, utilisez ngrok).</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Conversion de devises (exchangerate-api.com) -->
            <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6 mb-6">
                <h4 class="text-lg font-semibold text-white mb-2 flex items-center">
                    <i class="fas fa-exchange-alt text-purple-500 mr-2"></i>
                    Conversion de devises
                </h4>
                <p class="text-sm text-gray-400 mb-6">
                    Le montant saisi (en {{ $kpayConfig['base_currency'] ?? 'XAF' }}) est converti dans la devise de
                    l'opérateur sélectionné (ex. Zambie → ZMW) avant l'envoi à KPay, via
                    <a href="https://www.exchangerate-api.com" target="_blank" class="underline hover:text-purple-300">exchangerate-api.com</a>.
                </p>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Devise de base -->
                    <div>
                        <label for="kpay_base_currency" class="block text-sm font-medium text-gray-300 mb-2">
                            <i class="fas fa-coins text-purple-400 mr-1"></i> Devise de base (saisie utilisateur)
                        </label>
                        <input type="text" name="kpay_base_currency" id="kpay_base_currency"
                               value="{{ old('kpay_base_currency', $kpayConfig['base_currency'] ?? 'XAF') }}"
                               maxlength="3"
                               class="w-full px-4 py-3 bg-dark-50 border border-dark-300 rounded-lg text-white uppercase placeholder-gray-500 focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all"
                               placeholder="XAF">
                        <p class="mt-1 text-xs text-gray-500">Devise dans laquelle l'utilisateur saisit le montant (FCFA = XAF).</p>
                    </div>

                    <!-- Clé API exchangerate-api -->
                    <div>
                        <label for="exchange_rate_api_key" class="block text-sm font-medium text-gray-300 mb-2">
                            <i class="fas fa-key text-purple-400 mr-1"></i> Clé API exchangerate-api.com
                        </label>
                        <div class="relative">
                            <input type="password" name="exchange_rate_api_key" id="exchange_rate_api_key"
                                   value=""
                                   class="w-full px-4 py-3 bg-dark-50 border border-dark-300 rounded-lg text-white placeholder-gray-500 focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all"
                                   placeholder="{{ !empty($exchangeRateApiKey ?? '') ? '•••••••• (laisser vide pour conserver)' : 'Votre clé exchangerate-api.com' }}">
                            <button type="button" onclick="togglePassword('exchange_rate_api_key')"
                                    class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-white">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <p class="mt-1 text-xs text-gray-500">Créez une clé gratuite sur exchangerate-api.com. Les taux sont mis en cache 1&nbsp;h.</p>
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
                                <span>Depuis votre tableau de bord <a href="https://admin.kpay.site" target="_blank" class="underline hover:text-purple-300">admin.kpay.site</a>, récupérez vos clés <strong>API Key</strong> (X-API-Key) et <strong>Secret Key</strong> (X-Secret-Key).</span>
                            </li>
                            <li class="flex items-start">
                                <i class="fas fa-check-circle text-purple-500 mr-2 mt-0.5"></i>
                                <span>Collez-les ci-dessus. L'environnement est déduit du préfixe (<code>kpay_test_</code> = sandbox, <code>kpay_live_</code> = production).</span>
                            </li>
                            <li class="flex items-start">
                                <i class="fas fa-check-circle text-purple-500 mr-2 mt-0.5"></i>
                                <span>Déclarez l'<strong>URL de webhook</strong> ci-dessus dans le dashboard KPay et renseignez le <strong>Webhook Secret</strong> correspondant.</span>
                            </li>
                            <li class="flex items-start">
                                <i class="fas fa-check-circle text-purple-500 mr-2 mt-0.5"></i>
                                <span>Renseignez la clé <strong>exchangerate-api.com</strong> pour la conversion automatique des devises.</span>
                            </li>
                            <li class="flex items-start">
                                <i class="fas fa-check-circle text-purple-500 mr-2 mt-0.5"></i>
                                <span>Cliquez sur <strong>Tester</strong> pour vérifier la connexion, puis passez en production après validation.</span>
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

    <!-- Encaissement direct Tab (Stripe carte + minimums par moyen) -->
    <div x-show="activePayment === 'direct'" x-cloak>
        <form action="{{ route('admin.settings.payments.update') }}" method="POST">
            @csrf
            @method('PUT')

            <!-- Stripe (carte bancaire) -->
            <div class="bg-dark-100 rounded-lg shadow-lg border border-dark-200 p-6 mb-6">
                <div class="flex items-center justify-between mb-6">
                    <div class="flex items-center">
                        <div class="w-12 h-12 rounded-lg bg-emerald-500/10 flex items-center justify-center mr-4">
                            <i class="fas fa-credit-card text-emerald-400 text-xl"></i>
                        </div>
                        <div>
                            <h3 class="text-2xl font-bold text-white">Stripe</h3>
                            <p class="text-gray-400 mt-1">
                                Encaissement par carte <strong>et</strong> virements bancaires vendeurs (IBAN)
                            </p>
                            <p class="text-amber-400/90 text-xs mt-1">
                                <i class="fas fa-exclamation-triangle mr-1"></i>
                                Désactiver ce service masque toute la configuration Stripe : les virements
                                IBAN s'arrêtent aussi, pas seulement les paiements par carte.
                            </p>
                        </div>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="hidden" name="stripe_enabled" value="0">
                        <input type="checkbox" name="stripe_enabled" value="1"
                               {{ old('stripe_enabled', $paymentSettings['stripe_enabled']->value ?? '0') == '1' ? 'checked' : '' }}
                               class="sr-only peer">
                        <div class="w-14 h-7 bg-gray-600 peer-focus:ring-2 peer-focus:ring-emerald-500 rounded-full peer peer-checked:after:translate-x-7 after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:bg-white after:rounded-full after:h-6 after:w-6 after:transition-all peer-checked:bg-emerald-500"></div>
                    </label>
                </div>

                <div class="max-w-md">
                    <label for="stripe_currency" class="block text-sm font-medium text-gray-300 mb-2">
                        Devise d'encaissement
                    </label>
                    <select name="stripe_currency" id="stripe_currency"
                            class="w-full px-4 py-3 bg-dark-200 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                        @php $stripeCur = old('stripe_currency', $paymentSettings['stripe_currency']->value ?? 'USD'); @endphp
                        <option value="USD" {{ $stripeCur == 'USD' ? 'selected' : '' }}>USD - Dollar américain</option>
                        <option value="EUR" {{ $stripeCur == 'EUR' ? 'selected' : '' }}>EUR - Euro</option>
                        <option value="GBP" {{ $stripeCur == 'GBP' ? 'selected' : '' }}>GBP - Livre Sterling</option>
                    </select>
                    <p class="mt-1 text-xs text-gray-500">Devise réellement débitée par Stripe (conversion depuis XAF au taux stocké).</p>
                </div>
            </div>

            <!-- Clés API Stripe -->
            <div class="bg-dark-100 rounded-lg shadow-lg border border-dark-200 p-6 mb-6">
                <h3 class="text-lg font-semibold text-white mb-1">Clés API Stripe</h3>
                <p class="text-gray-400 text-sm mb-6">
                    Laissez un champ secret <strong>vide</strong> pour conserver la valeur actuelle.
                    Webhook à pointer sur <code class="text-emerald-400">/api/v1/stripe/webhook</code>
                    (events : <em>checkout.session.completed</em>, <em>payment_intent.succeeded/payment_failed</em>, <em>payout.paid/failed</em>).
                </p>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="stripe_mode" class="block text-sm font-medium text-gray-300 mb-2">Mode d'exécution</label>
                        <select name="stripe_mode" id="stripe_mode"
                                class="w-full px-4 py-3 bg-dark-200 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                            @php $stripeMode = old('stripe_mode', $stripeConfig['mode'] ?? 'test'); @endphp
                            <option value="test" {{ $stripeMode == 'test' ? 'selected' : '' }}>Test (Sandbox)</option>
                            <option value="live" {{ $stripeMode == 'live' ? 'selected' : '' }}>Live (Production)</option>
                        </select>
                    </div>
                    <div>
                        <label for="stripe_publishable_key" class="block text-sm font-medium text-gray-300 mb-2">Clé publiable (pk_...)</label>
                        <input type="text" name="stripe_publishable_key" id="stripe_publishable_key"
                               value="{{ old('stripe_publishable_key', $stripeConfig['publishable_key'] ?? '') }}"
                               placeholder="pk_test_..."
                               class="w-full px-4 py-3 bg-dark-200 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                    </div>
                    <div>
                        <label for="stripe_secret_key" class="block text-sm font-medium text-gray-300 mb-2">Clé secrète (sk_...)</label>
                        <input type="password" name="stripe_secret_key" id="stripe_secret_key"
                               placeholder="{{ !empty($stripeConfig['secret_key']) ? '•••••••••• (déjà configurée)' : 'sk_test_...' }}"
                               class="w-full px-4 py-3 bg-dark-200 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                    </div>
                    <div>
                        <label for="stripe_webhook_secret" class="block text-sm font-medium text-gray-300 mb-2">Webhook secret — plateforme (whsec_...)</label>
                        <input type="password" name="stripe_webhook_secret" id="stripe_webhook_secret"
                               placeholder="{{ !empty($stripeConfig['webhook_secret']) ? '•••••••••• (déjà configuré)' : 'whsec_...' }}"
                               class="w-full px-4 py-3 bg-dark-200 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                        <p class="mt-1 text-xs text-gray-500">Encaissements carte : <em>payment_intent.*</em>, <em>checkout.session.completed</em>.</p>
                    </div>
                    <div>
                        <label for="stripe_webhook_secret_connect" class="block text-sm font-medium text-gray-300 mb-2">Webhook secret — Connect (whsec_...)</label>
                        <input type="password" name="stripe_webhook_secret_connect" id="stripe_webhook_secret_connect"
                               placeholder="{{ !empty($stripeConfig['webhook_secret_connect']) ? '•••••••••• (déjà configuré)' : 'whsec_...' }}"
                               class="w-full px-4 py-3 bg-dark-200 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                        <p class="mt-1 text-xs text-gray-500">
                            Virements vendeurs : <em>payout.paid/failed</em>, <em>account.updated</em>.
                            Ces événements viennent d'un endpoint <strong>distinct</strong> — sans ce secret, ils sont rejetés
                            et les virements restent « en cours » indéfiniment.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Virements bancaires vendeurs (Stripe Connect) -->
            <div class="bg-dark-100 rounded-lg shadow-lg border border-dark-200 p-6 mb-6">
                <div class="flex items-center justify-between mb-1">
                    <h3 class="text-lg font-semibold text-white">Virements bancaires vendeurs (IBAN)</h3>
                    <button type="button" id="stripe-diagnose-btn"
                            class="px-4 py-2 text-sm bg-dark-200 hover:bg-dark-300 border border-dark-300 text-gray-200 rounded-lg transition-colors">
                        <i class="fas fa-stethoscope mr-1"></i> Vérifier la chaîne de virement
                    </button>
                </div>
                <p class="text-gray-400 text-sm mb-6">
                    Le vendeur est débité dans la devise de son portefeuille (XAF) ; le montant est converti
                    vers la devise de son IBAN et financé depuis n'importe quelle devise détenue par la plateforme.
                </p>

                <div id="stripe-diagnose-result" class="hidden mb-6 space-y-1 text-sm"></div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="min_stripe_withdrawal_amount" class="block text-sm font-medium text-gray-300 mb-2">
                            Retrait minimum (devise de l'IBAN)
                        </label>
                        <input type="number" step="0.01" min="0" name="min_stripe_withdrawal_amount" id="min_stripe_withdrawal_amount"
                               value="{{ old('min_stripe_withdrawal_amount', $paymentSettings['min_stripe_withdrawal_amount']->value ?? '5') }}"
                               class="w-full px-4 py-3 bg-dark-200 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                        <p class="mt-1 text-xs text-gray-500">Ex. 5 EUR. Traduit automatiquement en XAF pour le vendeur.</p>
                    </div>
                    <div>
                        <label for="stripe_fx_buffer_percent" class="block text-sm font-medium text-gray-300 mb-2">
                            Marge de change (%)
                        </label>
                        <input type="number" step="0.1" min="0" max="20" name="stripe_fx_buffer_percent" id="stripe_fx_buffer_percent"
                               value="{{ old('stripe_fx_buffer_percent', $paymentSettings['stripe_fx_buffer_percent']->value ?? '3') }}"
                               class="w-full px-4 py-3 bg-dark-200 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                        <p class="mt-1 text-xs text-gray-500">
                            Appliquée quand le virement est financé depuis une autre devise : absorbe l'écart entre
                            notre taux et celui de Stripe. Trop basse, le vendeur reçoit un peu moins que prévu.
                        </p>
                    </div>
                    <div>
                        <label for="stripe_business_url" class="block text-sm font-medium text-gray-300 mb-2">
                            Site de la plateforme (profil Stripe)
                        </label>
                        <input type="url" name="stripe_business_url" id="stripe_business_url"
                               value="{{ old('stripe_business_url', $paymentSettings['stripe_business_url']->value ?? '') }}"
                               placeholder="https://mon-asso.com"
                               class="w-full px-4 py-3 bg-dark-200 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                        <p class="mt-1 text-xs text-gray-500">
                            Transmis à Stripe à la création des comptes vendeurs. Une adresse locale est refusée :
                            laissée vide, une description d'activité est envoyée à la place.
                        </p>
                    </div>
                    <div>
                        <label for="stripe_business_mcc" class="block text-sm font-medium text-gray-300 mb-2">
                            Code d'activité (MCC)
                        </label>
                        <input type="text" name="stripe_business_mcc" id="stripe_business_mcc"
                               value="{{ old('stripe_business_mcc', $paymentSettings['stripe_business_mcc']->value ?? '5399') }}"
                               placeholder="5399"
                               class="w-full px-4 py-3 bg-dark-200 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                        <p class="mt-1 text-xs text-gray-500">5399 = commerce de détail divers. Exigé par Stripe pour activer un compte vendeur.</p>
                    </div>
                </div>
            </div>

            <!-- Minimums par moyen -->
            <div class="bg-dark-100 rounded-lg shadow-lg border border-dark-200 p-6 mb-6">
                <h3 class="text-lg font-semibold text-white mb-1">Montants minimums par moyen</h3>
                <p class="text-gray-400 text-sm mb-6">
                    Exprimés en <strong>XAF</strong> (devise pivot). En dessous de ce seuil, le moyen
                    apparaît grisé (indisponible) côté application, sans jamais être masqué selon le pays.
                </p>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div>
                        <label for="pay_min_kpay" class="block text-sm font-medium text-gray-300 mb-2">
                            <i class="fas fa-mobile-alt text-purple-400 mr-1"></i> Mobile Money
                        </label>
                        <input type="number" step="1" min="0" name="pay_min_kpay" id="pay_min_kpay"
                               value="{{ old('pay_min_kpay', $paymentSettings['pay_min_kpay']->value ?? '100') }}"
                               class="w-full px-4 py-3 bg-dark-200 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                    </div>
                    <div>
                        <label for="pay_min_paypal" class="block text-sm font-medium text-gray-300 mb-2">
                            <i class="fab fa-paypal text-blue-400 mr-1"></i> PayPal
                        </label>
                        <input type="number" step="1" min="0" name="pay_min_paypal" id="pay_min_paypal"
                               value="{{ old('pay_min_paypal', $paymentSettings['pay_min_paypal']->value ?? '600') }}"
                               class="w-full px-4 py-3 bg-dark-200 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                    </div>
                    <div>
                        <label for="pay_min_stripe" class="block text-sm font-medium text-gray-300 mb-2">
                            <i class="fas fa-credit-card text-emerald-400 mr-1"></i> Carte bancaire
                        </label>
                        <input type="number" step="1" min="0" name="pay_min_stripe" id="pay_min_stripe"
                               value="{{ old('pay_min_stripe', $paymentSettings['pay_min_stripe']->value ?? '300') }}"
                               class="w-full px-4 py-3 bg-dark-200 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                    </div>
                </div>
            </div>

            <div class="flex justify-end">
                <button type="submit"
                        class="px-6 py-3 bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg transition-colors">
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

// Diagnostic de la chaîne de virement IBAN (AJAX)
(function () {
    const btn = document.getElementById('stripe-diagnose-btn');
    const box = document.getElementById('stripe-diagnose-result');
    if (!btn) return;
    btn.addEventListener('click', async function () {
        const original = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Vérification…';
        box.classList.remove('hidden');
        box.innerHTML = '<p class="text-gray-300">Interrogation de Stripe…</p>';
        try {
            const res = await fetch('{{ route('admin.settings.payments.diagnose-stripe') }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json',
                },
            });
            const data = await res.json();
            if (!data.checks) {
                box.innerHTML = '<p class="text-red-400">✗ ' + (data.message || 'Diagnostic impossible') + '</p>';
                return;
            }
            box.innerHTML = data.checks.map(function (c) {
                const color = c.ok ? 'text-green-400' : 'text-red-400';
                const icon = c.ok ? '✓' : '✗';
                return '<p class="' + color + '">' + icon + ' ' + c.label + '</p>';
            }).join('');
        } catch (e) {
            box.innerHTML = '<p class="text-red-400">✗ Erreur réseau</p>';
        } finally {
            btn.disabled = false;
            btn.innerHTML = original;
        }
    });
})();

// Test de connexion KPay (AJAX)
(function () {
    const btn = document.getElementById('kpay-test-btn');
    const result = document.getElementById('kpay-test-result');
    if (!btn) return;
    btn.addEventListener('click', async function () {
        const original = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Test...';
        result.textContent = 'Test en cours…';
        result.className = 'text-sm font-semibold text-gray-300';
        try {
            const res = await fetch('{{ route('admin.settings.payments.test-kpay') }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json',
                },
            });
            const data = await res.json();
            if (data.success) {
                result.textContent = '✓ ' + (data.message || 'Connexion réussie');
                result.className = 'text-sm font-semibold text-green-400';
            } else {
                result.textContent = '✗ ' + (data.message || 'Échec');
                result.className = 'text-sm font-semibold text-red-400';
            }
        } catch (e) {
            result.textContent = '✗ Erreur réseau';
            result.className = 'text-sm font-semibold text-red-400';
        } finally {
            btn.disabled = false;
            btn.innerHTML = original;
        }
    });
})();
</script>
@endpush
@endsection
