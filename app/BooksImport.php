<?php

namespace App\Imports;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBook;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Colonnes attendues (ligne d'en-tête) :
 * titre, auteur, prix, categorie, isbn, editeur, langue, format, pages, annee, stock, poids, description
 */
class BooksImport implements ToCollection, WithHeadingRow, WithCustomCsvSettings
{
    public int $created = 0;
    public int $updated = 0;
    /** @var array<int, array{line:int, message:string}> */
    public array $errors = [];

    public function __construct(
        private int $shopId,
        private int $userId,
        private string $delimiter = ',',
    ) {}

    public function getCsvSettings(): array
    {
        return ['delimiter' => $this->delimiter, 'input_encoding' => 'UTF-8'];
    }

    public function collection(Collection $rows): void
    {
        // Catégories bibliothèque, retrouvées par nom ou slug (insensible à la casse).
        $categories = Category::where('is_library', true)->get();
        $byKey = [];
        foreach ($categories as $category) {
            $byKey[Str::lower($category->name)] = $category;
            $byKey[Str::lower($category->slug)] = $category;
        }

        foreach ($rows as $index => $row) {
            $line = $index + 2; // +1 pour l'en-tête, +1 car l'index part de 0
            $data = $row->toArray();

            // Ligne entièrement vide : ignorée.
            if (collect($data)->filter(fn ($v) => $v !== null && $v !== '')->isEmpty()) {
                continue;
            }

            $validator = Validator::make($data, [
                'titre' => 'required|string|max:255',
                'auteur' => 'required|string|max:255',
                'prix' => 'required|numeric|min:0',
                'categorie' => 'required|string',
                'isbn' => 'nullable|max:20',
                'editeur' => 'nullable|string|max:255',
                'langue' => 'nullable|in:fr,en',
                'format' => 'nullable|in:' . implode(',', ProductBook::FORMATS),
                'pages' => 'nullable|integer|min:1|max:20000',
                'annee' => 'nullable|integer|min:1000|max:2100',
                'stock' => 'nullable|integer|min:0',
                'poids' => 'nullable|numeric|min:0.001',
            ]);

            if ($validator->fails()) {
                $this->errors[] = ['line' => $line, 'message' => $validator->errors()->first()];
                continue;
            }

            $category = $byKey[Str::lower(trim($data['categorie']))] ?? null;
            if (!$category) {
                $this->errors[] = ['line' => $line, 'message' => "Catégorie bibliothèque introuvable : « {$data['categorie']} »"];
                continue;
            }

            try {
                DB::transaction(fn () => $this->upsert($data, $category));
            } catch (\Throwable $e) {
                report($e);
                $this->errors[] = ['line' => $line, 'message' => 'Erreur inattendue : ' . $e->getMessage()];
            }
        }
    }

    private function upsert(array $data, Category $category): void
    {
        $isbn = isset($data['isbn']) ? trim((string) $data['isbn']) : null;
        $isbn = $isbn !== '' ? $isbn : null;

        $productAttributes = [
            'name' => trim($data['titre']),
            'description' => !empty($data['description']) ? $data['description'] : trim($data['titre']),
            'price' => (float) $data['prix'],
            'category_id' => $category->id,
            'stock' => (int) ($data['stock'] ?? 0),
        ];
        if (!empty($data['poids'])) {
            $productAttributes['weight'] = (float) $data['poids'];
        }

        $bookAttributes = [
            'author' => trim($data['auteur']),
            'isbn' => $isbn,
            'publisher' => $data['editeur'] ?? null,
            'language' => $data['langue'] ?? 'fr',
            'format' => $data['format'] ?? null,
            'pages' => $data['pages'] ?? null,
            'published_year' => $data['annee'] ?? null,
        ];

        // Même ISBN déjà présent dans cette boutique : mise à jour plutôt que doublon.
        $existing = $isbn
            ? ProductBook::where('isbn', $isbn)
                ->whereHas('product', fn ($q) => $q->where('shop_id', $this->shopId))
                ->first()
            : null;

        if ($existing) {
            $existing->product->update($productAttributes);
            $existing->update($bookAttributes);
            $this->updated++;
            return;
        }

        $product = Product::create($productAttributes + [
            'shop_id' => $this->shopId,
            'user_id' => $this->userId,
            'currency' => 'XAF',
            'type' => 'article',

            'condition' => 'new',
            'weight_category' => 'X-small',
            'status' => 'active',
            'slug' => Str::slug($data['titre']) . '-' . Str::random(5),
        ]);
        $product->book()->create($bookAttributes + ['weight' => 0.4]); // kg, utilisé seulement si la colonne « poids » est vide
        $this->created++;
    }
}
