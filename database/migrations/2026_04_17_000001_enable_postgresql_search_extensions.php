<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Enable PostgreSQL extensions for intelligent search
     */
    public function up(): void
    {
        // Enable pg_trgm extension for trigram similarity search
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        // Enable unaccent extension for accent-insensitive search
        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');

        // Create a custom function for smarter search
        DB::statement("
            CREATE OR REPLACE FUNCTION f_unaccent(text)
            RETURNS text AS
            \$func\$
            SELECT unaccent('unaccent', \$1)
            \$func\$ LANGUAGE sql IMMUTABLE;
        ");

        // Add GIN index on products for full-text search
        DB::statement("
            CREATE INDEX IF NOT EXISTS products_name_trgm_idx
            ON products USING gin (f_unaccent(name) gin_trgm_ops)
        ");

        DB::statement("
            CREATE INDEX IF NOT EXISTS products_description_trgm_idx
            ON products USING gin (f_unaccent(description) gin_trgm_ops)
        ");

        // Add full-text search vector column to products
        DB::statement("
            ALTER TABLE products
            ADD COLUMN IF NOT EXISTS search_vector tsvector
        ");

        // Create function to update search vector
        DB::statement("
            CREATE OR REPLACE FUNCTION products_search_vector_update()
            RETURNS trigger AS \$\$
            BEGIN
                NEW.search_vector :=
                    setweight(to_tsvector('french', coalesce(f_unaccent(NEW.name), '')), 'A') ||
                    setweight(to_tsvector('french', coalesce(f_unaccent(NEW.description), '')), 'B');
                RETURN NEW;
            END
            \$\$ LANGUAGE plpgsql;
        ");

        // Create trigger to auto-update search vector
        DB::statement("
            DROP TRIGGER IF EXISTS products_search_vector_trigger ON products
        ");

        DB::statement("
            CREATE TRIGGER products_search_vector_trigger
            BEFORE INSERT OR UPDATE ON products
            FOR EACH ROW
            EXECUTE FUNCTION products_search_vector_update()
        ");

        // Create GIN index on search_vector for fast full-text search
        DB::statement("
            CREATE INDEX IF NOT EXISTS products_search_vector_idx
            ON products USING gin (search_vector)
        ");

        // Update existing products to populate search_vector
        DB::statement("UPDATE products SET name = name");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("DROP TRIGGER IF EXISTS products_search_vector_trigger ON products");
        DB::statement("DROP FUNCTION IF EXISTS products_search_vector_update()");
        DB::statement("DROP INDEX IF EXISTS products_search_vector_idx");
        DB::statement("ALTER TABLE products DROP COLUMN IF EXISTS search_vector");
        DB::statement("DROP INDEX IF EXISTS products_description_trgm_idx");
        DB::statement("DROP INDEX IF EXISTS products_name_trgm_idx");
        DB::statement("DROP FUNCTION IF EXISTS f_unaccent(text)");
        DB::statement("DROP EXTENSION IF EXISTS unaccent");
        DB::statement("DROP EXTENSION IF EXISTS pg_trgm");
    }
};
