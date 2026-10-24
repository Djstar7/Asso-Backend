<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Dispute;
use App\Models\DisputeAdvance;
use App\Models\DisputeReceipt;
use App\Models\DisputeResolutionTask;
use App\Models\DisputeShipment;
use App\Models\Order;
use App\Models\OrderDeliveryClaim;
use App\Services\DisputeResolutionService;
use Illuminate\Http\Request;

class DisputeResolutionController extends Controller
{
    public function __construct(private DisputeResolutionService $resolution) {}

    public function index(Request $request)
    {
        $this->resolution->assertAdmin($request->user());

        return view('admin.disputes.resolution', [
            'tasks' => DisputeResolutionTask::with(['dispute', 'claim.order', 'assignee'])->whereNull('completed_at')->orderBy('due_at')->paginate(25),
            'claims' => OrderDeliveryClaim::with('order')->when($request->filled('claim_id'), fn ($q) => $q->whereKey($request->integer('claim_id')))
                ->orderByRaw("case when status = 'open' then 0 else 1 end")->latest()->paginate(20, ['*'], 'claims_page')->withQueryString(),
            'advances' => DisputeAdvance::where('status', 'outstanding')->latest()->paginate(20, ['*'], 'advances_page')->withQueryString(),
        ]);
    }

    public function receipt(Request $request, DisputeShipment $shipment)
    {
        $this->resolution->assertAdmin($request->user());
        $data = $request->validate(['attachment_id' => 'required|integer', 'location' => 'required|string|max:255', 'note' => 'required|string|min:10|max:2000']);

        return $this->run(fn () => $this->resolution->registerReceipt($shipment, $request->user(), $data['attachment_id'], $data['location'], $data['note']));
    }

    public function accept(Request $request, DisputeReceipt $receipt)
    {
        $this->resolution->assertAdmin($request->user());
        $data = $request->validate(['note' => 'required|string|min:10|max:2000']);

        return $this->run(fn () => $this->resolution->acceptReceipt($receipt, $request->user(), $data['note']));
    }

    public function refund(Request $request, Dispute $dispute)
    {
        $this->resolution->assertAdmin($request->user());
        $data = $request->validate(['attachment_id' => 'required|integer', 'note' => 'required|string|min:10|max:2000']);

        return $this->run(fn () => $this->resolution->refund($dispute, $request->user(), $data['attachment_id'], $data['note']));
    }

    public function advance(Request $request, DisputeShipment $shipment)
    {
        $this->resolution->assertAdmin($request->user());
        $data = $request->validate(['note' => 'required|string|min:10|max:2000']);

        return $this->run(fn () => $this->resolution->advanceTransport($shipment, $request->user(), $data['note']));
    }

    public function openClaim(Request $request, Order $order)
    {
        $this->resolution->assertAdmin($request->user());
        $data = $request->validate(['reason' => 'required|in:'.implode(',', OrderDeliveryClaim::REASONS), 'description' => 'required|string|min:10|max:2000']);

        return $this->run(fn () => $this->resolution->openDeliveryClaim($order, $order->user, $data['reason'], $data['description']));
    }

    public function decideClaim(Request $request, OrderDeliveryClaim $claim)
    {
        $this->resolution->assertAdmin($request->user());
        $data = $request->validate(['decision' => 'required|in:refund,reject', 'note' => 'required|string|min:10|max:2000',
            'proof' => 'required|file|mimes:jpg,jpeg,png,pdf|max:8192']);
        $path = $request->file('proof')->store('disputes/delivery-claims', 'local');

        return $this->run(fn () => $this->resolution->resolveDeliveryClaim($claim, $request->user(), $data['decision'], $data['note'], $path));
    }

    public function proof(Request $request, OrderDeliveryClaim $claim, string $index)
    {
        $this->resolution->assertAdmin($request->user());
        $path = $index === 'decision' ? $claim->proof_path : ($claim->client_proofs[(int) $index] ?? null);
        abort_unless($path && \Illuminate\Support\Facades\Storage::disk('local')->exists($path), 404);

        return \Illuminate\Support\Facades\Storage::disk('local')->download($path);
    }

    public function settings(Request $request)
    {
        $this->resolution->assertAdmin($request->user());
        $rules = collect(['review_hours', 'payment_hours', 'receipt_contest_hours', 'local_transit_hours',
            'international_transit_hours', 'dispatch_hours', 'preparation_hours', 'import_preparation_hours'])
            ->mapWithKeys(fn ($key) => [$key => 'required|integer|min:1|max:8760'])->all();
        $data = $request->validate($rules + ['reminder_lead_hours' => 'sometimes|required|integer|min:1|max:720', 'automatic_advance_limit' => 'required|numeric|min:0|max:10000000']);
        foreach ($data as $key => $value) {
            \App\Models\Setting::set('dispute_'.$key, $value, $key === 'automatic_advance_limit' ? 'string' : 'integer', 'disputes');
        }

        return back()->with('success', 'Règles de résolution enregistrées. Les échéances déjà engagées sont conservées.');
    }

    private function run(callable $action)
    {
        try {
            $action();

            return back()->with('success', 'Décision enregistrée et traitement effectué.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            throw $e;
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
