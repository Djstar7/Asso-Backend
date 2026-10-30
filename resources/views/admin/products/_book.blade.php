@php
    // Fonctionne dans create (pas de $product) et edit ($product existant).
    $book = isset($product) ? $product->book : null;
    $inputClass = 'w-full px-4 py-2 bg-dark-50 border border-dark-300 text-white rounded-lg focus:ring-2 focus:ring-primary-500';
@endphp

<!-- Détails du livre : visible seulement pour une catégorie bibliothèque -->
<div class="bg-dark-100 rounded-xl shadow-lg p-6" id="book_section" style="display:none;">
    <h3 class="text-lg font-bold text-white mb-4 flex items-center">
        <i class="fas fa-book text-primary-500 mr-2"></i>
        Détails du livre
    </h3>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="md:col-span-2">
            <label class="block text-sm font-medium text-white mb-2">Auteur <span class="text-red-500">*</span></label>
            <input type="text" name="book[author]" id="book_author" value="{{ old('book.author', $book?->author) }}"
                   class="{{ $inputClass }} @error('book.author') border-red-500 @enderror" placeholder="Ex : Chimamanda Ngozi Adichie">
            @error('book.author')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-white mb-2">ISBN</label>
            <input type="text" name="book[isbn]" value="{{ old('book.isbn', $book?->isbn) }}"
                   class="{{ $inputClass }} @error('book.isbn') border-red-500 @enderror" placeholder="978-2-07-036822-5">
            @error('book.isbn')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-white mb-2">Éditeur</label>
            <input type="text" name="book[publisher]" value="{{ old('book.publisher', $book?->publisher) }}"
                   class="{{ $inputClass }}" placeholder="Ex : Gallimard">
            @error('book.publisher')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-white mb-2">Langue</label>
            @php $lang = old('book.language', $book?->language ?? 'fr'); @endphp
            <select name="book[language]" class="{{ $inputClass }}">
                <option value="fr" @selected($lang === 'fr')>Français</option>
                <option value="en" @selected($lang === 'en')>Anglais</option>
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium text-white mb-2">Format</label>
            @php $fmt = old('book.format', $book?->format); @endphp
            <select name="book[format]" class="{{ $inputClass }}">
                <option value="">Non précisé</option>
                @foreach(\App\Models\ProductBook::FORMAT_LABELS as $value => $label)
                    <option value="{{ $value }}" @selected($fmt === $value)>{{ $label }}</option>
                @endforeach
            </select>
            @error('book.format')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-white mb-2">Nombre de pages</label>
            <input type="number" name="book[pages]" min="1" max="20000" value="{{ old('book.pages', $book?->pages) }}"
                   class="{{ $inputClass }} @error('book.pages') border-red-500 @enderror">
            @error('book.pages')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-white mb-2">Année de publication</label>
            <input type="number" name="book[published_year]" min="1000" max="2100" value="{{ old('book.published_year', $book?->published_year) }}"
                   class="{{ $inputClass }} @error('book.published_year') border-red-500 @enderror" placeholder="2024">
            @error('book.published_year')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
        </div>
    </div>
</div>

@push('scripts')
<script>
// Affiche le bloc « Détails du livre » quand la catégorie choisie est une catégorie bibliothèque.
function toggleBookSection() {
    const select = document.getElementById('category_id');
    const section = document.getElementById('book_section');
    if (!select || !section) return;

    const option = select.options[select.selectedIndex];
    const isLibrary = option?.dataset.library === '1';

    section.style.display = isLibrary ? 'block' : 'none';
    // Champs désactivés quand le bloc est masqué : rien n'est envoyé au serveur.
    section.querySelectorAll('input, select').forEach(el => el.disabled = !isLibrary);

    const author = document.getElementById('book_author');
    if (author) author.required = isLibrary;
}

document.addEventListener('DOMContentLoaded', toggleBookSection);
</script>
@endpush
