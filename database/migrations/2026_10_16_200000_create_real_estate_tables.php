<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rubrique Immobilier : maisons à louer (bailleurs vérifiés) et terrains à
 * vendre (saisis par ASSO, rattachés à un cabinet partenaire).
 *
 * Les prix sont stockés NETS (ce que touche le bailleur ou le cabinet) en
 * FCFA ; le prix affiché est calculé par RealEstatePricingService avec la
 * majoration réglée dans le back-office.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Cabinets partenaires : créés par ASSO, comme les partenaires de livraison.
        Schema::create('real_estate_cabinets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete()
                ->comment('Compte du cabinet : porte son Wallet');
            $table->string('name');
            $table->string('tagline')->nullable();
            $table->string('specialties')->nullable();
            $table->text('description')->nullable();
            $table->json('why_choose_us')->nullable();
            $table->text('owners_message')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('banner_path')->nullable();
            $table->string('region')->nullable();
            $table->string('headquarters_city')->nullable();
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('whatsapp')->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_verified')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('real_estate_listings', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->string('type', 10)->index()->comment('rent | land');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cabinet_id')->nullable()->constrained('real_estate_cabinets')->nullOnDelete();
            $table->string('property_kind', 30)->nullable();
            $table->string('title');
            $table->text('owner_description')->nullable();

            // Localisation : le quartier et la ville sont publics, le reste
            // n'est communiqué qu'après paiement.
            $table->string('country', 2)->default('CM');
            $table->string('city')->index();
            $table->string('district')->nullable()->index();
            $table->string('address')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // Caractéristiques.
            $table->unsignedTinyInteger('rooms')->nullable();
            $table->unsignedTinyInteger('bedrooms')->nullable();
            $table->unsignedTinyInteger('bathrooms')->nullable();
            $table->unsignedInteger('surface_m2')->nullable();
            $table->boolean('furnished')->default(false);

            // Questionnaire d'accès et de quartier (génère la description).
            $table->string('road_access', 20)->nullable()->comment('roadside | near_road | inside');
            $table->unsignedInteger('distance_to_road_m')->nullable();
            $table->string('road_condition', 20)->nullable()->comment('paved | dirt | poor');
            $table->boolean('is_landlocked')->default(false);
            $table->string('water_source', 20)->nullable()->comment('network | borehole | well | none');
            $table->string('electricity', 20)->nullable()->comment('network | prepaid | solar | none');
            $table->string('security_level', 20)->nullable()->comment('high | medium | low');
            $table->boolean('has_parking')->default(false);
            $table->boolean('has_fence')->default(false);
            $table->boolean('has_guard')->default(false);

            // Terrain.
            $table->boolean('land_titled')->default(false);
            $table->string('land_title_status', 30)->nullable();
            $table->boolean('serviced')->default(false)->comment('Viabilisé');
            $table->decimal('deposit_percent', 5, 2)->nullable();

            // Conditions de location.
            $table->unsignedSmallInteger('min_lease_months')->nullable();
            $table->unsignedSmallInteger('advance_months')->nullable();
            $table->date('available_from')->nullable();

            // Prix nets (FCFA) ; « À partir de » = plus bas des unités.
            $table->unsignedBigInteger('net_price')->default(0);
            $table->unsignedBigInteger('old_net_price')->nullable();
            $table->string('promo_text')->nullable();

            $table->boolean('is_featured')->default(false)->index();
            $table->unsignedInteger('views_count')->default(0);
            $table->string('status', 20)->default('draft')->index()
                ->comment('draft | pending_review | published | rejected | archived');
            $table->string('rejection_reason')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Types de logement d'une annonce (studio, chambre…) ou lots de
        // surface d'un terrain (300 m², 500 m²…).
        Schema::create('real_estate_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained('real_estate_listings')->cascadeOnDelete();
            $table->string('label');
            $table->unsignedInteger('surface_min')->nullable();
            $table->unsignedInteger('surface_max')->nullable();
            $table->unsignedBigInteger('net_price');
            $table->unsignedBigInteger('old_net_price')->nullable();
            $table->unsignedTinyInteger('capacity')->nullable();
            $table->string('kitchen', 20)->nullable()->comment('private | shared | none');
            $table->boolean('furnished')->default(false);
            $table->boolean('serviced')->default(false);
            $table->unsignedSmallInteger('units_total')->default(1);
            $table->unsignedSmallInteger('units_available')->default(1);
            $table->date('available_from')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        // Pièces de la visite pièce par pièce.
        Schema::create('real_estate_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained('real_estate_listings')->cascadeOnDelete();
            $table->string('kind', 20)->comment('entrance | living | bedroom | kitchen | bathroom | outside | road | other');
            $table->string('label')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('real_estate_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained('real_estate_listings')->cascadeOnDelete();
            $table->foreignId('room_id')->nullable()->constrained('real_estate_rooms')->nullOnDelete();
            $table->string('type', 10)->default('image');
            $table->string('path');
            $table->string('poster_path')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_cover')->default(false);
            $table->timestamps();
        });

        Schema::create('real_estate_amenities', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20)->comment('highlight | equipment | service');
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('icon', 60)->nullable()->comment('Nom d\'icône Material côté app');
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('real_estate_amenity_listing', function (Blueprint $table) {
            $table->foreignId('listing_id')->constrained('real_estate_listings')->cascadeOnDelete();
            $table->foreignId('amenity_id')->constrained('real_estate_amenities')->cascadeOnDelete();
            $table->primary(['listing_id', 'amenity_id']);
        });

        Schema::create('real_estate_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('listing_id')->constrained('real_estate_listings')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'listing_id']);
        });

        // Critères enregistrés (« Vos critères » / « Actualiser mon alerte »).
        Schema::create('real_estate_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10)->default('rent');
            $table->string('applicant_status', 20)->nullable();
            $table->string('school')->nullable();
            $table->string('city')->nullable();
            $table->date('move_in_date')->nullable();
            $table->unsignedSmallInteger('lease_months')->nullable();
            $table->unsignedBigInteger('budget_min')->nullable();
            $table->unsignedBigInteger('budget_max')->nullable();
            $table->json('filters')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_notified_at')->nullable();
            $table->timestamps();
            $table->unique('user_id');
        });

        // « Confier mon projet ».
        Schema::create('real_estate_project_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('has_land');
            $table->string('city')->nullable();
            $table->string('district')->nullable();
            $table->unsignedInteger('surface_m2')->nullable();
            $table->unsignedBigInteger('budget')->nullable();
            $table->string('name')->nullable();
            $table->string('phone')->nullable();
            $table->text('message')->nullable();
            $table->string('status', 20)->default('new');
            $table->timestamps();
        });

        // Réservations payées : débloquent les coordonnées. Le parcours de
        // paiement (lot 3) les alimente ; le masquage s'appuie déjà dessus.
        Schema::create('real_estate_reservations', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->foreignId('listing_id')->constrained('real_estate_listings')->cascadeOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('real_estate_units')->nullOnDelete();
            $table->foreignId('tenant_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('payee_id')->nullable()->constrained('users')->nullOnDelete()
                ->comment('Bailleur ou compte du cabinet');
            $table->string('kind', 10);
            $table->date('move_in_date')->nullable();
            $table->unsignedSmallInteger('months')->nullable();
            $table->json('occupants')->nullable();
            $table->string('applicant_status', 20)->nullable();
            $table->string('school_or_employer')->nullable();
            $table->text('message')->nullable();
            $table->unsignedBigInteger('displayed_unit_price')->default(0);
            $table->unsignedSmallInteger('advance_months')->nullable();
            $table->unsignedBigInteger('owner_amount')->default(0);
            $table->unsignedBigInteger('platform_amount')->default(0);
            $table->unsignedBigInteger('total_amount')->default(0);
            $table->string('payment_method', 20)->nullable();
            $table->string('payment_reference')->nullable()->index();
            $table->string('status', 20)->default('pending_payment')->index();
            $table->timestamp('contract_accepted_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('visit_deadline_at')->nullable()->index();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->string('contract_path')->nullable();
            $table->string('invoice_path')->nullable();
            $table->timestamps();
        });

        // Statut bailleur : seul un bailleur vérifié voit ses annonces publiées.
        Schema::table('users', function (Blueprint $table) {
            $table->string('landlord_verification_status', 20)->default('none')
                ->comment('none | pending | verified | rejected');
            $table->timestamp('landlord_verified_at')->nullable();
        });

        // Contexte immobilier d'une conversation avec ASSO.
        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('real_estate_listing_id')->nullable()->after('diaspo_offer_id')
                ->constrained('real_estate_listings')->nullOnDelete();
        });
        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('real_estate_listing_id')->nullable()->after('diaspo_offer_id')
                ->constrained('real_estate_listings')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('real_estate_listing_id');
        });
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('real_estate_listing_id');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['landlord_verification_status', 'landlord_verified_at']);
        });
        Schema::dropIfExists('real_estate_reservations');
        Schema::dropIfExists('real_estate_project_requests');
        Schema::dropIfExists('real_estate_alerts');
        Schema::dropIfExists('real_estate_favorites');
        Schema::dropIfExists('real_estate_amenity_listing');
        Schema::dropIfExists('real_estate_amenities');
        Schema::dropIfExists('real_estate_media');
        Schema::dropIfExists('real_estate_rooms');
        Schema::dropIfExists('real_estate_units');
        Schema::dropIfExists('real_estate_listings');
        Schema::dropIfExists('real_estate_cabinets');
    }
};
