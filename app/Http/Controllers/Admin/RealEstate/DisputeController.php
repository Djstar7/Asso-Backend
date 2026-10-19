<?php

namespace App\Http\Controllers\Admin\RealEstate;

use App\Http\Controllers\Controller;
use App\Models\RealEstate\Dispute;
use App\Models\RealEstate\DisputeReason;
use App\Services\RealEstate\RealEstateDisputeService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Litiges immobiliers et leurs motifs. */
class DisputeController extends Controller
{
    public function __construct(private RealEstateDisputeService $service)
    {
    }

    public function index(Request $request)
    {
        $status = $request->query('status', 'open');
        $disputes = Dispute::with(['reservation.listing', 'reservation.tenant', 'reservation.payee'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->latest()->paginate(25)->withQueryString();

        return view('admin.real_estate.disputes.index', [
            'disputes' => $disputes,
            'status' => $status,
            'counts' => Dispute::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'reasons' => DisputeReason::orderBy('kind')->orderBy('position')->get(),
        ]);
    }

    public function show(Dispute $dispute)
    {
        $dispute->load(['reservation.listing.cabinet', 'reservation.tenant', 'reservation.payee', 'reservation.unit', 'attachments', 'decider']);

        return view('admin.real_estate.disputes.show', ['dispute' => $dispute]);
    }

    public function decide(Request $request, Dispute $dispute)
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['refund', 'release'])],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        try {
            $this->service->decide($dispute, $data['decision'], $data['note'] ?? null, $request->user()->id);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $data['decision'] === 'refund'
            ? 'Locataire remboursé de la part bloquée. La commission ASSO reste acquise.'
            : 'Part débloquée au bailleur.');
    }

    public function storeReason(Request $request)
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['rent', 'land', 'all'])],
            'label' => ['required', 'string', 'max:150'],
            'label_en' => ['nullable', 'string', 'max:150'],
        ]);
        $reason = DisputeReason::create([
            'kind' => $data['kind'], 'label' => $data['label'],
            'position' => (int) DisputeReason::max('position') + 1,
        ]);
        $reason->setTranslation('label', 'en', $data['label_en'] ?? null);

        return back()->with('success', 'Motif ajouté.');
    }

    public function updateReason(Request $request, DisputeReason $reason)
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['rent', 'land', 'all'])],
            'label' => ['required', 'string', 'max:150'],
            'label_en' => ['nullable', 'string', 'max:150'],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);
        $reason->update([
            'kind' => $data['kind'], 'label' => $data['label'],
            'position' => $data['position'] ?? $reason->position, 'is_active' => $request->boolean('is_active'),
        ]);
        $reason->setTranslation('label', 'en', $data['label_en'] ?? null);

        return back()->with('success', 'Motif mis à jour.');
    }
}
