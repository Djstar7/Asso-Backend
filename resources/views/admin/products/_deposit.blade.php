{{--
    Commande avec acompte (produit sur commande / importé) : le client paie
    l'acompte à la commande ; le solde n'est payable qu'après la livraison ou le
    retrait et la vérification faite ensemble avec un employé ASSO.
--}}
@php
    $depositOn = (bool) old('deposit_enabled', isset($product) ? $product->deposit_enabled : false);
    $depositRate = old('deposit_rate', isset($product) ? $product->deposit_rate : null);
@endphp
<div class="bg-dark-100 rounded-xl shadow-lg p-6">
    <label for="deposit_enabled" class="flex items-center justify-between gap-4 cursor-pointer">
        <div>
            <h2 class="text-lg font-semibold text-white flex items-center">
                <i class="fas fa-hand-holding-usd text-amber-500 mr-2"></i>
                Commande avec acompte
            </h2>
            <p class="text-xs text-gray-400 mt-1">Acompte à la commande, solde après livraison et vérification ASSO.</p>
        </div>
        <span class="relative inline-flex flex-shrink-0">
            <input type="hidden" name="deposit_enabled" value="0">
            <input type="checkbox" name="deposit_enabled" id="deposit_enabled" value="1" {{ $depositOn ? 'checked' : '' }} class="peer sr-only"
                   onchange="document.getElementById('deposit_rate_block').classList.toggle('hidden', !this.checked)">
            <span class="h-8 w-14 rounded-full bg-gray-600 transition-colors peer-checked:bg-amber-600 peer-focus-visible:ring-2 peer-focus-visible:ring-amber-400"></span>
            <span class="absolute left-1 top-1 h-6 w-6 rounded-full bg-white shadow transition-transform peer-checked:translate-x-6"></span>
        </span>
    </label>
    <div id="deposit_rate_block" class="mt-4 {{ $depositOn ? '' : 'hidden' }}">
        <label for="deposit_rate" class="block text-sm font-medium text-gray-300 mb-2">Acompte requis (% du prix client)</label>
        <div class="flex items-center gap-2">
            <input type="number" name="deposit_rate" id="deposit_rate" min="1" max="99" step="0.5" value="{{ $depositRate }}"
                   class="w-32 px-4 py-2 bg-dark-200 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-amber-500">
            <span class="text-gray-400">%</span>
        </div>
        <p class="text-xs text-gray-400 mt-2">La livraison est payée avec l'acompte. Vérification ASSO obligatoire avant le solde.</p>
    </div>
    @error('deposit_enabled')<p class="mt-2 text-sm text-red-400">{{ $message }}</p>@enderror
    @error('deposit_rate')<p class="mt-2 text-sm text-red-400">{{ $message }}</p>@enderror
</div>
