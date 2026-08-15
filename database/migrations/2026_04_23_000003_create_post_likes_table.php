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
        Schema::create('post_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->morphs('likeable'); // likeable_id + likeable_type (Post ou PostComment)
            $table->enum('type', ['like', 'dislike'])->default('like');
            $table->timestamps();

            // Indexes et contrainte unique
            $table->unique(['user_id', 'likeable_id', 'likeable_type']);
            $table->index('likeable_id');
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('post_likes');
    }
};
