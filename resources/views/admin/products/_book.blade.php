@php
    // Inclus seulement en mode Bibliothèque (création ou modification d'un livre).
    $book = isset($product) ? $product->book : null;
    $inputClass = 'w-full px-4 py-2 bg-dark-50 border border-dark-300 text-white rounded-lg focus:ring-2 focus:ring-primary-500';
    $lang = old('book.language', $book?->language ?? 'fr');
    $fmt = old('book.format', $book?->format);
@endphp

<div class="bg-dark-100 rounded-xl shadow-lg p-6" id="book_section">
    <h3 class="text-lg font-bold text-white mb-4 flex items-center">
        <i class="fas fa-book text-primary-500 mr-2"></i>
        Détails du livre
    </h3>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="md:col-span-2">
            <label for="book_author" class="block text-sm font-medium text-white mb-2">Auteur <span class="text-red-500">*</span></label>
            <input type="text" name="book[author]" id="book_author" required value="{{ old('book.author', $book?->author) }}"
                   class="{{ $inputClass }} @error('book.author') border-red-500 @enderror" placeholder="Ex : Chimamanda Ngozi Adichie">
            @error('book.author')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="book_isbn" class="block text-sm font-medium text-white mb-2">ISBN</label>
            <input type="text" name="book[isbn]" id="book_isbn" value="{{ old('book.isbn', $book?->isbn) }}"
                   class="{{ $inputClass }} @error('book.isbn') border-red-500 @enderror" placeholder="978-2-07-036822-5">
            @error('book.isbn')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="book_publisher" class="block text-sm font-medium text-white mb-2">Éditeur</label>
            <input type="text" name="book[publisher]" id="book_publisher" value="{{ old('book.publisher', $book?->publisher) }}"
                   class="{{ $inputClass }}" placeholder="Ex : Gallimard">
            @error('book.publisher')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="book_language" class="block text-sm font-medium text-white mb-2">Langue</label>
            <select name="book[language]" id="book_language" class="{{ $inputClass }}">
                @foreach(\App\Models\ProductBook::LANGUAGES as $value)
                    <option value="{{ $value }}" @selected($lang === $value)>{{ \App\Models\ProductBook::languageLabel($value, 'fr') }}</option>
                @endforeach
            </select>
            @error('book.language')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="book_format" class="block text-sm font-medium text-white mb-2">Format</label>
            <select name="book[format]" id="book_format" class="{{ $inputClass }}">
                <option value="">Non précisé</option>
                @foreach(\App\Models\ProductBook::FORMATS as $value)
                    <option value="{{ $value }}" @selected($fmt === $value)>{{ \App\Models\ProductBook::formatLabel($value, 'fr') }}</option>
                @endforeach
            </select>
            @error('book.format')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="book_pages" class="block text-sm font-medium text-white mb-2">Nombre de pages</label>
            <input type="number" name="book[pages]" id="book_pages" min="1" max="20000" value="{{ old('book.pages', $book?->pages) }}"
                   class="{{ $inputClass }} @error('book.pages') border-red-500 @enderror">
            @error('book.pages')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="book_year" class="block text-sm font-medium text-white mb-2">Année de publication</label>
            <input type="number" name="book[published_year]" id="book_year" min="1000" max="2100" value="{{ old('book.published_year', $book?->published_year) }}"
                   class="{{ $inputClass }} @error('book.published_year') border-red-500 @enderror" placeholder="2024">
            @error('book.published_year')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
        </div>
    </div>
</div>
