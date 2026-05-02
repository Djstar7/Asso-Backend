<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Table des devises
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 3)->unique(); // ISO 4217 (EUR, USD, XOF, etc.)
            $table->string('name'); // Euro, US Dollar, West African CFA franc
            $table->string('symbol', 10); // €, $, FCFA
            $table->json('countries')->nullable(); // Liste des pays utilisant cette devise
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Table des taux de change (base: XOF - Franc CFA)
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->string('from_currency', 3); // Code devise de départ (XOF par défaut)
            $table->string('to_currency', 3); // Code devise de destination
            $table->decimal('rate', 20, 8); // Taux de conversion
            $table->date('effective_date')->default(now()); // Date d'effet du taux
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('from_currency')->references('code')->on('currencies')->onDelete('cascade');
            $table->foreign('to_currency')->references('code')->on('currencies')->onDelete('cascade');
            $table->unique(['from_currency', 'to_currency', 'effective_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('currencies');
    }
};
