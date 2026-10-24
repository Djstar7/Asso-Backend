<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('disputes', function (Blueprint $t) {
            $t->timestamp('receipt_waived_at')->nullable();
        });
        Schema::create('dispute_receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('dispute_shipment_id')->unique()->constrained()->cascadeOnDelete();
            $t->foreignId('verified_by')->constrained('users');
            $t->foreignId('dispute_attachment_id')->constrained()->restrictOnDelete();
            $t->string('location');
            $t->text('note');
            $t->timestamp('contest_until');
            $t->text('contest_note')->nullable();
            $t->foreignId('contest_attachment_id')->nullable()->constrained('dispute_attachments');
            $t->timestamp('contested_at')->nullable();
            $t->timestamp('accepted_at')->nullable();
            $t->timestamps();
        });
        Schema::create('order_delivery_claims', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('reason');
            $t->text('description');
            $t->string('status')->default('open');
            $t->json('client_proofs')->nullable();
            $t->text('decision_note')->nullable();
            $t->string('proof_path')->nullable();
            $t->foreignId('decided_by')->nullable()->constrained('users');
            $t->decimal('refund_amount', 14, 2)->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
        });
        Schema::create('dispute_resolution_tasks', function (Blueprint $t) {
            $t->id();
            $t->string('workflow_key')->unique();
            $t->foreignId('dispute_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('order_delivery_claim_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('stage');
            $t->string('responsible');
            $t->text('expected_proof');
            $t->timestamp('due_at')->index();
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->unsignedInteger('escalations')->default(0);
            $t->timestamp('escalated_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->text('last_error')->nullable();
            $t->timestamps();
        });
        Schema::create('dispute_advances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('dispute_shipment_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $t->foreignId('order_delivery_claim_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $t->foreignId('debtor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('authorized_by')->nullable()->constrained('users')->nullOnDelete();
            $t->decimal('amount', 14, 2);
            $t->string('currency', 3)->default('XAF');
            $t->string('status')->default('outstanding');
            $t->text('note');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispute_advances');
        Schema::dropIfExists('dispute_resolution_tasks');
        Schema::dropIfExists('order_delivery_claims');
        Schema::dropIfExists('dispute_receipts');
        Schema::table('disputes', fn (Blueprint $t) => $t->dropColumn('receipt_waived_at'));
    }
};
