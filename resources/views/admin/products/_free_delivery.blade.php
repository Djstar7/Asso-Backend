{{--
    Livraison gratuite du produit : suit la boutique (vide), offerte (1) ou
    payée par le client (0). L'acheteur voit alors les prix de livraison barrés ;
    la course est retenue sur la part du vendeur.
--}}
@php
    $current = old('free_delivery', isset($product) && $product->free_delivery !== null ? ($product->free_delivery ? '1' : '0') : '');
    $shopFree = isset($product) && $product->shop?->free_delivery;
    $choices = [
        '' => ['Suivre la boutique', isset($product) && $product->shop
            ? ($shopFree ? 'La boutique offre la livraison' : 'La boutique ne l\'offre pas')
            : 'Réglage de la boutique choisie', 'fa-store'],
        '1' => ['Offerte', 'Le client ne paie pas la livraison', 'fa-gift'],
        '0' => ['Payée par le client', 'Même si la boutique l\'offre', 'fa-receipt'],
    ];
@endphp
<div class="bg-dark-100 rounded-xl shadow-lg p-6">
    <h2 class="text-lg font-semibold text-white mb-1 flex items-center">
        <i class="fas fa-truck text-green-500 mr-2"></i>
        Livraison gratuite
    </h2>
    <p class="text-xs text-gray-400 mb-4">
        Le prix de la course est retenu sur la vente. Si elle coûte plus que la vente, le client la paie.
        Pour un produit en gros, seule la livraison depuis Douala est offerte.
    </p>

    <div class="space-y-2">
        @foreach($choices as $value => [$label, $hint, $icon])
            <label class="flex items-center p-3 border border-dark-200 rounded-lg cursor-pointer hover:bg-dark-50 has-[:checked]:border-green-500 has-[:checked]:bg-green-900/10">
                <input type="radio" name="free_delivery" value="{{ $value }}" {{ (string) $current === (string) $value ? 'checked' : '' }}
                       class="mr-3 text-green-500 focus:ring-green-500">
                <div>
                    <div class="font-medium text-white"><i class="fas {{ $icon }} mr-2 text-green-500"></i>{{ $label }}</div>
                    <div class="text-xs text-gray-400">{{ $hint }}</div>
                </div>
            </label>
        @endforeach
    </div>
    @error('free_delivery')<p class="mt-2 text-sm text-red-400">{{ $message }}</p>@enderror
</div>
