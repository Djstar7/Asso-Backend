<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductBook extends Model
{
    public const FORMATS = ['paperback', 'hardcover', 'ebook', 'audio'];

    public const FORMAT_LABELS = [
        'paperback' => 'Broché',
        'hardcover' => 'Relié',
        'ebook' => 'E-book',
        'audio' => 'Livre audio',
    ];

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

    /** Format présenté dans l'API (réutilisé par formatProduct). */
    public function toApi(): array
    {
        return [
            'author' => $this->author,
            'isbn' => $this->isbn,
            'publisher' => $this->publisher,
            'language' => $this->language,
            'pages' => $this->pages,
            'format' => $this->format,
            'format_label' => self::FORMAT_LABELS[$this->format] ?? null,
            'published_year' => $this->published_year,
        ];
    }
}
