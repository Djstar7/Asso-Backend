<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contrôle d'intégrité autour d'un déploiement (lecture seule, aucune écriture en base).
 *
 *   php artisan down
 *   php artisan deploy:integrity-check --save=avant.json
 *   php artisan migrate --force
 *   php artisan deploy:integrity-check --compare=avant.json
 *   php artisan up
 *
 * La photo porte sur les données existantes : nombres de lignes, sommes et
 * empreintes (md5) des colonnes d'avant la migration, pour les commandes, lignes,
 * transactions, Wallet, produits et utilisateurs. La comparaison signale le moindre
 * écart, puis vérifie les points propres à la migration des chefs de rubrique.
 * Code de sortie 1 au moindre problème.
 *
 * À lancer site en maintenance : une commande passée entre les deux photos
 * apparaîtrait comme un écart.
 */
class DeployIntegrityCheck extends Command
{
    protected $signature = 'deploy:integrity-check
        {--save= : Fichier où enregistrer la photo (storage/app/integrity/)}
        {--compare= : Photo prise avant, à comparer à l\'état actuel}';

    protected $description = 'Photo des données sensibles avant migration et comparaison après (lecture seule)';

    /**
     * Table => [colonnes comptées dans l'empreinte, colonnes sommées]. Uniquement
     * des colonnes qui existaient avant la migration : elles ne doivent pas bouger.
     */
    private const TABLES = [
        'orders' => [
            ['id', 'order_number', 'user_id', 'status', 'payment_status', 'subtotal', 'delivery_fee', 'total', 'sale_commission', 'vendor_net_amount', 'settled_at', 'refunded_at', 'vendor_funds_status', 'vendor_funds_held_amount'],
            ['total', 'subtotal', 'sale_commission', 'vendor_net_amount', 'vendor_funds_held_amount'],
        ],
        'order_items' => [
            ['id', 'order_id', 'product_id', 'seller_id', 'quantity', 'unit_price', 'total_price', 'seller_unit_price', 'seller_total_price', 'commission_rate'],
            ['quantity', 'total_price', 'seller_total_price'],
        ],
        'transactions' => [
            ['id', 'reference', 'buyer_id', 'seller_id', 'product_id', 'amount', 'status', 'fees', 'net_amount', 'currency'],
            ['amount', 'fees', 'net_amount'],
        ],
        'wallet_transactions' => [
            ['id', 'user_id', 'type', 'amount', 'balance_before', 'balance_after', 'reference_type', 'reference_id', 'status', 'provider'],
            ['amount'],
        ],
        'wallet_balances' => [
            ['id', 'user_id', 'currency', 'balance', 'locked_balance', 'card_funds'],
            ['balance', 'locked_balance', 'card_funds'],
        ],
        'products' => [
            ['id', 'user_id', 'shop_id', 'category_id', 'subcategory_id', 'name', 'slug', 'price', 'stock', 'status', 'currency'],
            ['price', 'stock'],
        ],
        'users' => [
            ['id', 'email', 'role', 'roles', 'admin_permissions'],
            [],
        ],
        'disputes' => [
            ['id', 'order_id', 'order_item_id', 'status', 'held_amount', 'refund_amount', 'refunded_at'],
            ['held_amount', 'refund_amount'],
        ],
        'real_estate_reservations' => [
            ['id', 'status', 'total_amount', 'platform_amount', 'owner_amount', 'paid_at', 'released_at'],
            ['total_amount', 'platform_amount', 'owner_amount'],
        ],
        'platform_withdrawals' => [
            ['id', 'user_id', 'amount_requested', 'status'],
            ['amount_requested'],
        ],
    ];

    public function handle(): int
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->error('Contrôle prévu pour PostgreSQL (base de production).');

            return self::FAILURE;
        }
        if (! $this->option('save') && ! $this->option('compare')) {
            $this->error('Indiquez --save=fichier.json (avant) ou --compare=fichier.json (après).');

            return self::FAILURE;
        }

        $snapshot = $this->snapshot();

        if ($file = $this->option('save')) {
            $path = $this->path($file);
            if (is_file($path)) {
                $this->error("{$path} existe déjà : choisissez un autre nom (une photo n'est jamais écrasée).");

                return self::FAILURE;
            }
            @mkdir(dirname($path), 0750, true);
            file_put_contents($path, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->table(['Table', 'Lignes'], collect($snapshot['tables'])->map(fn ($t, $name) => [$name, $t['count'] ?? 'absente'])->values());
            $this->info("Photo enregistrée : {$path}");

            return self::SUCCESS;
        }

        return $this->compare($this->path($this->option('compare')), $snapshot);
    }

    private function snapshot(): array
    {
        $tables = [];
        // Transaction en lecture seule : aucune écriture possible, photo cohérente
        // (déjà dans une transaction, par exemple en test : on lit dans celle-ci).
        $outer = DB::transactionLevel() > 0;
        DB::transaction(function () use (&$tables, $outer) {
            if (! $outer) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            }
            foreach (self::TABLES as $table => [$hashColumns, $sumColumns]) {
                if (! Schema::hasTable($table)) {
                    $tables[$table] = null;

                    continue;
                }
                $existing = Schema::getColumnListing($table);
                $hashColumns = array_values(array_intersect($hashColumns, $existing));
                $sumColumns = array_values(array_intersect($sumColumns, $existing));

                $row = (array) DB::selectOne(sprintf(
                    'SELECT COUNT(*) AS count, COALESCE(MAX(id), 0) AS max_id, md5(COALESCE(string_agg(%s, \'|\' ORDER BY id), \'\')) AS hash%s FROM %s',
                    "concat_ws(';', ".implode(', ', array_map(fn ($c) => 'COALESCE("'.$c.'"::text, \'∅\')', $hashColumns)).')',
                    implode('', array_map(fn ($c) => ', COALESCE(SUM("'.$c.'"), 0)::text AS "sum_'.$c.'"', $sumColumns)),
                    $table
                ));
                $row['columns'] = $hashColumns;
                $tables[$table] = $row;
            }
        });

