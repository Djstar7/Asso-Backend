<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un compte n'est plus jamais effacé : il est anonymisé (App\Services\AccountDeletionService).
 *
 * - users.anonymized_at : date de l'anonymisation.
 * - Les clés étrangères de l'historique financier vers users passent de CASCADE
 *   à RESTRICT : même une suppression faite à la main ne peut plus emporter les
 *   commandes, lignes, Wallet, transactions, litiges, forfaits ou réservations.
 *   Elles sont retrouvées par leur définition (le nom peut différer d'une base à
 *   l'autre) puis contrôlées : la migration échoue et s'annule sinon.
 */
return new class extends Migration
{
    /** Table => colonnes vers users protégées (liste figée au moment de la migration). */
    public const PROTECTED = \App\Support\ProtectedUserHistory::COLUMNS;

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('anonymized_at')->nullable();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::PROTECTED as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($columns as $column) {
                if (! $this->setRule($table, $column, 'RESTRICT')) {
                    continue;
                }
                $rules = collect($this->foreignKeys($table, $column))->pluck('rule')->all();
                if ($rules !== ['r']) {
                    throw new \RuntimeException("{$table}.{$column} : contrainte inattendue après migration (".json_encode($rules).').');
                }
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (self::PROTECTED as $table => $columns) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                foreach ($columns as $column) {
                    $this->setRule($table, $column, 'CASCADE');
                }
            }
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('anonymized_at');
        });
    }

    /** @return bool false si la colonne n'a pas de clé étrangère vers users dans cette base */
    private function setRule(string $table, string $column, string $rule): bool
    {
        $existing = $this->foreignKeys($table, $column);
        if ($existing === []) {
            return false;
        }
        foreach ($existing as $constraint) {
            DB::statement('ALTER TABLE '.$table.' DROP CONSTRAINT "'.str_replace('"', '""', $constraint->name).'"');
        }
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_foreign FOREIGN KEY ({$column}) REFERENCES users(id) ON DELETE {$rule}");

        return true;
    }

    /** Clés étrangères {$table}.{$column} -> users : nom et règle (a, r, c, n, d). */
    private function foreignKeys(string $table, string $column): array
    {
        return DB::select(<<<'SQL'
            SELECT c.conname AS name, c.confdeltype AS rule
            FROM pg_constraint c
            JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY (c.conkey)
            WHERE c.contype = 'f' AND c.conrelid = ?::regclass AND c.confrelid = 'users'::regclass AND a.attname = ?
            SQL, [$table, $column]);
    }
};
