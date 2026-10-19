<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Litiges immobiliers : motifs réglés dans le back-office (FR/EN), litige
 * ouvert par le locataire ou l'acheteur avant la fin du délai de visite,
 * pièces des deux parties, décision d'ASSO.
 */
return new class extends Migration
{
    private const REASONS = [
        // [kind, fr, en]
        ['rent', 'Le logement ne correspond pas aux photos ou à l\'annonce', 'The home does not match the photos or listing'],
        ['rent', 'Le bailleur est injoignable', 'The landlord cannot be reached'],
        ['rent', 'Le logement n\'est plus disponible', 'The home is no longer available'],
        ['rent', 'Problème de sécurité ou d\'insalubrité', 'Safety or health problem'],
        ['rent', 'L\'adresse ou le quartier est différent', 'The address or neighbourhood is different'],
        ['land', 'Le cabinet est injoignable', 'The firm cannot be reached'],
        ['land', 'Le terrain ne correspond pas à l\'annonce', 'The plot does not match the listing'],
        ['land', 'Problème sur les documents du terrain', 'Problem with the land documents'],
        ['all', 'Autre problème', 'Other problem'],
    ];

    public function up(): void
    {
        Schema::create('real_estate_dispute_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 10)->default('all')->comment('rent | land | all');
            $table->string('label');
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('real_estate_disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->unique()->constrained('real_estate_reservations')->cascadeOnDelete();
            $table->foreignId('reason_id')->nullable()->constrained('real_estate_dispute_reasons')->nullOnDelete();
            $table->string('reason_label');
            $table->text('description');
            $table->text('payee_response')->nullable();
            $table->string('status', 20)->default('open')->index()->comment('open | refunded | released');
            $table->text('decision_note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        Schema::create('real_estate_dispute_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispute_id')->constrained('real_estate_disputes')->cascadeOnDelete();
            $table->string('author', 10)->comment('tenant | payee | admin');
            $table->string('path');
            $table->timestamps();
        });

        $now = now();
        foreach (self::REASONS as $position => [$kind, $fr, $en]) {
            $id = DB::table('real_estate_dispute_reasons')->insertGetId([
                'kind' => $kind, 'label' => $fr, 'position' => $position, 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('translations')->insert([
                'translatable_type' => \App\Models\RealEstate\DisputeReason::class,
                'translatable_id' => $id, 'locale' => 'en', 'field' => 'label', 'value' => $en,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('translations')->where('translatable_type', \App\Models\RealEstate\DisputeReason::class)->delete();
        Schema::dropIfExists('real_estate_dispute_attachments');
        Schema::dropIfExists('real_estate_disputes');
        Schema::dropIfExists('real_estate_dispute_reasons');
    }
};
