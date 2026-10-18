<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('parcel_natures', 'min_insurance_amount')) {
            Schema::table('parcel_natures', function (Blueprint $table) {
                $table->dropColumn('min_insurance_amount');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('parcel_natures', 'min_insurance_amount')) {
            Schema::table('parcel_natures', function (Blueprint $table) {
                $table->decimal('min_insurance_amount', 12, 2)->default(0);
            });
        }
    }
};
