@extends('admin.layouts.app')

@section('title', 'Paramètres')
@section('header', 'Paramètres de l\'Application')

@section('content')
<div class="space-y-6">
    <!-- Tabs Navigation -->
    <div class="bg-dark-100 rounded-lg shadow-sm border border-dark-200" x-data="{ activeTab: new URLSearchParams(window.location.search).get('tab') || 'general' }">
        <div class="border-b border-dark-200">
            <nav class="flex space-x-4 px-6" aria-label="Tabs">
                <button @click="activeTab = 'general'"
                        :class="activeTab === 'general' ? 'border-primary-500 text-primary-500' : 'border-transparent text-gray-400 hover:text-gray-300 hover:border-gray-300'"
                        class="py-4 px-1 border-b-2 font-medium text-sm transition-colors">
                    <i class="fas fa-cog mr-2"></i> Général
                </button>
                <button @click="activeTab = 'system'"
                        :class="activeTab === 'system' ? 'border-primary-500 text-primary-500' : 'border-transparent text-gray-400 hover:text-gray-300 hover:border-gray-300'"
                        class="py-4 px-1 border-b-2 font-medium text-sm transition-colors">
                    <i class="fas fa-server mr-2"></i> Système
                </button>
                <button @click="activeTab = 'commissions'"
                        :class="activeTab === 'commissions' ? 'border-primary-500 text-primary-500' : 'border-transparent text-gray-400 hover:text-gray-300 hover:border-gray-300'"
                        class="py-4 px-1 border-b-2 font-medium text-sm transition-colors">
                    <i class="fas fa-percentage mr-2"></i> Commissions
                </button>
            </nav>
        </div>

        <!-- General Tab -->
        <div x-show="activeTab === 'general'" x-cloak>
            <form action="{{ route('admin.settings.update') }}" method="POST" enctype="multipart/form-data" class="p-6">
                @csrf
                @method('PUT')

                <div class="space-y-6">
                    <h3 class="text-lg font-semibold text-white mb-4">Informations Générales</h3>

                    <!-- App Name -->
                    <div>
                        <label for="app_name" class="block text-sm font-medium text-gray-300 mb-2">
                            Nom de l'application <span class="text-red-500">*</span>
                        </label>
                        <input type="text" name="app_name" id="app_name"
                               value="{{ old('app_name', $generalSettings['app_name']->value ?? 'ASSO') }}"
                               class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                               required>
                        @error('app_name')
                            <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- App Logo -->
                    <div>
                        <label for="app_logo" class="block text-sm font-medium text-gray-300 mb-2">
                            Logo de l'application
                        </label>
                        @if(isset($generalSettings['app_logo']) && $generalSettings['app_logo']->value)
                            <div class="mb-2">
                                <img src="{{ Storage::url($generalSettings['app_logo']->value) }}" alt="Logo" class="h-16 rounded">
                            </div>
                        @endif
                        <input type="file" name="app_logo" id="app_logo" accept="image/*"
                               class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg text-white file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-primary-500 file:text-white hover:file:bg-primary-600">
                    </div>

                    <!-- App Slogan -->
                    <x-admin.translatable name="app_slogan" field="value" label="Slogan" :model="$generalSettings['app_slogan'] ?? null" />

                    <!-- App Description -->
                    <x-admin.translatable name="app_description" field="value" label="Description" type="textarea"
                        :model="$generalSettings['app_description'] ?? null" />

                    <h3 class="text-lg font-semibold text-white mb-4 mt-8">Informations de Contact</h3>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Contact Email -->
                        <div>
                            <label for="contact_email" class="block text-sm font-medium text-gray-300 mb-2">
                                Email de contact <span class="text-red-500">*</span>
                            </label>
                            <input type="email" name="contact_email" id="contact_email"
                                   value="{{ old('contact_email', $generalSettings['contact_email']->value ?? '') }}"
                                   class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                                   required>
                        </div>

                        <!-- Contact Phone -->
                        <div>
                            <label for="contact_phone" class="block text-sm font-medium text-gray-300 mb-2">
                                Téléphone
                            </label>
                            <input type="text" name="contact_phone" id="contact_phone"
                                   value="{{ old('contact_phone', $generalSettings['contact_phone']->value ?? '') }}"
                                   class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                        </div>
                    </div>

                    <!-- Contact Address -->
                    <x-admin.translatable name="contact_address" field="value" label="Adresse"
                        :model="$generalSettings['contact_address'] ?? null" />

                    <!-- Submit Button -->
                    <div class="flex justify-end pt-4">
                        <button type="submit"
                                class="px-6 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:from-primary-600 hover:to-primary-700 transition-all shadow-md">
                            <i class="fas fa-save mr-2"></i> Enregistrer
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- System Tab -->
        <div x-show="activeTab === 'system'" x-cloak>
            <form action="{{ route('admin.settings.update') }}" method="POST" class="p-6">
                @csrf
                @method('PUT')

                <div class="space-y-6">
                    <h3 class="text-lg font-semibold text-white mb-4">Paramètres Système</h3>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Timezone -->
                        <div>
                            <label for="timezone" class="block text-sm font-medium text-gray-300 mb-2">
                                Fuseau horaire <span class="text-red-500">*</span>
                            </label>
                            <select name="timezone" id="timezone"
                                    class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                                    required>
                                <option value="Africa/Porto-Novo" {{ old('timezone', $systemSettings['timezone']->value ?? '') == 'Africa/Porto-Novo' ? 'selected' : '' }}>Africa/Porto-Novo</option>
                                <option value="Africa/Abidjan" {{ old('timezone', $systemSettings['timezone']->value ?? '') == 'Africa/Abidjan' ? 'selected' : '' }}>Africa/Abidjan</option>
                                <option value="Africa/Lagos" {{ old('timezone', $systemSettings['timezone']->value ?? '') == 'Africa/Lagos' ? 'selected' : '' }}>Africa/Lagos</option>
                                <option value="UTC" {{ old('timezone', $systemSettings['timezone']->value ?? '') == 'UTC' ? 'selected' : '' }}>UTC</option>
                            </select>
                        </div>

                        <!-- Default Language -->
                        <div>
                            <label for="default_language" class="block text-sm font-medium text-gray-300 mb-2">
                                Langue par défaut <span class="text-red-500">*</span>
                            </label>
                            <select name="default_language" id="default_language"
                                    class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                                    required>
                                <option value="fr" {{ old('default_language', $systemSettings['default_language']->value ?? '') == 'fr' ? 'selected' : '' }}>Français</option>
                                <option value="en" {{ old('default_language', $systemSettings['default_language']->value ?? '') == 'en' ? 'selected' : '' }}>English</option>
                            </select>
                        </div>

                        <!-- Currency -->
                        <div>
                            <label for="currency" class="block text-sm font-medium text-gray-300 mb-2">
                                Devise <span class="text-red-500">*</span>
                            </label>
                            <select name="currency" id="currency"
                                    class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                                    required>
                                <option value="XOF" {{ old('currency', $systemSettings['currency']->value ?? '') == 'XOF' ? 'selected' : '' }}>XOF (Franc CFA)</option>
                                <option value="USD" {{ old('currency', $systemSettings['currency']->value ?? '') == 'USD' ? 'selected' : '' }}>USD (Dollar)</option>
                                <option value="EUR" {{ old('currency', $systemSettings['currency']->value ?? '') == 'EUR' ? 'selected' : '' }}>EUR (Euro)</option>
                            </select>
                        </div>

                        <!-- Currency Symbol -->
                        <div>
                            <label for="currency_symbol" class="block text-sm font-medium text-gray-300 mb-2">
                                Symbole de la devise <span class="text-red-500">*</span>
                            </label>
                            <input type="text" name="currency_symbol" id="currency_symbol"
                                   value="{{ old('currency_symbol', $systemSettings['currency_symbol']->value ?? '') }}"
                                   class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                                   required>
                        </div>

                        <!-- Minimum Deposit Amount -->
                        <div>
                            <label for="min_deposit_amount" class="block text-sm font-medium text-gray-300 mb-2">
                                Montant minimum de dépôt (FCFA) <span class="text-red-500">*</span>
                            </label>
                            <input type="number" name="min_deposit_amount" id="min_deposit_amount"
                                   value="{{ old('min_deposit_amount', $systemSettings['min_deposit_amount']->value ?? '100') }}"
                                   class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                                   min="1" step="1"
                                   required>
                        </div>

                        <!-- Minimum Withdrawal Amount -->
                        <div>
                            <label for="min_withdrawal_amount" class="block text-sm font-medium text-gray-300 mb-2">
                                Montant minimum de retrait (FCFA) <span class="text-red-500">*</span>
                            </label>
                            <input type="number" name="min_withdrawal_amount" id="min_withdrawal_amount"
                                   value="{{ old('min_withdrawal_amount', $systemSettings['min_withdrawal_amount']->value ?? '100') }}"
                                   class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                                   min="1" step="1"
                                   required>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <div class="flex justify-end pt-4">
                        <button type="submit"
                                class="px-6 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:from-primary-600 hover:to-primary-700 transition-all shadow-md">
                            <i class="fas fa-save mr-2"></i> Enregistrer
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Commissions Tab -->
        @php
            $rangesData = $commissionRanges->map(fn($r) => [
                'id' => $r->id,
                'min_amount' => (float) $r->min_amount,
                'max_amount' => (float) $r->max_amount,
                'percentage' => (float) $r->percentage,
                'is_active' => (bool) $r->is_active,
            ])->values()->toArray();
        @endphp
        <div x-show="activeTab === 'commissions'" x-cloak
             x-data="commissionSettings()">
            <form action="{{ route('admin.settings.commissions.update') }}" method="POST" class="p-6">
                @csrf
                @method('PUT')

                <div class="space-y-8">
                    <!-- Principe -->
                    <div class="bg-blue-900/20 border border-blue-800/30 rounded-lg p-4 flex items-start">
                        <i class="fas fa-info-circle text-blue-400 mt-0.5 mr-3"></i>
                        <div class="text-sm text-blue-300">
                            <p class="font-medium mb-1">Toutes les commissions ASSO sont ajoutées au prix payé par le client.</p>
                            <p class="text-blue-400">Le vendeur, le livreur et le voyageur reçoivent exactement le prix qu'ils ont fixé. Le client voit et paie ce prix majoré de la commission, sans ligne supplémentaire. Un changement de taux s'applique aux prochaines commandes, jamais aux commandes déjà passées.</p>
                        </div>
                    </div>

                    <!-- 1. Vente de produits -->
                    <section class="space-y-4">
                        <div>
                            <h3 class="text-lg font-semibold text-white"><i class="fas fa-shopping-bag mr-2 text-primary-500"></i>Vente de produits</h3>
                            <p class="text-sm text-gray-400 mt-1">Pourcentage ajouté au prix fixé par le vendeur.</p>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-300 mb-2">Taux par défaut</label>
                                <div class="relative">
                                    <input type="number" name="default_sale_commission_rate" x-model.number="defaultRate"
                                           min="0" max="100" step="0.01" required
                                           class="w-full px-3 py-2 bg-dark-100 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-primary-500 pr-8">
                                    <span class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm">%</span>
                                </div>
                                <p class="text-xs text-gray-500 mt-1">Appliqué aux prix qui ne tombent dans aucune plage ci-dessous.</p>
                            </div>

                            <!-- Simulateur -->
                            <div class="bg-dark-50 border border-dark-300 rounded-lg p-4">
                                <label class="block text-sm font-medium text-gray-300 mb-2">Simulateur</label>
                                <div class="flex items-center gap-2">
                                    <input type="number" x-model.number="simPrice" min="0" step="1" placeholder="Prix vendeur"
                                           class="w-full px-3 py-2 bg-dark-100 border border-dark-300 rounded-lg text-white text-sm">
                                    <span class="text-gray-400 text-sm">F</span>
                                </div>
                                <div class="mt-3 text-sm space-y-1" x-show="simPrice > 0">
                                    <p class="text-gray-400">Taux appliqué : <span class="text-white font-medium" x-text="fmtRate(rateFor(simPrice))"></span></p>
                                    <p class="text-gray-400">Le client paie : <span class="text-green-400 font-semibold" x-text="fmt(buyerPrice(simPrice))"></span></p>
                                    <p class="text-gray-400">Le vendeur reçoit : <span class="text-white font-medium" x-text="fmt(simPrice)"></span></p>
                                    <p class="text-gray-400">ASSO perçoit : <span class="text-primary-400 font-medium" x-text="fmt(buyerPrice(simPrice) - simPrice)"></span></p>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center justify-between pt-2">
                            <div>
                                <p class="text-sm font-medium text-gray-300">Taux par tranche de prix <span class="text-gray-500 font-normal">(facultatif)</span></p>
                                <p class="text-xs text-gray-500">La tranche est choisie selon le prix vendeur du produit.</p>
                            </div>
                            <button type="button" @click="addRange()"
                                    class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors text-sm">
                                <i class="fas fa-plus mr-1"></i> Ajouter une tranche
                            </button>
                        </div>

                        <p x-show="ranges.length === 0" class="text-sm text-gray-500 italic px-1">Aucune tranche : le taux par défaut s'applique à tous les produits.</p>

                        <div x-show="ranges.length > 0" class="hidden md:grid md:grid-cols-12 gap-4 px-4 py-2 text-sm font-medium text-gray-400 border-b border-dark-200">
                            <div class="col-span-3">Prix vendeur min. (F)</div>
                            <div class="col-span-3">Prix vendeur max. (F)</div>
                            <div class="col-span-2">Commission (%)</div>
                            <div class="col-span-2 text-center">Active</div>
                            <div class="col-span-2 text-center">Actions</div>
                        </div>

                        <template x-for="(range, index) in ranges" :key="index">
                            <div class="grid grid-cols-1 md:grid-cols-12 gap-4 px-4 py-3 bg-dark-50 rounded-lg border border-dark-300 items-center">
                                <div class="md:col-span-3">
                                    <label class="md:hidden block text-xs text-gray-400 mb-1">Prix vendeur min. (F)</label>
                                    <input type="number" :name="'ranges[' + index + '][min_amount]'" x-model.number="range.min_amount"
                                           placeholder="0" min="0" step="1" required
                                           class="w-full px-3 py-2 bg-dark-100 border border-dark-300 rounded-lg text-white text-sm focus:ring-2 focus:ring-primary-500">
                                </div>
                                <div class="md:col-span-3">
                                    <label class="md:hidden block text-xs text-gray-400 mb-1">Prix vendeur max. (F)</label>
                                    <input type="number" :name="'ranges[' + index + '][max_amount]'" x-model.number="range.max_amount"
                                           placeholder="100000" min="0" step="1" required
                                           class="w-full px-3 py-2 bg-dark-100 border border-dark-300 rounded-lg text-white text-sm focus:ring-2 focus:ring-primary-500">
                                </div>
                                <div class="md:col-span-2">
                                    <label class="md:hidden block text-xs text-gray-400 mb-1">Commission (%)</label>
                                    <div class="relative">
                                        <input type="number" :name="'ranges[' + index + '][percentage]'" x-model.number="range.percentage"
                                               placeholder="5" min="0" max="100" step="0.01" required
                                               class="w-full px-3 py-2 bg-dark-100 border border-dark-300 rounded-lg text-white text-sm pr-8 focus:ring-2 focus:ring-primary-500">
                                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm">%</span>
                                    </div>
                                </div>
                                <div class="md:col-span-2 flex items-center md:justify-center">
                                    <label class="md:hidden text-xs text-gray-400 mr-2">Active</label>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" :name="'ranges[' + index + '][is_active]'" x-model="range.is_active" value="1" class="sr-only peer">
                                        <div class="w-11 h-6 bg-dark-300 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-primary-500"></div>
                                    </label>
                                </div>
                                <div class="md:col-span-2 flex items-center md:justify-center">
                                    <button type="button" @click="removeRange(index)"
                                            class="px-3 py-2 bg-red-600/20 text-red-400 rounded-lg hover:bg-red-600/40 transition-colors text-sm">
                                        <i class="fas fa-trash-alt mr-1"></i> Supprimer
                                    </button>
                                </div>
                            </div>
                        </template>
                    </section>

                    <!-- 2. Diaspo -->
                    <section class="space-y-4 border-t border-dark-200 pt-6">
                        <div>
                            <h3 class="text-lg font-semibold text-white"><i class="fas fa-suitcase-rolling mr-2 text-primary-500"></i>Diaspo (échange de kilos)</h3>
                            <p class="text-sm text-gray-400 mt-1">Pourcentage ajouté au prix fixé par le voyageur pour chaque réservation.</p>
                        </div>
                        <div class="max-w-xs">
                            <div class="relative">
                                <input type="number" name="diaspo_commission_rate"
                                       value="{{ old('diaspo_commission_rate', $commissionSettings['diaspo_commission_rate']) }}"
                                       min="0" max="100" step="0.01" required
                                       class="w-full px-3 py-2 bg-dark-100 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-primary-500 pr-8">
                                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm">%</span>
                            </div>
                        </div>
                    </section>

                    <!-- 3. Livraison -->
                    <section class="space-y-4 border-t border-dark-200 pt-6">
                        <div>
                            <h3 class="text-lg font-semibold text-white"><i class="fas fa-truck mr-2 text-primary-500"></i>Livraison</h3>
                            <p class="text-sm text-gray-400 mt-1">Pourcentage du prix hors taxe du partenaire (SOLEX, livreurs…), ajouté aux frais de livraison payés par l'acheteur. Ex. 10 % sur une course à 1 500 F HT = 150 F.</p>
                        </div>
                        <div class="max-w-xs">
                            <div class="relative">
                                <input type="number" name="delivery_commission_rate"
                                       value="{{ old('delivery_commission_rate', $commissionSettings['delivery_commission_rate']) }}"
                                       min="0" max="100" step="0.01" required
                                       class="w-full px-3 py-2 bg-dark-100 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-primary-500 pr-8">
                                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm">%</span>
                            </div>
                        </div>
                    </section>

                    <!-- 4. Commerciaux (P6) -->
                    <section class="space-y-4 border-t border-dark-200 pt-6">
                        <div>
                            <h3 class="text-lg font-semibold text-white"><i class="fas fa-user-tie mr-2 text-primary-500"></i>Commerciaux (forfaits vendeurs)</h3>
                            <p class="text-sm text-gray-400 mt-1">
                                Pourcentage du prix d'un forfait versé au commercial dont le code a été saisi par le vendeur.
                                Contrairement aux commissions ci-dessus, il est <strong>payé par ASSO</strong> et n'augmente pas le prix du forfait.
                                Un taux particulier peut être fixé sur la fiche d'un commercial.
                            </p>
                        </div>
                        <div class="flex flex-col md:flex-row md:items-center gap-4">
                            <div class="max-w-xs w-full">
                                <div class="relative">
                                    <input type="number" name="sales_commission_rate"
                                           value="{{ old('sales_commission_rate', $commissionSettings['sales_commission_rate']) }}"
                                           min="0" max="100" step="0.01" required
                                           class="w-full px-3 py-2 bg-dark-100 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-primary-500 pr-8">
                                    <span class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm">%</span>
                                </div>
                            </div>
                            <a href="{{ route('admin.sales.agents.index') }}" class="text-sm text-primary-400 hover:text-primary-300">
                                <i class="fas fa-arrow-right mr-1"></i>Gérer les commerciaux et leurs commissions
                            </a>
                        </div>
                    </section>

                    <div class="flex justify-end pt-4 border-t border-dark-200">
                        <button type="submit"
                                class="px-6 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:from-primary-600 hover:to-primary-700 transition-all shadow-md">
                            <i class="fas fa-save mr-2"></i> Enregistrer les commissions
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    function commissionSettings() {
        return {
            defaultRate: {{ (float) old('default_sale_commission_rate', $commissionSettings['default_sale_commission_rate']) }},
            ranges: @json($rangesData),
            simPrice: 10000,
            addRange() {
                const last = this.ranges[this.ranges.length - 1];
                const min = last && last.max_amount !== '' ? Number(last.max_amount) + 1 : 0;
                this.ranges.push({ id: null, min_amount: min, max_amount: '', percentage: '', is_active: true });
            },
            removeRange(index) {
                this.ranges.splice(index, 1);
            },
            // Même règle que CommissionService::rateFor (plage active couvrant le prix, sinon défaut).
            rateFor(price) {
                const r = this.ranges.find(r => r.is_active && r.min_amount !== '' && r.max_amount !== ''
                    && Number(r.min_amount) <= price && Number(r.max_amount) >= price);
                return r ? Number(r.percentage || 0) : Number(this.defaultRate || 0);
            },
            buyerPrice(price) {
                return Math.round(price * (1 + this.rateFor(price) / 100));
            },
            fmt(v) {
                return Math.round(v).toLocaleString('fr-FR') + ' F';
            },
            fmtRate(v) {
                return v.toLocaleString('fr-FR', { maximumFractionDigits: 2 }) + ' %';
            },
        }
    }
</script>
@endpush
