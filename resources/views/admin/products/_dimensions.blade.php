<div class="mb-4" id="general_dimensions">
    <h3 class="block text-sm font-medium text-white mb-2">Dimensions du produit (facultatif)</h3>
    <div class="grid grid-cols-2 gap-3">
        @foreach(['length_cm' => 'Longueur', 'width_cm' => 'Largeur'] as $field => $label)
            <div>
                <label for="general_{{ $field }}" class="block text-xs text-gray-400 mb-1">{{ $label }} (cm)</label>
                <input type="number" name="{{ $field }}" id="general_{{ $field }}" data-general-dimension
                       value="{{ old($field, isset($product) ? $product->$field : null) }}"
                       min="0.01" max="999999.99" step="0.01" inputmode="decimal" placeholder="{{ $field === 'length_cm' ? '120' : '100' }}"
                       class="w-full px-4 py-2 bg-dark-50 border border-dark-300 rounded-lg text-white @error($field) border-red-500 @enderror">
                @error($field)<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
            </div>
        @endforeach
    </div>
    <p class="mt-1 text-xs text-gray-400">Pour plusieurs formats, ajoutez une option « Dimensions » dans les variantes.</p>
</div>
