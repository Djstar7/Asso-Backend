{{-- Contenu de la page : un éditeur par langue. Le français est obligatoire. --}}
@php
    $contentLocales = array_merge(['fr'], \App\Support\Translation\ContentLocale::targets());
@endphp
<div x-data="{ lang: 'fr' }">
    <div class="flex items-center justify-between gap-3 mb-2">
        <label class="block text-sm font-medium text-gray-300">
            Contenu <span class="text-red-500">*</span>
        </label>
        <div class="inline-flex rounded-md border border-dark-200 overflow-hidden text-xs font-semibold" role="tablist">
            @foreach($contentLocales as $locale)
                <button type="button" role="tab" @click="lang = '{{ $locale }}'"
                    :class="lang === '{{ $locale }}' ? 'bg-primary-600 text-white' : 'text-gray-400 hover:text-gray-200'"
                    class="px-3 py-1 inline-flex items-center gap-1">
                    {{ strtoupper($locale) }}
                    @if($locale !== 'fr' && $legalPage && blank($legalPage->getTranslation('content', $locale)))
                        <span class="w-1.5 h-1.5 rounded-full bg-amber-400" title="Traduction manquante"></span>
                    @endif
                </button>
            @endforeach
        </div>
    </div>
    @foreach($contentLocales as $locale)
        @php
            $field = $locale === 'fr' ? 'content' : "translations[{$locale}][content]";
            $oldKey = $locale === 'fr' ? 'content' : "translations.{$locale}.content";
        @endphp
        <div x-show="lang === '{{ $locale }}'" @if($locale !== 'fr') x-cloak @endif>
            <div class="legal-editor" id="editor-{{ $locale }}" data-input="content-{{ $locale }}" data-required="{{ $locale === 'fr' ? 1 : 0 }}"
                data-initial="{{ old($oldKey, $legalPage?->getTranslation('content', $locale)) }}"></div>
            <input type="hidden" name="{{ $field }}" id="content-{{ $locale }}">
            @if($locale === 'fr')
                <p class="mt-1 text-xs text-gray-400">Utilisez les outils de mise en forme ci-dessus pour rédiger le contenu</p>
            @else
                <p class="mt-1 text-xs text-gray-400">Version anglaise facultative : laissée vide, le texte français s'affiche.</p>
            @endif
            @error($oldKey)
                <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
            @enderror
        </div>
    @endforeach
</div>
