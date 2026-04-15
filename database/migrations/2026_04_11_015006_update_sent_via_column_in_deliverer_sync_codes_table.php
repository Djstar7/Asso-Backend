<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * This migration updates the ENUM values of the sent_via column
     * by recreating the column with the new allowed values.
     */
    public function up(): void
    {
        // Step 1: Convert enum column to TEXT temporarily
        DB::statement("
            ALTER TABLE deliverer_sync_codes
            ALTER COLUMN sent_via TYPE TEXT
        ");

        // Step 2: Drop the existing CHECK constraint (auto-created by Laravel)
        DB::statement("
            ALTER TABLE deliverer_sync_codes
            DROP CONSTRAINT IF EXISTS deliverer_sync_codes_sent_via_check
        ");

        // Step 3: Add new CHECK constraint with updated values
        DB::statement("
            ALTER TABLE deliverer_sync_codes
            ADD CONSTRAINT deliverer_sync_codes_sent_via_check
            CHECK (sent_via IN ('email', 'sms', 'whatsapp', 'all'))
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Step 1: Remove new constraint
        DB::statement("
            ALTER TABLE deliverer_sync_codes
            DROP CONSTRAINT IF EXISTS deliverer_sync_codes_sent_via_check
        ");

        // Step 2: Restore old constraint
        DB::statement("
            ALTER TABLE deliverer_sync_codes
            ADD CONSTRAINT deliverer_sync_codes_sent_via_check
            CHECK (sent_via IN ('email', 'sms', 'both'))
        ");
    }
};
