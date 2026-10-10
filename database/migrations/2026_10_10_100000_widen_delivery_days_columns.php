<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Délai de livraison sans maximum métier (1 jour minimum) : les colonnes
 * passent d'un tinyint (255 au plus en MySQL) à un smallint.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['products', 'categories'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->unsignedSmallInteger('delivery_days_min')->nullable()->change();
                $table->unsignedSmallInteger('delivery_days_max')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        foreach (['products', 'categories'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->unsignedTinyInteger('delivery_days_min')->nullable()->change();
                $table->unsignedTinyInteger('delivery_days_max')->nullable()->change();
            });
        }
    }
};
