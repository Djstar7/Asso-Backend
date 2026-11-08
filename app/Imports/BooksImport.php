<?php

namespace App\Imports;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBook;
use App\Models\Shop;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Import de livres dans une boutique, depuis un CSV ou un classeur Excel.
 *
 * Colonnes (ligne d'en-tête) : titre, auteur, prix, rayon, genre, isbn,
 * editeur, langue, format, pages, annee, stock, poids, description.
 * « categorie » est accepté à la place de « rayon » (anciens modèles).
 *
 * Un livre dont l'ISBN existe déjà dans la boutique est mis à jour ; chaque
 * ligne est traitée seule, une erreur n'empêche pas les suivantes.
 */
class BooksImport implements ToCollection, WithHeadingRow, WithCustomCsvSettings
{
    public const COLUMNS = ['titre', 'auteur', 'prix', 'rayon', 'genre', 'isbn', 'editeur', 'langue', 'format', 'pages', 'annee', 'stock', 'poids', 'description'];

    /** Poids d'un livre courant, en kg, quand la colonne est vide (livraison). */
    public const DEFAULT_WEIGHT_KG = 0.4;

    public int $created = 0;

    public int $updated = 0;

    /** @var array<int, array{line:int, message:string}> */
    public array $errors = [];

    public function __construct(
        private readonly Shop $shop,
        private readonly string $delimiter = ',',
    ) {}

    public function getCsvSettings(): array
    {
        return ['delimiter' => $this->delimiter, 'input_encoding' => 'UTF-8'];
    }

    public function collection(Collection $rows): void
    {
        // Rayons (et leurs genres) retrouvés par nom ou slug, sans tenir compte de la casse.
        $shelves = [];
        foreach (Category::where('is_library', true)->with('subcategories')->get() as $category) {
            $shelves[Str::lower($category->name)] = $category;
            $shelves[Str::lower($category->slug)] = $category;
        }

        foreach ($rows as $index => $row) {
            $line = $index + 2; // en-tête + index à partir de 0
            $data = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $row->toArray());
            $data['rayon'] = $data['rayon'] ?? $data['categorie'] ?? null;
            foreach (['prix', 'poids'] as $decimal) {
                if (is_string($data[$decimal] ?? null)) {
                    $data[$decimal] = str_replace([' ', ','], ['', '.'], $data[$decimal]);
                }
            }

            if (collect($data)->filter(fn ($v) => $v !== null && $v !== '')->isEmpty()) {
                continue;
            }

            $validator = Validator::make($data, [
                'titre' => 'required|string|max:255',
                'auteur' => 'required|string|max:255',
                'prix' => 'required|numeric|min:0',
                'rayon' => 'required|string',
                'genre' => 'nullable|string',
                'isbn' => 'nullable|max:20',
                'editeur' => 'nullable|string|max:255',
                'langue' => 'nullable|in:' . implode(',', ProductBook::LANGUAGES),
                'format' => 'nullable|in:' . implode(',', ProductBook::FORMATS),
                'pages' => 'nullable|integer|min:1|max:20000',
                'annee' => 'nullable|integer|min:1000|max:2100',
                'stock' => 'nullable|integer|min:0',
                'poids' => 'nullable|numeric|min:0.001',
            ], [], [
                'titre' => 'titre', 'auteur' => 'auteur', 'prix' => 'prix', 'rayon' => 'rayon',
            ]);

            if ($validator->fails()) {
                $this->errors[] = ['line' => $line, 'message' => $validator->errors()->first()];
                continue;
            }

            $shelf = $shelves[Str::lower($data['rayon'])] ?? null;
            if (! $shelf) {
                $this->errors[] = ['line' => $line, 'message' => "Rayon introuvable : « {$data['rayon']} »"];
                continue;
            }

            $genre = null;
            if (! empty($data['genre'])) {
                $genre = $shelf->subcategories->first(fn ($s) => in_array(Str::lower($data['genre']), [Str::lower($s->name), Str::lower($s->slug)], true));
                if (! $genre) {
                    $this->errors[] = ['line' => $line, 'message' => "Genre « {$data['genre']} » introuvable dans le rayon {$shelf->name}"];
                    continue;
                }
            }

            try {
                DB::transaction(fn () => $this->upsert($data, $shelf, $genre?->id));
            } catch (\Throwable $e) {
                report($e);
                $this->errors[] = ['line' => $line, 'message' => 'Erreur inattendue : ' . $e->getMessage()];
            }
        }
    }

    private function upsert(array $data, Category $shelf, ?int $genreId): void
    {
        $isbn = ProductBook::normalizeIsbn($data['isbn'] ?? null);
        // Colonne absente du fichier ou cellule vide : rien à appliquer.
        $filled = fn (string $column) => isset($data[$column]) && $data[$column] !== '';

        $existing = $isbn
            ? ProductBook::where('isbn', $isbn)
                ->whereHas('product', fn ($q) => $q->where('shop_id', $this->shop->id))
                ->first()
            : null;

        if ($existing) {
            // Mise à jour : seules les colonnes remplies changent, pour qu'un
            // fichier « prix et stock » ne vide pas le genre ou la description.
            $product = $existing->product;
            $productAttributes = [
                'name' => $data['titre'],
                'price' => (float) $data['prix'],
                'category_id' => $shelf->id,
            ];
            if ($filled('genre')) {
                $productAttributes['subcategory_id'] = $genreId;
            } elseif ($product->category_id !== $shelf->id) {
                $productAttributes['subcategory_id'] = null; // l'ancien genre appartient à l'ancien rayon
            }
            foreach (['description' => 'description', 'stock' => 'stock', 'poids' => 'weight'] as $column => $attribute) {
                if ($filled($column)) {
                    $productAttributes[$attribute] = match ($column) {
                        'stock' => (int) $data[$column],
                        'poids' => (float) $data[$column],
                        default => $data[$column],
                    };
                }
            }

            $bookAttributes = ['author' => $data['auteur']];
            foreach (['editeur' => 'publisher', 'langue' => 'language', 'format' => 'format', 'pages' => 'pages', 'annee' => 'published_year'] as $column => $attribute) {
                if ($filled($column)) {
                    $bookAttributes[$attribute] = $data[$column];
                }
            }

            $product->update($productAttributes);
            $existing->update($bookAttributes);
            $this->updated++;

            return;
        }

        $productAttributes = [
            'name' => $data['titre'],
            'description' => $filled('description') ? $data['description'] : $data['titre'],
            'price' => (float) $data['prix'],
            'category_id' => $shelf->id,
            'subcategory_id' => $genreId,
            'stock' => (int) ($data['stock'] ?? 0),
        ];

        $bookAttributes = [
            'author' => $data['auteur'],
            'isbn' => $isbn,
            'publisher' => $filled('editeur') ? $data['editeur'] : null,
            'language' => $filled('langue') ? $data['langue'] : 'fr',
            'format' => $filled('format') ? $data['format'] : null,
            'pages' => $filled('pages') ? $data['pages'] : null,
            'published_year' => $filled('annee') ? $data['annee'] : null,
        ];

        $product = Product::create($productAttributes + [
            'shop_id' => $this->shop->id,
            'user_id' => $this->shop->user_id,
            'currency' => 'XAF',
            'price_type' => 'fixed',
            'type' => 'article',
            'weight' => ! empty($data['poids']) ? (float) $data['poids'] : self::DEFAULT_WEIGHT_KG,
            'weight_category' => 'X-small',
            'status' => 'active',
            'slug' => Product::uniqueSlug($data['titre']),
        ]);
        $product->book()->create($bookAttributes);
        $this->created++;
    }
}
