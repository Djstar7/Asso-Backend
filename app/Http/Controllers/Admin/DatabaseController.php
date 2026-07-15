<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DatabaseController extends Controller
{
    /**
     * Afficher l'interface SQL.
     */
    public function index()
    {
        $tables = $this->getAllTables();
        $stats = [
            'total_tables' => count($tables),
            'database_name' => env('DB_DATABASE'),
            'database_size' => $this->getDatabaseSize(),
        ];

        return view('admin.database.index', compact('tables', 'stats'));
    }

    /**
     * Afficher les détails d'une table.
     */
    public function table($tableName)
    {
        if (!Schema::hasTable($tableName)) {
            abort(404, 'Table introuvable');
        }

        $columns = $this->getTableColumns($tableName);
        $rowCount = DB::table($tableName)->count();
        $data = DB::table($tableName)->limit(100)->get();

        return view('admin.database.table', compact('tableName', 'columns', 'rowCount', 'data'));
    }

    /**
     * Exécuter une requête SQL.
     */
    public function query(Request $request)
    {
        $request->validate([
            'query_text' => 'required|string',
        ]);

        try {
            $query = trim($request->query_text);

            // Vérifier que c'est une requête SELECT uniquement (sécurité)
            if (!preg_match('/^SELECT\s/i', $query)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Seules les requêtes SELECT sont autorisées pour des raisons de sécurité.',
                ], 400);
            }

            $startTime = microtime(true);
            $results = DB::select($query);
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            return response()->json([
                'success' => true,
                'results' => $results,
                'count' => count($results),
                'execution_time' => $executionTime,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Exporter les résultats en CSV.
     */
    public function export(Request $request)
    {
        $request->validate([
            'query' => 'required|string',
        ]);

        try {
            $query = trim($request->input('query'));

            if (!preg_match('/^SELECT\s/i', $query)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Seules les requêtes SELECT sont autorisées.',
                ], 400);
            }

            $results = DB::select($query);

            if (empty($results)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun résultat à exporter.',
                ], 400);
            }

            $fileName = 'export_' . date('Y-m-d_His') . '.csv';
            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
            ];

            $callback = function () use ($results) {
                $file = fopen('php://output', 'w');

                // En-têtes
                $firstRow = (array) $results[0];
                fputcsv($file, array_keys($firstRow));

                // Données
                foreach ($results as $result) {
                    fputcsv($file, (array) $result);
                }

                fclose($file);
            };

            return response()->stream($callback, 200, $headers);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Obtenir toutes les tables de la base de données.
     */
    private function getAllTables()
    {
        $tables = [];
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            // SQLite
            $results = DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");

            foreach ($results as $result) {
                $tableName = $result->name;
                $rowCount = DB::table($tableName)->count();

                $tables[] = [
                    'name' => $tableName,
                    'rows' => $rowCount,
                    'size' => 0, // SQLite ne supporte pas facilement la taille par table
                ];
            }
        } elseif ($driver === 'pgsql') {
            // PostgreSQL
            $results = DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename");

            foreach ($results as $result) {
                $tableName = $result->tablename;
                $rowCount = DB::table($tableName)->count();

                $tables[] = [
                    'name' => $tableName,
                    'rows' => $rowCount,
                    'size' => $this->getTableSize($tableName),
                ];
            }
        } else {
            // MySQL/MariaDB
            $databaseName = env('DB_DATABASE');
            $results = DB::select('SHOW TABLES');

            foreach ($results as $result) {
                $tableName = $result->{'Tables_in_' . $databaseName};
                $rowCount = DB::table($tableName)->count();
                $tableSize = $this->getTableSize($tableName);

                $tables[] = [
                    'name' => $tableName,
                    'rows' => $rowCount,
                    'size' => $tableSize,
                ];
            }
        }

        return $tables;
    }

    /**
     * Obtenir les colonnes d'une table.
     */
    private function getTableColumns($tableName)
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $columns = DB::select("PRAGMA table_info({$tableName})");

            return array_map(function ($column) {
                return [
                    'name' => $column->name,
                    'type' => $column->type,
                    'null' => $column->notnull == 0 ? 'YES' : 'NO',
                    'key' => $column->pk == 1 ? 'PRI' : '',
                    'default' => $column->dflt_value,
                    'extra' => '',
                ];
            }, $columns);
        } elseif ($driver === 'pgsql') {
            $columns = DB::select("
                SELECT column_name, data_type, is_nullable, column_default
                FROM information_schema.columns
                WHERE table_schema = 'public' AND table_name = ?
                ORDER BY ordinal_position
            ", [$tableName]);

            return array_map(function ($column) {
                return [
                    'name' => $column->column_name,
                    'type' => $column->data_type,
                    'null' => $column->is_nullable,
                    'key' => '',
                    'default' => $column->column_default,
                    'extra' => '',
                ];
            }, $columns);
        } else {
            $columns = DB::select('SHOW COLUMNS FROM ' . $tableName);

            return array_map(function ($column) {
                return [
                    'name' => $column->Field,
                    'type' => $column->Type,
                    'null' => $column->Null,
                    'key' => $column->Key,
                    'default' => $column->Default,
                    'extra' => $column->Extra,
                ];
            }, $columns);
        }
    }

    /**
     * Obtenir la taille d'une table.
     */
    private function getTableSize($tableName)
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            $result = DB::select(
                "SELECT ROUND(pg_total_relation_size(?::regclass) / 1024.0 / 1024.0, 2) AS size_mb",
                [$tableName]
            );

            return $result[0]->size_mb ?? 0;
        }

        // MySQL/MariaDB
        $databaseName = env('DB_DATABASE');

        $result = DB::select("
            SELECT
                ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
            FROM information_schema.TABLES
            WHERE table_schema = ? AND table_name = ?
        ", [$databaseName, $tableName]);

        return $result[0]->size_mb ?? 0;
    }

    /**
     * Obtenir la taille totale de la base de données.
     */
    private function getDatabaseSize()
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $dbPath = database_path('database.sqlite');
            if (file_exists($dbPath)) {
                return round(filesize($dbPath) / 1024 / 1024, 2);
            }
            return 0;
        } elseif ($driver === 'pgsql') {
            $result = DB::select("SELECT ROUND(pg_database_size(current_database()) / 1024.0 / 1024.0, 2) AS size_mb");

            return $result[0]->size_mb ?? 0;
        } else {
            $databaseName = env('DB_DATABASE');

            $result = DB::select("
                SELECT
                    ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb
                FROM information_schema.TABLES
                WHERE table_schema = ?
            ", [$databaseName]);

            return $result[0]->size_mb ?? 0;
        }
    }
}
