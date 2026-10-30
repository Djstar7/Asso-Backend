<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBook;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class ProductBookService
{
    /**
     * Règles de validation à fusionner avec celles du produit :
     *   $request->validate([... ] + ProductBookService::rules())
     * L'auteur n'est obligatoire que si la catégorie choisie est une catégorie bibliothèque.
     */
    public static function rules(): array
    {
        $isLibrary = fn () => Category::where('id', request('category_id'))
            ->where('is_library', true)
            ->exists();

        return [
            'book' => ['nullable', 'array'],
            'book.author' => [Rule::requiredIf($isLibrary), 'nullable', 'string', 'max:255'],
            'book.isbn' => ['nullable', 'string', 'max:20'],
            'book.publisher' => ['nullable', 'string', 'max:255'],
            'book.language' => ['nullable', 'string', 'max:5'],
            'book.pages' => ['nullable', 'integer', 'min:1', 'max:20000'],
            'book.format' => ['nullable', 'in:' . implode(',', ProductBook::FORMATS)],
            'book.published_year' => ['nullable', 'integer', 'min:1000', 'max:2100'],
        ];
    }

    /**
     * Crée / met à jour la fiche livre d'un produit.
     * Si le produit n'est plus dans une catégorie bibliothèque, la fiche est supprimée.
     */
    public function sync(Product $product, ?array $data): void
    {
        $isLibrary = (bool) Category::whereKey($product->category_id)->value('is_library');

        if (!$isLibrary) {
            $product->book()->delete();
            return;
        }

        if (empty($data['author'])) {
            return;
        }

        $attributes = Arr::only($data, ['author', 'isbn', 'publisher', 'language', 'pages', 'format', 'published_year']);
        $attributes['language'] = $attributes['language'] ?? 'fr';

        $product->book()->updateOrCreate([], $attributes);
    }
}
