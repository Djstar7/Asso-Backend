{{--
    Champ saisi en français, avec sa version dans les autres langues (onglets).
    Le français est la référence ; une langue laissée vide affiche le français.

    <x-admin.translatable name="description" label="Description" :model="$package" type="textarea" />
    <x-admin.translatable name="app_slogan" field="value" label="Slogan" :model="$generalSettings['app_slogan']" />
--}}
@props([
    'name',
    'label',
    'model' => null,
    'type' => 'text',
    'required' => false,
    'placeholder' => '',
    'rows' => 4,
    'help' => null,
    'field' => null,
])

@php
    $locales = \App\Support\Translation\ContentLocale::targets();
    // Champ du modèle qui porte la valeur (« value » pour un réglage), par défaut le nom du champ.
    $field ??= $name;
    $source = old($name, $model ? $model->getTranslation($field, 'fr') : null);
    $values = [];
    foreach ($locales as $locale) {
        $values[$locale] = old("translations.{$locale}.{$name}", $model ? $model->getTranslation($field, $locale) : null);
    }
    $inputClass = 'w-full px-4 py-2 bg-dark-50 border border-dark-200 rounded-lg text-gray-100 focus:ring-2 focus:ring-blue-500';
    $localeNames = ['en' => 'anglaise'];
@endphp

<div x-data="{ lang: 'fr' }" @invalid.capture="lang = 'fr'" {{ $attributes->merge(['class' => '']) }}>
    <div class="flex items-center justify-between gap-3 mb-2">
        <label for="{{ $name }}" class="block text-sm font-medium text-gray-300">
            {{ $label }} @if($required)<span class="text-red-500">*</span>@endif
        </label>
        <div class="inline-flex rounded-md border border-dark-200 overflow-hidden text-xs font-semibold" role="tablist" aria-label="Langue du champ {{ $label }}">
            <button type="button" role="tab" @click="lang = 'fr'" :aria-selected="lang === 'fr'"
                :class="lang === 'fr' ? 'bg-primary-600 text-white' : 'text-gray-400 hover:text-gray-200'"
                class="px-3 py-1">FR</button>
            @foreach($locales as $locale)
                <button type="button" role="tab" @click="lang = '{{ $locale }}'" :aria-selected="lang === '{{ $locale }}'"
                    :class="lang === '{{ $locale }}' ? 'bg-primary-600 text-white' : 'text-gray-400 hover:text-gray-200'"
                    class="px-3 py-1 inline-flex items-center gap-1">
                    {{ strtoupper($locale) }}
                    @if(filled($source) && blank($values[$locale]))
                        <span class="w-1.5 h-1.5 rounded-full bg-amber-400" title="Traduction manquante"></span>
                    @endif
                </button>
            @endforeach
        </div>
    </div>

    <div x-show="lang === 'fr'">
        @if($type === 'textarea')
            <textarea id="{{ $name }}" name="{{ $name }}" rows="{{ $rows }}" @if($required) required @endif
                class="{{ $inputClass }} @error($name) border-red-500 @enderror"
                placeholder="{{ $placeholder }}">{{ $source }}</textarea>
        @else
            <input type="text" id="{{ $name }}" name="{{ $name }}" value="{{ $source }}" @if($required) required @endif
                class="{{ $inputClass }} @error($name) border-red-500 @enderror"
                placeholder="{{ $placeholder }}">
        @endif
        @error($name)
            <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
        @enderror
    </div>

    @foreach($locales as $locale)
        <div x-show="lang === '{{ $locale }}'" x-cloak>
            @if($type === 'textarea')
                <textarea id="{{ $name }}_{{ $locale }}" name="translations[{{ $locale }}][{{ $name }}]" rows="{{ $rows }}"
                    class="{{ $inputClass }} @error("translations.{$locale}.{$name}") border-red-500 @enderror"
                    placeholder="Version {{ $localeNames[$locale] ?? $locale }} (facultatif)">{{ $values[$locale] }}</textarea>
            @else
                <input type="text" id="{{ $name }}_{{ $locale }}" name="translations[{{ $locale }}][{{ $name }}]" value="{{ $values[$locale] }}"
                    class="{{ $inputClass }} @error("translations.{$locale}.{$name}") border-red-500 @enderror"
                    placeholder="Version {{ $localeNames[$locale] ?? $locale }} (facultatif)">
            @endif
            <p class="text-xs text-gray-500 mt-1">Laissé vide, le texte français s'affiche.</p>
            @error("translations.{$locale}.{$name}")
                <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
            @enderror
        </div>
    @endforeach

    @if($help)
        <p class="text-xs text-gray-500 mt-1">{{ $help }}</p>
    @endif
</div>
