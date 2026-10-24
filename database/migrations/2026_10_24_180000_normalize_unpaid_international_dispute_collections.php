<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Only untouched, unpaid replacement drafts from the previous export flow.
        $rows = DB::table('dispute_shipments as s')->join('disputes as d', 'd.id', '=', 's.dispute_id')
            ->join('orders as o', 'o.id', '=', 'd.order_id')
            ->where('o.delivery_breakdown->international_export', true)
            ->where('s.type', 'replacement')->where('s.status', 'awaiting_payment')
            ->where('s.payment_status', 'pending')->whereNull('s.payment_reference')->whereNull('s.quote')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('dispute_shipments as c')
                ->whereColumn('c.dispute_id', 's.dispute_id')->where('c.type', 'collection'))
            ->select('s.id', 's.stops')->get();
        foreach ($rows as $row) {
            $stops = json_decode($row->stops ?? '{}', true);
            DB::table('dispute_shipments')->where('id', $row->id)->update([
                'type' => 'collection',
                'stops' => isset($stops['pickup'], $stops['dropoff'])
                    ? json_encode(['pickup' => $stops['dropoff'], 'dropoff' => $stops['pickup']]) : null,
            ]);
        }
    }

    public function down(): void
    {
        // Operational history and paid shipments must never be reversed by rollback.
    }
};
