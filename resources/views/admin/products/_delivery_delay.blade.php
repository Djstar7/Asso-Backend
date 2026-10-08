{{--
    Délai de livraison annoncé au client, en jours ouvrables (1 à 20).
    Vide = délai de la catégorie, sinon le délai par défaut (Partenaires de livraison).
--}}
@php
    $inherited = \App\Support\DeliveryDelay::forCategory(isset($product) ? $product->category : null);
    $min = old('delivery_days_min', isset($product) ? $product->delivery_days_min : null);
    $max = old('delivery_days_max', isset($product) ? $product->delivery_days_max : null);
@endphp
<div class="bg-dark-100 rounded-xl shadow-lg p-6">
    <h2 class="text-lg font-semibold text-white flex items-center">
        <i class="fas fa-clock text-primary-500 mr-2"></i>
        Délai de livraison
    </h2>
    <p class="text-xs text-gray-400 mt-1">
        En jours ouvrables, de 1 à 20. Vide = {{ $inherited['source'] === 'category' ? 'délai de la catégorie' : 'délai par défaut' }}
        ({{ $inherited['min'] }} à {{ $inherited['max'] }} j).
    </p>
    <div class="grid grid-cols-2 gap-3 mt-4">
        <div>
            <label for="delivery_days_min" class="block text-sm text-gray-300 mb-1">Minimum</label>
            <input type="number" name="delivery_days_min" id="delivery_days_min" min="1" max="20" step="1"
                   value="{{ $min }}" placeholder="{{ $inherited['min'] }}"
                   class="w-full px-3 py-2 bg-dark-50 border border-dark-300 rounded-lg text-white focus:border-primary-500">
        </div>
        <div>
            <label for="delivery_days_max" class="block text-sm text-gray-300 mb-1">Maximum</label>
            <input type="number" name="delivery_days_max" id="delivery_days_max" min="1" max="20" step="1"
                   value="{{ $max }}" placeholder="{{ $inherited['max'] }}"
                   class="w-full px-3 py-2 bg-dark-50 border border-dark-300 rounded-lg text-white focus:border-primary-500">
        </div>
    </div>
    @error('delivery_days_min')<p class="mt-2 text-sm text-red-400">{{ $message }}</p>@enderror
    @error('delivery_days_max')<p class="mt-2 text-sm text-red-400">{{ $message }}</p>@enderror
</div>
