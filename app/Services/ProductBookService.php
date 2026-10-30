<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBook;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * Fiche livre d'un produit rangé dans un rayon de la Bibliothèque
 * (catégorie `is_library`).
 */
class ProductBookService
{
    public const FIELDS = ['author', 'isbn', 'publisher', 'language', 'pages', 'format', 'published_year'];

    /**
     * Règles à fusionner avec celles du produit.
     *
     * L'auteur est exigé quand la catégorie retenue est un rayon de la
     * Bibliothèque et que `$requireAuthor` est vrai : à la création, ou à la
     * modification quand la fiche livre est envoyée ou que le produit change
     * de catégorie (modifier seulement le prix d'un livre ne l'exige pas).
     */
    public static function rules(?int $categoryId, bool $requireAuthor = true): array
    {
        $required = $requireAuthor && self::isLibraryCategory($categoryId);

        return [
            'book' => ['nullable', 'array'],
            'book.author' => [Rule::requiredIf($required), 'nullable', 'string', 'max:255'],
            'book.isbn' => ['nullable', 'string', 'max:20'],
            'book.publisher' => ['nullable', 'string', 'max:255'],
            'book.language' => ['nullable', 'in:' . implode(',', ProductBook::LANGUAGES)],
            'book.pages' => ['nullable', 'integer', 'min:1', 'max:20000'],
            'book.format' => ['nullable', 'in:' . implode(',', ProductBook::FORMATS)],
            'book.published_year' => ['nullable', 'integer', 'min:1000', 'max:2100'],
        ];
    }

    public static function isLibraryCategory(?int $categoryId): bool
    {
        return $categoryId !== null
            && Category::whereKey($categoryId)->where('is_library', true)->exists();
    }

    /**
     * Crée ou met à jour la fiche livre. Un produit sorti de la Bibliothèque
     * perd sa fiche ; sans auteur fourni, la fiche existante est conservée.
     */
    public function sync(Product $product, ?array $data): void
    {
        if (! self::isLibraryCategory($product->category_id)) {
            $product->book()->delete();

            return;
        }

        if (empty($data['author'])) {
            return;
        }

        $attributes = Arr::only($data, self::FIELDS);
        $attributes['isbn'] = ProductBook::normalizeIsbn($attributes['isbn'] ?? null);
        $attributes['language'] = $attributes['language'] ?? 'fr';

        $product->book()->updateOrCreate([], $attributes);
    }
}
