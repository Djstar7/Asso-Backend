<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogue des fournisseurs (lot 2) : catégories du fournisseur et leur
 * correspondance ASSO, produits et déclinaisons importés, reliés aux fiches ASSO.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 100);
            $table->string('name');
            // Chemin complet chez le fournisseur : « Women's Clothing > Tops & Sets > Hoodies ».
            $table->string('path', 600);
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subcategory_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['supplier_id', 'external_id']);
        });

        Schema::create('supplier_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 100);
            $table->string('external_sku', 100)->nullable();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_category_id', 100)->nullable();
            $table->string('name');
            // imported | no_shipping | unmapped | error | delisted
            $table->string('status', 20)->default('pending');
            $table->decimal('cost_usd', 12, 2)->nullable();
            $table->decimal('freight_usd', 12, 2)->nullable();
            $table->string('logistic_name')->nullable();
            $table->string('logistic_aging', 50)->nullable();
            $table->unsignedInteger('weight_g')->nullable();
            $table->string('main_image_url', 1000)->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->json('payload')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['supplier_id', 'external_id']);
            $table->index(['supplier_id', 'status']);
        });

        Schema::create('supplier_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_product_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 100);
            $table->string('external_sku', 100)->nullable();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->json('attributes')->nullable();
            $table->decimal('cost_usd', 12, 2);
            $table->decimal('price_xaf', 14, 2)->nullable();
            $table->decimal('weight_g', 10, 2)->nullable();
            $table->unsignedInteger('length_mm')->nullable();
            $table->unsignedInteger('width_mm')->nullable();
            $table->unsignedInteger('height_mm')->nullable();
            $table->integer('stock')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['supplier_product_id', 'external_id']);
            $table->index('external_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_variants');
        Schema::dropIfExists('supplier_products');
        Schema::dropIfExists('supplier_categories');
    }
};
