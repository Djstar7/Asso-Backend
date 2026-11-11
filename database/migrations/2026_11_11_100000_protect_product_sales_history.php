<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Historique des ventes protégé de la suppression des produits.
 *
 * - products.deleted_at : un produit déjà vendu est archivé, plus supprimé.
 * - products.created_by : compte qui a créé la fiche (périmètre des gestionnaires).
 * - order_items : nom, catégorie et sous-catégorie figés à la commande ; la
 *   rubrique d'une vente ne change plus si le produit change de catégorie.
 * - order_items.product_id / transactions.product_id : la suppression définitive
 *   d'un produit (compte ou boutique supprimés) ne supprime plus les lignes de
 *   commande ni les transactions, elle vide seulement le lien.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->softDeletes();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->string('product_name')->nullable()->after('product_id');
            $table->unsignedBigInteger('category_id')->nullable()->after('product_name')->index();
            $table->unsignedBigInteger('subcategory_id')->nullable()->after('category_id');
        });

        // Rattrapage : instantané pris sur la fiche actuelle du produit.
        DB::table('order_items')->whereNull('category_id')->orderBy('id')->chunkById(500, function ($items) {
            $products = DB::table('products')->whereIn('id', $items->pluck('product_id')->filter())
                ->get(['id', 'name', 'category_id', 'subcategory_id'])->keyBy('id');
            foreach ($items as $item) {
                if ($product = $products->get($item->product_id)) {
                    DB::table('order_items')->where('id', $item->id)->update([
                        'product_name' => mb_substr((string) $product->name, 0, 255),
                        'category_id' => $product->category_id,
                        'subcategory_id' => $product->subcategory_id,
                    ]);
                }
            }
        });

        if (DB::getDriverName() === 'pgsql') {
            foreach (['order_items', 'transactions'] as $table) {
                // Contraintes réelles, quel que soit leur nom (il peut différer d'une base à l'autre).
                foreach ($this->productForeignKeys($table) as $constraint) {
                    DB::statement('ALTER TABLE '.$table.' DROP CONSTRAINT "'.str_replace('"', '""', $constraint->name).'"');
                }
                DB::statement("ALTER TABLE {$table} ALTER COLUMN product_id DROP NOT NULL");
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_product_id_foreign FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL");

                // Contrôle : une seule contrainte, en SET NULL. Sinon la migration échoue et
                // la transaction est annulée (rien n'est modifié).
                $rules = collect($this->productForeignKeys($table))->pluck('delete_rule')->all();
                if ($rules !== ['n']) {
                    throw new \RuntimeException("{$table}.product_id : contrainte inattendue après migration (".json_encode($rules).').');
                }
            }
        }

        // Contrôle : le rattrapage n'a modifié que les nouvelles colonnes.
        $missing = DB::table('order_items')->whereNotNull('product_id')->whereNull('category_id')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('products')->whereColumn('products.id', 'order_items.product_id')->whereNotNull('products.category_id'))
            ->count();
        if ($missing > 0) {
            throw new \RuntimeException("{$missing} ligne(s) de commande sans catégorie figée après rattrapage.");
        }
    }

    /** Clés étrangères de {$table}.product_id vers products : nom et règle de suppression (a, r, c, n, d). */
    private function productForeignKeys(string $table): array
    {
        return DB::select(<<<'SQL'
            SELECT c.conname AS name, c.confdeltype AS delete_rule
            FROM pg_constraint c
            JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY (c.conkey)
            WHERE c.contype = 'f'
              AND c.conrelid = ?::regclass
              AND c.confrelid = 'products'::regclass
              AND a.attname = 'product_id'
            SQL, [$table]);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (['order_items', 'transactions'] as $table) {
                foreach ($this->productForeignKeys($table) as $constraint) {
                    DB::statement('ALTER TABLE '.$table.' DROP CONSTRAINT "'.str_replace('"', '""', $constraint->name).'"');
                }
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_product_id_foreign FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE");
            }
        }

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex(['category_id']);
            $table->dropColumn(['product_name', 'category_id', 'subcategory_id']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropSoftDeletes();
        });
    }
};
