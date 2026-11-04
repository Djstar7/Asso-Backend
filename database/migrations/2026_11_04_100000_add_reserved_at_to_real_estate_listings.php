<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('real_estate_listings', function (Blueprint $table) {
            $table->timestamp('reserved_at')->nullable()->after('available_from');
        });

        // Biens sans unités déjà payés : jusqu'ici, rien ne les retirait.
        DB::table('real_estate_listings')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('real_estate_units')
                ->whereColumn('real_estate_units.listing_id', 'real_estate_listings.id'))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('real_estate_reservations')
                ->whereColumn('real_estate_reservations.listing_id', 'real_estate_listings.id')
                ->whereIn('real_estate_reservations.status', ['paid', 'confirmed', 'disputed', 'released']))
            ->update(['reserved_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('real_estate_listings', function (Blueprint $table) {
            $table->dropColumn('reserved_at');
        });
    }
};
