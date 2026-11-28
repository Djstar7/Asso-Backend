@extends('admin.layouts.app')

@section('content')
<div class="p-6">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-white">Fournisseurs</h1>
        <p class="text-gray-400">Catalogues de dropshipping connectés à ASSO. Les produits importés depuis un fournisseur sont vendus par une boutique ASSO et livrés à l'entrepôt de Douala avant la livraison au client.</p>
    </div>
    @include('admin.suppliers._tabs')

    {{-- CJdropshipping --}}
    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6 mb-6">
        <div class="flex flex-wrap items-start justify-between gap-4 mb-4">
            <div>
                <h2 class="text-lg font-semibold text-white"><i class="fas fa-boxes-packing mr-2 text-primary-400"></i>CJdropshipping</h2>
                <p class="text-sm text-gray-400">Catalogue chinois à l'unité, commande et paiement par l'API depuis le solde CJ (USD).</p>
            </div>
            <div class="flex flex-wrap gap-2 text-xs">
                @if($cj->isConnected())
                    <span class="px-2 py-1 rounded bg-green-900/40 text-green-300"><i class="fas fa-check-circle mr-1"></i>Connecté</span>
                @elseif($cj->hasApiKey())
                    <span class="px-2 py-1 rounded bg-yellow-900/40 text-yellow-300"><i class="fas fa-plug mr-1"></i>Connexion à tester</span>
                @else
                    <span class="px-2 py-1 rounded bg-dark-200 text-gray-400"><i class="fas fa-key mr-1"></i>Clé API manquante</span>
                @endif
                <span class="px-2 py-1 rounded {{ $cj->is_sandbox ? 'bg-blue-900/40 text-blue-300' : 'bg-red-900/40 text-red-300' }}">
                    <i class="fas {{ $cj->is_sandbox ? 'fa-flask' : 'fa-bolt' }} mr-1"></i>{{ $cj->is_sandbox ? 'Bac à sable' : 'Production' }}
                </span>
                <span class="px-2 py-1 rounded {{ $cj->is_active ? 'bg-green-900/40 text-green-300' : 'bg-dark-200 text-gray-400' }}">
                    {{ $cj->is_active ? 'Actif' : 'Inactif' }}
                </span>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="bg-dark-50 rounded-lg p-4">
                <p class="text-xs uppercase tracking-wider text-gray-500 mb-1">Solde CJ</p>
                @if($cj->balance_usd !== null)
                    <p class="text-xl font-semibold {{ $cj->balanceIsLow() ? 'text-yellow-300' : 'text-white' }}">{{ number_format((float) $cj->balance_usd, 2, ',', ' ') }} USD</p>
                    @if((float) $cj->balance_frozen_usd > 0)
                        <p class="text-xs text-gray-400">dont {{ number_format((float) $cj->balance_frozen_usd, 2, ',', ' ') }} USD gelés</p>
                    @endif
                    @if($cj->balanceIsLow())
                        <p class="text-xs text-yellow-300 mt-1"><i class="fas fa-exclamation-triangle mr-1"></i>Sous le seuil d'alerte : rechargez le solde CJ avant d'envoyer des commandes.</p>
                    @endif
                    <p class="text-xs text-gray-500 mt-1">Relevé {{ $cj->balance_checked_at?->diffForHumans() }}</p>
                @else
                    <p class="text-sm text-gray-400">Inconnu : testez la connexion.</p>
                @endif
            </div>
            <div class="bg-dark-50 rounded-lg p-4">
                <p class="text-xs uppercase tracking-wider text-gray-500 mb-1">Jeton d'accès</p>
                @if($cj->access_token_expires_at)
                    <p class="text-sm text-white">Valable jusqu'au {{ $cj->access_token_expires_at->format('d/m/Y') }}</p>
                    <p class="text-xs text-gray-500">Renouvelé automatiquement.</p>
                @else
                    <p class="text-sm text-gray-400">Aucun jeton.</p>
                @endif
                @if($cj->account_id)
                    <p class="text-xs text-gray-500 mt-1">Compte CJ n° {{ $cj->account_id }}</p>
                @endif
            </div>
            <div class="bg-dark-50 rounded-lg p-4">
                <p class="text-xs uppercase tracking-wider text-gray-500 mb-1">Dernière erreur</p>
                @if($cj->last_error)
                    <p class="text-sm text-red-300 break-words">{{ $cj->last_error }}</p>
                    <p class="text-xs text-gray-500 mt-1">{{ $cj->last_error_at?->format('d/m/Y H:i') }}</p>
                @else
                    <p class="text-sm text-gray-400">Aucune.</p>
                @endif
            </div>
        </div>

        <form action="{{ route('admin.suppliers.cj.update') }}" method="POST" class="space-y-4">
            @csrf
            @method('PUT')
            <div>
                <label class="block text-sm text-gray-300 mb-1" for="api_key">Clé API CJ</label>
                <input id="api_key" name="api_key" type="password" autocomplete="off"
                       placeholder="{{ $cj->maskedApiKey() ?? 'CJ1234567@api@…' }}"
                       class="w-full px-3 py-2 bg-dark-200 rounded text-white">
                <p class="text-xs text-gray-500 mt-1">À copier depuis la page « My CJ API » du compte CJ. Laissez vide pour garder la clé actuelle. Elle est chiffrée et n'est plus jamais affichée.</p>
                @error('api_key')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm text-gray-300 mb-1" for="margin_percent">Marge ASSO (%)</label>
                    <input id="margin_percent" name="margin_percent" type="number" min="0" max="300" step="0.1" required
                           value="{{ old('margin_percent', $cj->setting('margin_percent')) }}" class="w-full px-3 py-2 bg-dark-200 rounded text-white">
                    <p class="text-xs text-gray-500 mt-1">Ajoutée au coût CJ (produit + transport jusqu'à Douala).</p>
                    @error('margin_percent')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm text-gray-300 mb-1" for="fx_buffer_percent">Marge de change (%)</label>
                    <input id="fx_buffer_percent" name="fx_buffer_percent" type="number" min="0" max="50" step="0.1" required
                           value="{{ old('fx_buffer_percent', $cj->setting('fx_buffer_percent')) }}" class="w-full px-3 py-2 bg-dark-200 rounded text-white">
                    <p class="text-xs text-gray-500 mt-1">Couvre la variation du dollar entre la vente et le paiement à CJ.</p>
                    @error('fx_buffer_percent')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm text-gray-300 mb-1" for="balance_alert_usd">Seuil d'alerte du solde (USD)</label>
                    <input id="balance_alert_usd" name="balance_alert_usd" type="number" min="0" step="1" required
                           value="{{ old('balance_alert_usd', $cj->setting('balance_alert_usd')) }}" class="w-full px-3 py-2 bg-dark-200 rounded text-white">
                    @error('balance_alert_usd')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="flex flex-wrap gap-6">
                <label class="inline-flex items-center gap-2 text-sm text-gray-300">
                    <input type="hidden" name="is_sandbox" value="0">
                    <input type="checkbox" name="is_sandbox" value="1" @checked(old('is_sandbox', $cj->is_sandbox)) class="rounded bg-dark-200">
                    Bac à sable (commandes de test, paiement simulé)
                </label>
                <label class="inline-flex items-center gap-2 text-sm text-gray-300">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $cj->is_active)) class="rounded bg-dark-200">
                    Fournisseur actif
                </label>
                <label class="inline-flex items-center gap-2 text-sm text-gray-300">
                    <input type="hidden" name="auto_publish" value="0">
                    <input type="checkbox" name="auto_publish" value="1" @checked(old('auto_publish', $cj->setting('auto_publish', true))) class="rounded bg-dark-200">
                    Publier les produits importés tout de suite (visibles dans l’app s’ils ont du stock)
                </label>
            </div>

            <div class="flex flex-wrap gap-3">
                <button type="submit" class="px-4 py-2 text-sm bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg">
                    <i class="fas fa-save mr-1"></i> Enregistrer
                </button>
            </div>
        </form>

        <form action="{{ route('admin.suppliers.cj.test') }}" method="POST" class="mt-3">
            @csrf
            <button type="submit" @disabled(! $cj->hasApiKey()) class="px-4 py-2 text-sm bg-dark-200 text-white rounded-lg hover:bg-dark-50 disabled:opacity-50">
                <i class="fas fa-plug mr-1"></i> Tester la connexion et relever le solde
            </button>
        </form>
    </div>

    {{-- Printful : phase 2 --}}
    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6 opacity-70">
        <h2 class="text-lg font-semibold text-white"><i class="fas fa-shirt mr-2 text-gray-400"></i>Printful</h2>
        <p class="text-sm text-gray-400">Impression à la demande. Prévu après la mise en service de CJ.</p>
    </div>
</div>
@endsection
