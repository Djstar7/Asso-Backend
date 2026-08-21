<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module ASSO CHINA / DUBAÏ / TURQUIE — commande EN GROS (import).
 *
 * Un produit « gros » est rattaché à un pays d'import (origin_country) et propose
 * plusieurs PALIERS de conditionnement (product_price_tiers), chacun avec son prix
 * et sa quantité minimale « cota ». L'expédition internationale (avion/bateau/express)
 * est décrite par import_shipping_options.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Marquer un produit comme « gros / import »
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'is_wholesale')) {
                $table->boolean('is_wholesale')->default(false)->after('type')->index();
            }
            if (!Schema::hasColumn('products', 'min_order_quantity')) {
                // Quantité minimale globale de l'article (repli si aucun palier choisi).
                $table->unsignedInteger('min_order_quantity')->nullable()->after('is_wholesale');
            }
        });

        // 2) Paliers de prix (conditionnement) : pack de 12, pack de 5, bidon 20L…
        Schema::create('product_price_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->string('label');                 // ex. "Bidon 1L - pack de 12"
            $table->decimal('unit_price', 12, 2);    // prix du palier (par unité de vente ci-dessous)
            $table->string('currency', 3)->default('XAF');
            $table->unsignedInteger('min_quantity')->default(1); // « cota » : quantité minimale
            $table->unsignedInteger('pack_size')->nullable();    // nb de pièces par pack (info)
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['product_id', 'is_active']);
        });

        // 2b) Commande EN GROS : métadonnées sur la commande et ses lignes
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'is_wholesale')) {
                $table->boolean('is_wholesale')->default(false)->after('status')->index();
            }
            if (!Schema::hasColumn('orders', 'import_country_code')) {
                $table->string('import_country_code', 2)->nullable()->after('is_wholesale');
            }
            if (!Schema::hasColumn('orders', 'shipping_mode')) {
                $table->string('shipping_mode')->nullable()->after('import_country_code'); // air|sea|express
            }
            if (!Schema::hasColumn('orders', 'shipping_option_id')) {
                $table->unsignedBigInteger('shipping_option_id')->nullable()->after('shipping_mode');
            }
        });

        Schema::table('order_items', function (Blueprint $table) {
            if (!Schema::hasColumn('order_items', 'price_tier_id')) {
                $table->unsignedBigInteger('price_tier_id')->nullable()->after('product_id');
            }
            if (!Schema::hasColumn('order_items', 'tier_label')) {
                $table->string('tier_label')->nullable()->after('price_tier_id');
            }
        });

        // 3) Options d'expédition internationale par pays (null = valable pour tous)
        Schema::create('import_shipping_options', function (Blueprint $table) {
            $table->id();
            $table->string('country_code', 2)->nullable()->index(); // lie import_countries.code
            $table->enum('mode', ['air', 'sea', 'express']);        // Avion / Bateau / Express
            $table->enum('rate_type', ['per_kg', 'per_cbm', 'flat'])->default('per_kg');
            $table->decimal('rate_amount', 12, 2);                  // ex. 4000 (avion/kg)
            $table->string('currency', 3)->default('XAF');
            $table->unsignedInteger('lead_time_days')->nullable();  // délai estimé (jours)
            $table->string('expedition_note')->nullable();          // fenêtre d'expédition (texte libre)
            $table->json('destinations')->nullable();               // ["Afrique","Europe","Canada","USA"]
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_shipping_options');
        Schema::dropIfExists('product_price_tiers');
        Schema::table('order_items', function (Blueprint $table) {
            foreach (['price_tier_id', 'tier_label'] as $col) {
                if (Schema::hasColumn('order_items', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
        Schema::table('orders', function (Blueprint $table) {
            foreach (['is_wholesale', 'import_country_code', 'shipping_mode', 'shipping_option_id'] as $col) {
                if (Schema::hasColumn('orders', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
        Schema::table('products', function (Blueprint $table) {
            foreach (['is_wholesale', 'min_order_quantity'] as $col) {
                if (Schema::hasColumn('products', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
