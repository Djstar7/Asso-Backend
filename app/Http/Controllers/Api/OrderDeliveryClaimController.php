<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DisputeReceipt;
use App\Models\Order;
use App\Models\OrderDeliveryClaim;
use App\Services\DisputeResolutionService;
use Illuminate\Http\Request;

class OrderDeliveryClaimController extends Controller
{
    public function show(Request $request, $orderId)
    {
        $order = Order::where('user_id', $request->user()->id)->findOrFail($orderId);
        $claim = OrderDeliveryClaim::where('order_id', $order->id)->first();

        return response()->json(['success' => true, 'claim' => $claim ? $this->present($claim) : null]);
    }

    public function store(Request $request, $orderId, DisputeResolutionService $resolution)
    {
        $data = $request->validate(['reason' => 'required|in:'.implode(',', OrderDeliveryClaim::REASONS), 'description' => 'required|string|min:10|max:2000', 'photos' => 'nullable|array|max:6', 'photos.*' => 'image|max:8192']);
        $order = Order::where('user_id', $request->user()->id)->findOrFail($orderId);
        try {
            $paths = collect($request->file('photos', []))->map(fn ($file) => $file->store('disputes/delivery-claims', 'local'))->all();
            $claim = $resolution->openDeliveryClaim($order, $request->user(), $data['reason'], $data['description'], $paths);
        } catch (\DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => __('disputes.delivery_claim_opened'), 'claim' => $this->present($claim)], 201);
    }

    public function contest(Request $request, DisputeReceipt $receipt, DisputeResolutionService $resolution)
    {
        abort_unless($receipt->shipment->dispute->seller_id === $request->user()->id, 404);
        $data = $request->validate(['attachment_id' => 'required|integer', 'note' => 'required|string|min:10|max:2000']);
        try {
            $resolution->contestReceipt($receipt, $request->user(), $data['attachment_id'], $data['note']);
        } catch (\DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => __('disputes.receipt_contested_success')]);
    }

    private function present(OrderDeliveryClaim $claim): array
    {
        return $claim->only(['id', 'order_id', 'reason', 'description', 'status', 'decision_note', 'refund_amount', 'resolved_at', 'created_at']);
    }
}
