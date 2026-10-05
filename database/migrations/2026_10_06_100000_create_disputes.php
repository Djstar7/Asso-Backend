<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Réclamations & litiges.
 *
 * Commande : la part du vendeur est créditée sur son Wallet mais BLOQUÉE dès la
 * validation, puis débloquée quand le client confirme « Tout est conforme », au bout
 * de 48 h sans action après la livraison, ou quand ASSO juge une réclamation non
 * fondée. Une réclamation porte sur un article : la part de cet article passe de la
 * commande au litige et y reste bloquée jusqu'à la décision.
 *
 * Litige : décision ASSO, remplacement (une seule fois) ou retour + remboursement.
 * Les courses de remplacement et de retour sont payées par le vendeur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // held → released (conforme, 48 h, non fondée) ; null = commande réglée avant le blocage.
            $table->string('vendor_funds_status', 10)->nullable()->after('settled_at');
            // Vendeur crédité au règlement (part bloquée sur SON Wallet).
            $table->foreignId('vendor_funds_holder_id')->nullable()->after('vendor_funds_status')->constrained('users')->nullOnDelete();
            // Part encore bloquée au niveau de la commande (hors parts passées sur un litige).
            $table->decimal('vendor_funds_held_amount', 12, 2)->nullable()->after('vendor_funds_holder_id');
            $table->timestamp('vendor_funds_released_at')->nullable()->after('vendor_funds_held_amount');
            $table->timestamp('conformity_confirmed_at')->nullable()->after('vendor_funds_released_at');
            // Fin de la fenêtre de contrôle (livraison + 48 h).
            $table->timestamp('auto_validate_at')->nullable()->after('conformity_confirmed_at');

            $table->index(['vendor_funds_status', 'auto_validate_at']);
        });

        Schema::create('disputes', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('seller_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 20);
            $table->text('description');
            // Part vendeur de l'article, bloquée sur le Wallet du vendeur.
            $table->decimal('held_amount', 12, 2)->default(0);
            // Remboursement versé au client (Cas B).
            $table->decimal('refund_amount', 12, 2)->nullable();
            $table->string('status', 20)->default('new');
            $table->string('decision', 10)->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('resolution', 20)->nullable();
            $table->unsignedTinyInteger('replacement_count')->default(0);
            // Nouveau contrôle de 48 h après la livraison du remplacement.
            $table->timestamp('auto_validate_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['seller_id', 'status']);
        });

        Schema::create('dispute_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispute_id')->constrained()->cascadeOnDelete();
            $table->string('author_type', 10); // client | vendor | admin
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('path');
            $table->timestamps();
        });

        Schema::create('dispute_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispute_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('label');
            $table->text('note')->nullable();
            // Note réservée à l'équipe ASSO (jamais renvoyée au client ni au vendeur).
            $table->boolean('internal')->default(false);
            $table->string('actor_type', 10); // client | vendor | admin | system | partner
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();
        });

        Schema::create('dispute_shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispute_id')->constrained()->cascadeOnDelete();
            $table->string('type', 12); // replacement | return
            $table->foreignId('deliverer_company_id')->nullable()->constrained('deliverer_companies')->nullOnDelete();
            $table->json('quote')->nullable();
            // Prix payé par le vendeur, dont la part du partenaire et la commission ASSO.
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('carrier_amount', 12, 2)->default(0);
            $table->decimal('asso_commission', 12, 2)->default(0);
            $table->string('payer', 10)->default('vendor'); // vendor | asso
            $table->string('payment_status', 10)->default('pending'); // pending | paid | failed
            $table->string('payment_mode', 20)->nullable();
            $table->string('payment_reference')->nullable();
            $table->string('payment_currency', 3)->nullable();
            $table->decimal('payment_amount', 14, 2)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('status', 24)->default('awaiting_payment');
            $table->string('carrier_tracking_number')->nullable();
            $table->string('proof_path')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['dispute_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispute_shipments');
        Schema::dropIfExists('dispute_events');
        Schema::dropIfExists('dispute_attachments');
        Schema::dropIfExists('disputes');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['vendor_funds_status', 'auto_validate_at']);
            $table->dropConstrainedForeignId('vendor_funds_holder_id');
            $table->dropColumn([
                'vendor_funds_status', 'vendor_funds_held_amount', 'vendor_funds_released_at',
                'conformity_confirmed_at', 'auto_validate_at',
            ]);
        });
    }
};
