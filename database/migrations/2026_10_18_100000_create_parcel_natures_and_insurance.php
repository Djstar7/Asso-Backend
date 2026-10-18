<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Assurance des colis Diaspo (avion et maritime) : l'expéditeur choisit la
 * nature du colis dans une liste gérée par l'admin et en déclare la valeur ;
 * le supplément = max(valeur × taux de la nature, minimum de la nature).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parcel_natures', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Taux en % de la valeur déclarée.
            $table->decimal('insurance_rate', 5, 2)->default(0);
            // Supplément minimum, en XAF.
            $table->decimal('min_insurance_amount', 12, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('diaspo_bookings', function (Blueprint $table) {
            $table->foreignId('parcel_nature_id')->nullable()->after('total_price')->constrained('parcel_natures')->nullOnDelete();
            $table->decimal('declared_value', 12, 2)->nullable()->after('parcel_nature_id');
            $table->decimal('insurance_amount', 12, 2)->default(0)->after('declared_value');
        });

        // Natures par défaut ; taux à ajuster dans l'admin.
        $now = now();
        $defaults = [
            ['Téléphone', 5, 2000],
            ['Bijoux', 8, 3000],
            ['Document administratif', 2, 1000],
            ['Vêtements et chaussures', 3, 1000],
            ['Appareil électronique', 5, 2000],
            ['Autre', 3, 1000],
        ];
        foreach ($defaults as $i => [$name, $rate, $min]) {
            DB::table('parcel_natures')->insert([
                'name' => $name,
                'insurance_rate' => $rate,
                'min_insurance_amount' => $min,
                'sort_order' => $i + 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('diaspo_bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parcel_nature_id');
            $table->dropColumn(['declared_value', 'insurance_amount']);
        });
        Schema::dropIfExists('parcel_natures');
    }
};
