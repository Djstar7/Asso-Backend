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
            $table->json('announced_topics')->nullable();
            $table->timestamp('announced_at')->nullable()->index();
        });
        // Ne pas diffuser rétroactivement tout le catalogue lors du déploiement.
        DB::table('real_estate_listings')->where('status', 'published')->update(['announced_at' => now()]);
        Schema::table('real_estate_alerts', function (Blueprint $table) {
            $table->timestamp('pending_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('real_estate_alerts', fn (Blueprint $table) => $table->dropColumn('pending_until'));
        Schema::table('real_estate_listings', function (Blueprint $table) {
            $table->dropIndex(['announced_at']);
            $table->dropColumn(['announced_topics', 'announced_at']);
        });
    }
};
