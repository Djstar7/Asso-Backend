<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Partenaire de livraison choisi par le client
            $table->foreignId('delivery_company_id')->nullable()->after('delivery_person_id')
                  ->constrained('deliverer_companies')->onDelete('set null');
            $table->foreignId('delivery_zone_id')->nullable()->after('delivery_company_id')
                  ->constrained('delivery_zones')->onDelete('set null');

            // Code secret de confirmation livraison (6 chiffres)
            $table->string('confirmation_code', 6)->nullable()->after('tracking_number');

            // Double confirmation : client + livreur
            $table->timestamp('confirmed_by_client_at')->nullable()->after('cancelled_at');
            $table->timestamp('confirmed_by_deliverer_at')->nullable()->after('confirmed_by_client_at');

            // Notation
            $table->timestamp('rated_at')->nullable()->after('confirmed_by_deliverer_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['delivery_company_id']);
            $table->dropForeign(['delivery_zone_id']);
            $table->dropColumn([
                'delivery_company_id',
                'delivery_zone_id',
                'confirmation_code',
                'confirmed_by_client_at',
                'confirmed_by_deliverer_at',
                'rated_at',
            ]);
        });
    }
};
