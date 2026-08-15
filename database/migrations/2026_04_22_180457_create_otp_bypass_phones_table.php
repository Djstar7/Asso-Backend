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
        Schema::create('otp_bypass_phones', function (Blueprint $table) {
            $table->id();
            $table->string('phone')->unique()->comment('Numéro de téléphone autorisé à bypasser l\'OTP');
            $table->text('reason')->nullable()->comment('Raison du bypass');
            $table->foreignId('added_by')->nullable()->constrained('users')->onDelete('set null')->comment('Admin qui a ajouté ce numéro');
            $table->boolean('is_active')->default(true)->comment('Statut du bypass');
            $table->timestamps();

            // Index pour optimiser les recherches
            $table->index(['phone', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('otp_bypass_phones');
    }
};
