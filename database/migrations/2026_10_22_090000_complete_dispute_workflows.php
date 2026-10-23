<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('disputes', function (Blueprint $table) {
            $table->boolean('asso_managed')->nullable();
            $table->decimal('vendor_refunded_amount', 12, 2)->nullable();
            $table->unsignedInteger('quantity')->nullable();
            $table->timestamp('vendor_response_due_at')->nullable();
            $table->timestamp('vendor_responded_at')->nullable();
            $table->timestamp('vendor_reminded_at')->nullable();
            $table->timestamp('vendor_escalated_at')->nullable();
            $table->timestamp('replacement_due_at')->nullable();
        });
        Schema::table('dispute_shipments', function (Blueprint $table) {
            $table->json('stops')->nullable();
            $table->text('funding_error')->nullable();
        });
        // Les missions déjà payées gardent leur historique ; les missions ASSO en
        // attente deviennent finançables depuis le back-office.
        $platformId = \App\Services\CommissionService::platformAccount()?->id;
        $assoDisputes = DB::table('disputes')->where(function ($query) use ($platformId) {
            $query->whereIn('order_id', DB::table('orders')->select('id')->where('is_wholesale', true));
            if ($platformId) {
                $query->orWhere('seller_id', $platformId);
            }
        })->select('id');
        DB::table('dispute_shipments')->where('payment_status', '!=', 'paid')
            ->whereNull('payment_reference')->whereIn('dispute_id', $assoDisputes)->update(['payer' => 'asso']);

    }

    public function down(): void
    {
        Schema::table('dispute_shipments', fn (Blueprint $table) => $table->dropColumn(['stops', 'funding_error']));
        Schema::table('disputes', fn (Blueprint $table) => $table->dropColumn([
            'asso_managed', 'vendor_refunded_amount', 'quantity', 'vendor_response_due_at', 'vendor_responded_at',
            'vendor_reminded_at', 'vendor_escalated_at', 'replacement_due_at',
        ]));
    }
};
