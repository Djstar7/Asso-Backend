<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Fiche livre d'un produit de la Bibliothèque. */
class ProductBook extends Model
{
    public const FORMATS = ['paperback', 'hardcover', 'ebook', 'audio'];

    public const LANGUAGES = ['fr', 'en'];

    protected $fillable = [
        'product_id', 'author', 'isbn', 'publisher',
        'language', 'pages', 'format', 'published_year',
    ];

    protected $casts = [
        'pages' => 'integer',
        'published_year' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** ISBN sans tirets ni espaces, pour que « 978-2-07… » et « 978207… » se retrouvent. */
    public static function normalizeIsbn(mixed $isbn): ?string
    {
        $isbn = strtoupper(preg_replace('/[^0-9Xx]/', '', (string) $isbn) ?? '');

        return $isbn !== '' ? $isbn : null;
    }

    public static function formatLabel(?string $format, ?string $locale = null): ?string
    {
        return $format && in_array($format, self::FORMATS, true)
            ? __('library.formats.' . $format, [], $locale)
            : null;
    }

    public static function languageLabel(?string $language, ?string $locale = null): ?string
    {
        return $language && in_array($language, self::LANGUAGES, true)
            ? __('library.languages.' . $language, [], $locale)
            : $language;
    }

    /** Format présenté dans l'API (réutilisé par formatProduct). */
    public function toApi(): array
    {
        return [
            'author' => $this->author,
            'isbn' => $this->isbn,
            'publisher' => $this->publisher,
            'language' => $this->language,
            'language_label' => self::languageLabel($this->language),
            'pages' => $this->pages,
            'format' => $this->format,
            'format_label' => self::formatLabel($this->format),
            'published_year' => $this->published_year,
        ];
    }
}
