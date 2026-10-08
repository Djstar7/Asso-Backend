{{-- Avantages d'une certification, en français et dans les autres langues. --}}
@php
    $locales = \App\Support\Translation\ContentLocale::targets();
    $lists = ['fr' => old('benefits', $package?->getTranslation('benefits', 'fr') ?? [])];
    foreach ($locales as $locale) {
        $lists[$locale] = old("translations.{$locale}.benefits", $package?->getTranslation('benefits', $locale) ?? []);
    }
@endphp
<div id="benefits_field" class="col-span-2 hidden" x-data="{ lang: 'fr' }">
    <div class="flex items-center justify-between gap-3 mb-2">
        <label class="block text-sm font-medium text-gray-300">Bénéfices</label>
        <div class="inline-flex rounded-md border border-dark-200 overflow-hidden text-xs font-semibold" role="tablist">
            @foreach(array_keys($lists) as $locale)
                <button type="button" role="tab" @click="lang = '{{ $locale }}'"
                    :class="lang === '{{ $locale }}' ? 'bg-primary-600 text-white' : 'text-gray-400 hover:text-gray-200'"
                    class="px-3 py-1">{{ strtoupper($locale) }}</button>
            @endforeach
        </div>
    </div>
    @foreach($lists as $locale => $items)
        @php $inputName = $locale === 'fr' ? 'benefits[]' : "translations[{$locale}][benefits][]"; @endphp
        <div x-show="lang === '{{ $locale }}'" @if($locale !== 'fr') x-cloak @endif>
            <div id="benefits_container_{{ $locale }}" class="space-y-2" data-input-name="{{ $inputName }}">
                @foreach($items ?? [] as $benefit)
                    <div class="benefit-item flex gap-2">
                        <input type="text" name="{{ $inputName }}" value="{{ $benefit }}"
                            class="flex-1 px-4 py-2 bg-dark-50 border border-dark-200 rounded-lg text-gray-100 focus:ring-2 focus:ring-blue-500"
                            placeholder="Ex: Badge de certification visible">
                        <button type="button" onclick="removeBenefit(this)"
                            class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg transition-colors">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                @endforeach
            </div>
            <button type="button" onclick="addBenefit('{{ $locale }}')"
                class="mt-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors">
                <i class="fas fa-plus mr-2"></i>
                Ajouter un bénéfice
            </button>
            @if($locale !== 'fr')
                <p class="text-xs text-gray-500 mt-1">Laissée vide, la liste française s'affiche.</p>
            @endif
        </div>
    @endforeach
    @error('benefits')
        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
    @enderror
</div>
