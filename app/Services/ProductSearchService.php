<?php

namespace App\Services;

use App\Models\Product;
use App\Models\SearchSynonym;
use Illuminate\Database\Eloquent\Builder;

/**
 * Recherche du catalogue : chaque mot saisi doit être retrouvé, quel que soit
 * son ordre, dans le produit, sa catégorie ou sa boutique.
 *
 * Accents et casse sont ignorés des deux côtés de la comparaison, les synonymes
 * élargissent chaque mot, et une faute de frappe sur un mot assez long reste
 * tolérée par similarité sur le nom. Hors PostgreSQL (SQLite en test), la
 * recherche retombe sur LIKE, sans tolérance aux fautes.
 */
class ProductSearchService
{
    private const MAX_TERMS = 8;

    /** En dessous, deux mots sans rapport se ressemblent trop (« robe » / « rouge »). */
    private const FUZZY_MIN_LENGTH = 5;

    private const FUZZY_THRESHOLD = 0.45;

    /** @return list<string> */
    public function terms(string $search): array
    {
        $words = preg_split('/\s+/u', mb_strtolower(trim($search)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_slice(array_values(array_unique($words)), 0, self::MAX_TERMS);
    }

    public function apply(Builder $query, string $search): void
    {
        foreach ($this->terms($search) as $term) {
            $alternatives = SearchSynonym::getRelatedTerms($term);

            $query->where(function (Builder $q) use ($alternatives) {
                foreach ($alternatives as $alternative) {
                    $this->matchTerm($q, (string) $alternative);
                }
            });
        }
    }

    /** Nom exact, puis début du nom, puis nom contenant la saisie, puis le reste. */
    public function orderByRelevance(Builder $query, string $search): void
    {
        $search = trim($search);
        $name = $this->normalized('products.name');
        $value = $this->normalized('?');
        $like = $this->likeOperator();

        $score = "CASE WHEN {$name} {$like} {$value} ESCAPE '!' THEN 100"
            ." WHEN {$name} {$like} {$value} ESCAPE '!' THEN 80"
            ." WHEN {$name} {$like} {$value} ESCAPE '!' THEN 60 ELSE 0 END";
        $bindings = [$this->escape($search), $this->escape($search).'%', '%'.$this->escape($search).'%'];

        foreach ($this->terms($search) as $term) {
            $score .= " + CASE WHEN {$name} {$like} {$value} ESCAPE '!' THEN 10 ELSE 0 END";
            $bindings[] = '%'.$this->escape($term).'%';
        }

        if ($this->isPostgres()) {
            $score .= " + similarity({$name}, {$value}) * 40";
            $bindings[] = $search;
        }

        $query->orderByRaw("({$score}) DESC", $bindings)->orderByDesc('products.created_at');
    }

    private function matchTerm(Builder $query, string $term): void
    {
        $pattern = '%'.$this->escape($term).'%';
        $value = $this->normalized('?');
        $like = $this->likeOperator();
        $contains = fn (string $column) => $this->normalized($column)." {$like} {$value} ESCAPE '!'";

        $query->orWhereRaw($contains('products.name'), [$pattern])
            ->orWhereRaw($contains('products.description'), [$pattern])
            ->orWhereRaw($contains('products.characteristics'), [$pattern])
            ->orWhereHas('translations', fn (Builder $t) => $t->whereIn('field', ['name', 'description'])
                ->whereRaw($contains('value'), [$pattern]))
            ->orWhereHas('category', fn (Builder $c) => $c->whereRaw($contains('categories.name'), [$pattern])
                ->orWhereRaw($contains('categories.name_en'), [$pattern]))
            ->orWhereHas('subcategory', fn (Builder $s) => $s->whereRaw($contains('subcategories.name'), [$pattern]))
            ->orWhereHas('shop', fn (Builder $s) => $s->whereRaw($contains('shops.name'), [$pattern]))
            // Livres : on cherche aussi par auteur, éditeur ou ISBN.
            ->orWhereHas('book', fn (Builder $b) => $b->whereRaw($contains('product_books.author'), [$pattern])
                ->orWhereRaw($contains('product_books.publisher'), [$pattern])
                ->orWhereRaw($contains('product_books.isbn'), [$pattern]));

        if ($this->isPostgres() && mb_strlen($term) >= self::FUZZY_MIN_LENGTH) {
            $query->orWhereRaw(
                'word_similarity(f_unaccent(?), f_unaccent(products.name)) > ?',
                [$term, self::FUZZY_THRESHOLD]
            );
        }
    }

    private function normalized(string $expression): string
    {
        return $this->isPostgres() ? "f_unaccent({$expression})" : "LOWER({$expression})";
    }

    private function likeOperator(): string
    {
        return $this->isPostgres() ? 'ILIKE' : 'LIKE';
    }

    /**
     * « 100% » ou « a_b » se cherchent tels quels, sans agir comme jokers. Pas de
     * barre oblique comme échappement : PDO lit mal ESCAPE '\\' sur PostgreSQL.
     */
    private function escape(string $value): string
    {
        return strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']);
    }

    private function isPostgres(): bool
    {
        return Product::query()->getConnection()->getDriverName() === 'pgsql';
    }
}