        return ['taken_at' => now()->toIso8601String(), 'database' => DB::getDatabaseName(), 'tables' => $tables];
    }

    private function compare(string $path, array $after): int
    {
        if (! is_file($path)) {
            $this->error("Photo introuvable : {$path}");

            return self::FAILURE;
        }
        $before = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if ($before['database'] !== $after['database']) {
            $this->error("La photo vient de la base « {$before['database']} », pas de « {$after['database']} ».");

            return self::FAILURE;
        }

        $problems = [];
        $rows = [];
        foreach (self::TABLES as $table => $_) {
            $b = $before['tables'][$table] ?? null;
            $a = $after['tables'][$table] ?? null;
            if ($b === null) {
                $rows[] = [$table, '—', $a['count'] ?? '—', 'absente avant'];

                continue;
            }
            if ($a === null) {
                $problems[] = "{$table} : table disparue.";
                $rows[] = [$table, $b['count'], '—', 'DISPARUE'];

                continue;
            }
            $issues = [];
            foreach ($b as $key => $value) {
                if ($key === 'columns') {
                    continue;
                }
                if ((string) ($a[$key] ?? '') !== (string) $value) {
                    $issues[] = "{$key} : {$value} -> ".($a[$key] ?? 'absent');
                }
            }
            if ($b['columns'] !== $a['columns']) {
                $issues[] = 'colonnes contrôlées différentes';
            }
            foreach ($issues as $issue) {
                $problems[] = "{$table} : {$issue}";
            }
            $rows[] = [$table, $b['count'], $a['count'], $issues ? 'ÉCART' : 'identique'];
        }
        $this->table(['Table', 'Avant', 'Après', 'Résultat'], $rows);

        $problems = array_merge($problems, $this->migrationChecks());

        if ($problems) {
            $this->error('Écarts détectés : NE PAS remettre le site en ligne avant analyse.');
            foreach ($problems as $problem) {
                $this->line(' - '.$problem);
            }

            return self::FAILURE;
        }

        $this->info('Aucun écart : données existantes identiques, contrôles de migration conformes.');

        return self::SUCCESS;
    }

    /** Points propres à la migration chefs de rubrique (ignorés si elle n'est pas encore jouée). */
    private function migrationChecks(): array
    {
        $problems = [];

        foreach (['order_items', 'transactions'] as $table) {
            $rules = collect(DB::select(<<<'SQL'
                SELECT c.confdeltype AS rule FROM pg_constraint c
                JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY (c.conkey)
                WHERE c.contype = 'f' AND c.conrelid = ?::regclass AND c.confrelid = 'products'::regclass AND a.attname = 'product_id'
                SQL, [$table]))->pluck('rule')->all();
            if (Schema::hasColumn('products', 'deleted_at') && $rules !== ['n']) {
                $problems[] = "{$table}.product_id : suppression en cascade toujours possible (".json_encode($rules).').';
            }
        }

        if (Schema::hasColumn('order_items', 'category_id')) {
            $missing = DB::table('order_items as i')->join('products as p', 'p.id', '=', 'i.product_id')
                ->whereNull('i.category_id')->whereNotNull('p.category_id')->count();
            if ($missing) {
                $problems[] = "{$missing} ligne(s) de commande sans catégorie figée.";
            }
        }
        if (Schema::hasColumn('products', 'deleted_at') && ($archived = DB::table('products')->whereNotNull('deleted_at')->count())) {
            $problems[] = "{$archived} produit(s) archivé(s) juste après la migration (attendu : 0).";
        }
        if (Schema::hasTable('division_commissions')) {
            foreach (['division_heads', 'division_commissions'] as $table) {
                if ($n = DB::table($table)->count()) {
                    $problems[] = "{$table} : {$n} ligne(s) alors qu'aucun chef n'a encore été nommé.";
                }
            }
            $check = DB::selectOne("SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'users_role_check'");
            if (! $check || ! str_contains($check->def, 'division_head')) {
                $problems[] = 'users_role_check n\'accepte pas division_head.';
            }
        }

        // Comptes anonymisés, jamais effacés : plus aucune suppression en cascade
        // de l'historique financier depuis users.
        if (Schema::hasColumn('users', 'anonymized_at')) {
            $cascades = DB::select(<<<'SQL'
                SELECT c.conrelid::regclass::text AS t, a.attname AS col FROM pg_constraint c
                JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY (c.conkey)
                WHERE c.contype = 'f' AND c.confrelid = 'users'::regclass AND c.confdeltype = 'c'
                SQL);
            $protected = array_keys(\App\Support\ProtectedUserHistory::COLUMNS);
            foreach ($cascades as $fk) {
                if (in_array($fk->t, $protected, true)) {
                    $problems[] = "{$fk->t}.{$fk->col} : supprimer un utilisateur effacerait encore ces lignes.";
                }
            }
            if ($n = DB::table('users')->whereNotNull('anonymized_at')->count()) {
                $problems[] = "{$n} compte(s) anonymisé(s) juste après la migration (attendu : 0).";
            }
        }

        return $problems;
    }

    private function path(string $file): string
    {
        return str_contains($file, '/') ? $file : storage_path('app/integrity/'.$file);
    }
}
