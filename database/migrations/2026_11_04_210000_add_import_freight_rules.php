<?php

use App\Models\ImportShippingOption;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Import : dimensions du colis (produit et palier), tarif d'achat et marge ASSO par
 * option, seuil avion et diviseur volumétrique par pays. L'express est retiré : ses
 * options sont désactivées, les anciennes commandes restent lisibles.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['products', 'product_price_tiers'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->decimal('length_cm', 8, 2)->nullable();
                $table->decimal('width_cm', 8, 2)->nullable();
                $table->decimal('height_cm', 8, 2)->nullable();
            });
        }

        Schema::table('import_shipping_options', function (Blueprint $table) {
            $table->decimal('cost_amount', 12, 2)->nullable()->after('rate_amount');
            $table->string('margin_type', 10)->nullable()->after('cost_amount');
            $table->decimal('margin_value', 12, 2)->nullable()->after('margin_type');
        });

        Schema::table('import_countries', function (Blueprint $table) {
            $table->decimal('air_max_weight_kg', 10, 2)->default(100);
            $table->unsignedInteger('air_volumetric_divisor')->default(6000);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('import_freight_margin', 12, 2)->nullable()->after('import_shipping_fee');
        });

        ImportShippingOption::where('mode', 'express')->update(['is_active' => false]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('import_freight_margin');
        });

        Schema::table('import_countries', function (Blueprint $table) {
            $table->dropColumn(['air_max_weight_kg', 'air_volumetric_divisor']);
        });

        Schema::table('import_shipping_options', function (Blueprint $table) {
            $table->dropColumn(['cost_amount', 'margin_type', 'margin_value']);
        });

        foreach (['products', 'product_price_tiers'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn(['length_cm', 'width_cm', 'height_cm']);
            });
        }
    }
};
