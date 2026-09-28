{{--
    Livraison gratuite du produit : un seul interrupteur, offerte ou non.
    L'acheteur voit alors les prix de livraison barrés ; la course est retenue
    sur la part du vendeur.
--}}
@php
    $checked = (bool) old('free_delivery', isset($product) ? $product->hasFreeDelivery() : false);
@endphp
<div class="bg-dark-100 rounded-xl shadow-lg p-6">
    <label for="free_delivery" class="flex items-center justify-between gap-4 cursor-pointer">
        <div>
            <h2 class="text-lg font-semibold text-white flex items-center">
                <i class="fas fa-truck text-green-500 mr-2"></i>
                Livraison gratuite
            </h2>
            <p class="text-xs text-gray-400 mt-1">Offerte au client, retenue sur la vente.</p>
        </div>
        <span class="relative inline-flex flex-shrink-0">
            <input type="hidden" name="free_delivery" value="0">
            <input type="checkbox" name="free_delivery" id="free_delivery" value="1" {{ $checked ? 'checked' : '' }} class="peer sr-only">
            <span class="h-8 w-14 rounded-full bg-gray-600 transition-colors peer-checked:bg-green-600 peer-focus-visible:ring-2 peer-focus-visible:ring-green-400"></span>
            <span class="absolute left-1 top-1 h-6 w-6 rounded-full bg-white shadow transition-transform peer-checked:translate-x-6"></span>
        </span>
    </label>
    @error('free_delivery')<p class="mt-2 text-sm text-red-400">{{ $message }}</p>@enderror
</div>
