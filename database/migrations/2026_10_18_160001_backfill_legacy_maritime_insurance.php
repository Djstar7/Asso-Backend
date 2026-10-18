<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        \App\Services\MaritimeInsuranceLegacyBackfill::run();
    }

    public function down(): void
    {
        // This data migration preserves historical payment information and is intentionally irreversible.
    }
};
