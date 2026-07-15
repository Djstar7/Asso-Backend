<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return; // Contraintes CHECK gérées uniquement sous PostgreSQL (prod)
        }

        // Drop old CHECK constraint
        DB::statement('ALTER TABLE deliverer_sync_codes DROP CONSTRAINT IF EXISTS deliverer_sync_codes_sent_via_check');

        // Add new CHECK constraint with correct values
        DB::statement("ALTER TABLE deliverer_sync_codes ADD CONSTRAINT deliverer_sync_codes_sent_via_check CHECK (sent_via IN ('email', 'sms', 'whatsapp', 'all'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Drop new constraint
        DB::statement('ALTER TABLE deliverer_sync_codes DROP CONSTRAINT IF EXISTS deliverer_sync_codes_sent_via_check');

        // Restore old constraint
        DB::statement("ALTER TABLE deliverer_sync_codes ADD CONSTRAINT deliverer_sync_codes_sent_via_check CHECK (sent_via IN ('email', 'sms', 'both'))");
    }
};
