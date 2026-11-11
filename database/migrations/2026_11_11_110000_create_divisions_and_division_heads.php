<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rubriques de la plateforme et chefs de rubrique.
 *
 * - divisions : une rubrique (ex. « Électronique ») regroupe une ou plusieurs
 *   catégories du catalogue (division_category, une catégorie n'appartient qu'à
 *   une rubrique) ; une rubrique « Immobilier » porte sur le module immobilier.
 * - division_heads : affectations successives d'un chef à une rubrique, avec son
 *   pourcentage. Jamais modifiées après coup : changer de chef ou de taux ferme
 *   l'affectation et en ouvre une nouvelle.
 * - division_commissions : part du chef sur la commission ASSO de chaque vente de
 *   sa rubrique, figée à la vente (taux, base), versée sur son Wallet quand la
 *   vente est définitivement acquise.
 * - users.role accepte `division_head`.
 */
return new class extends Migration
{
    private const ROLES = ['admin', 'client', 'vendeur', 'livreur', 'product_manager', 'division_head'];

    private const PREVIOUS_ROLES = ['admin', 'client', 'vendeur', 'livreur', 'product_manager'];

    public function up(): void
    {
        $this->setAllowedRoles(self::ROLES);

        Schema::create('divisions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('name_en')->nullable();
            // category : catégories du catalogue ; real_estate : module Immobilier.
            $table->string('type', 30)->default('category');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('division_category', function (Blueprint $table) {
            $table->id();
            $table->foreignId('division_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('division_heads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('division_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            // Pourcentage de la commission ASSO des ventes de la rubrique.
            $table->decimal('commission_rate', 5, 2);
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('end_reason')->nullable();
            $table->timestamps();
            $table->index(['division_id', 'started_at', 'ended_at']);
        });

        Schema::create('division_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('division_id')->constrained()->restrictOnDelete();
            $table->foreignId('division_head_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            // order_item | real_estate_reservation
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference')->nullable();
            $table->timestamp('sold_at');
            $table->decimal('sale_amount', 14, 2)->default(0);
            // Commission ASSO de la vente, base du calcul.
            $table->decimal('base_amount', 14, 2);
            $table->decimal('rate', 5, 2);
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('XAF');
            // pending | available | cancelled | clawed_back
            $table->string('status', 20)->default('pending');
            $table->foreignId('wallet_transaction_id')->nullable()->constrained('wallet_transactions')->nullOnDelete();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            // Reprise après versement (remboursement tardif) : reste à retenir.
            $table->decimal('clawback_due', 14, 2)->default(0);
            $table->timestamps();

            $table->unique(['source_type', 'source_id']);
            $table->index(['user_id', 'status']);
            $table->index(['division_id', 'sold_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('division_commissions');
        Schema::dropIfExists('division_heads');
        Schema::dropIfExists('division_category');
        Schema::dropIfExists('divisions');

        DB::table('users')->where('role', 'division_head')->update(['role' => 'client', 'roles' => json_encode(['client'])]);
        $this->setAllowedRoles(self::PREVIOUS_ROLES);
    }

    private function setAllowedRoles(array $roles): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $list = implode(', ', array_map(fn ($r) => "'{$r}'::character varying", $roles));
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (((role)::text = ANY ((ARRAY[{$list}])::text[])))");

            return;
        }

        Schema::table('users', function (Blueprint $table) use ($roles) {
            $table->enum('role', $roles)->default('client')->change();
        });
    }
};
