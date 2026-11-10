<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('products')->where('requires_quote', true)->update(['requires_quote' => false]);
    }

    public function down(): void
    {
        // Les anciens choix ne peuvent pas être reconstitués.
    }
};
