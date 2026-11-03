<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_login_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            // Secrets stockés hachés : une fuite de la table ne connecte personne.
            $table->string('secret_hash', 64);
            $table->string('code_hash', 64)->unique();
            $table->string('status', 16)->default('pending');
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('token_id')->nullable()->index();
            $table->string('browser', 40);
            $table->string('platform', 40);
            $table->string('ip', 45);
            $table->timestamp('expires_at')->index();
            $table->timestamp('scanned_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_login_sessions');
    }
};
