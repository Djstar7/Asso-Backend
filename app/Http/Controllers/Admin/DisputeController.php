<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Dispute;
use App\Models\DisputeShipment;
use App\Services\DisputeService;
use Illuminate\Http\Request;

/**
 * Réclamations / litiges : l'équipe ASSO analyse chaque dossier (motif, preuves du
 * client, réponse du vendeur), décide (fondée / non fondée), puis pilote le
 * remplacement ou le retour jusqu'au remboursement. ASSO garde la décision finale.
 */
class DisputeController extends Controller
{
    public function __construct(private DisputeService $disputes)
    {
    }

    public function index(Request $request)
    {
        $status = $request->input('status', 'open');
        $query = Dispute::with(['order', 'item.product.primaryImage', 'client', 'seller']);

        if ($status === 'open') {
            $query->open();
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }

        // Gros : courses que l'équipe ASSO doit payer à la place du vendeur.
        if ($request->boolean('asso_payment')) {
            $query->whereHas('shipments', fn ($s) => $s->where('payer', 'asso')
                ->where('payment_status', '!=', \App\Models\DisputeShipment::PAYMENT_PAID));
        }

        if ($request->filled('search')) {
            $like = '%' . mb_strtolower(addcslashes(trim($request->input('search')), '%_\\')) . '%';
            $query->where(fn ($q) => $q->whereRaw('LOWER(number) LIKE ?', [$like])
                ->orWhereHas('order', fn ($o) => $o->whereRaw('LOWER(order_number) LIKE ?', [$like]))
                ->orWhereHas('client', fn ($u) => $u->whereRaw("LOWER(first_name || ' ' || last_name) LIKE ?", [$like])->orWhere('phone', 'like', $like))
                ->orWhereHas('seller', fn ($u) => $u->whereRaw("LOWER(first_name || ' ' || last_name) LIKE ?", [$like])->orWhere('phone', 'like', $like)));
        }

        return view('admin.disputes.index', [
            'disputes' => $query->latest()->paginate(20)->withQueryString(),
            'status' => $status,
            'counts' => collect(Dispute::STATUSES)->keys()->mapWithKeys(fn ($s) => [$s => Dispute::where('status', $s)->count()])
                ->put('open', Dispute::open()->count()),
        ]);
    }

    public function show(Dispute $dispute)
    {
        $dispute->load([
            'order.items', 'order.deliveryCompany', 'item.product.primaryImage', 'client', 'seller', 'decider',
            'attachments', 'events.actor', 'shipments.company',
        ]);

        $replacement = $dispute->shipment(DisputeShipment::TYPE_REPLACEMENT);
        $return = $dispute->shipment(DisputeShipment::TYPE_RETURN);
        $pending = collect([$return, $replacement])->first(fn ($s) => $s && !$s->isPaid());

        // Partenaires proposés pour la course en attente de paiement.
        $quotes = null;
        if ($pending) {
            try {
                $quotes = $this->disputes->partnerQuotes($pending);
            } catch (\Throwable $e) {
                $quotes = ['available' => false, 'message' => $e->getMessage(), 'partners' => []];
            }
        }

        return view('admin.disputes.show', [
            'dispute' => $dispute,
            'replacement' => $replacement,
            'return' => $return,
            'pending' => $pending,
            'quotes' => $quotes,
        ]);
    }

    public function review(Request $request, Dispute $dispute)
    {
        return $this->run(fn () => $this->disputes->markInReview($dispute, $request->user()), 'Dossier pris en analyse.');
    }

    public function contactVendor(Request $request, Dispute $dispute)
    {
        $note = $request->validate(['note' => 'nullable|string|max:1000'])['note'] ?? null;

        return $this->run(fn () => $this->disputes->contactVendor($dispute, $request->user(), $note), 'Vendeur contacté (notification envoyée).');
    }

    public function decide(Request $request, Dispute $dispute)
    {
        $validated = $request->validate([
            'decision' => 'required|in:founded,unfounded',
            'note' => 'required|string|max:2000',
        ]);

        return $this->run(
            fn () => $this->disputes->decide($dispute, $request->user(), $validated['decision'], $validated['note']),
            $validated['decision'] === 'unfounded'
                ? 'Réclamation non fondée : fonds vendeur débloqués, litige clôturé.'
                : 'Réclamation fondée : le vendeur peut remplacer le produit ou organiser le retour.'
        );
    }

    /** Remplacement (import en gros : ASSO, revendeur, remplace lui-même). */
    public function replace(Request $request, Dispute $dispute)
    {
        return $this->run(fn () => $this->disputes->startReplacement($dispute, 'admin', $request->user()->id), 'Remplacement lancé.');
    }

    /** Cas B imposé par ASSO (vendeur sans remplacement, ou qui ne répond pas). */
    public function forceReturn(Request $request, Dispute $dispute)
    {
        $note = $request->validate(['note' => 'nullable|string|max:1000'])['note'] ?? null;

        return $this->run(fn () => $this->disputes->startReturn($dispute, 'admin', $request->user()->id, $note), 'Retour lancé : le vendeur doit payer la livraison retour (notification envoyée).');
    }

    /** Partenaire choisi par ASSO (import en gros, ou vendeur à aider). */
    public function choosePartner(Request $request, DisputeShipment $shipment)
    {
        $choice = $request->validate(['partner' => 'required|string']);
        $parts = json_decode($choice['partner'], true);
        if (!is_array($parts) || empty($parts['company_id'])) {
            return back()->with('error', 'Partenaire invalide.');
        }

        return $this->run(fn () => $this->disputes->choosePartner($shipment, $parts), 'Partenaire enregistré.');
    }

    public function payByAsso(Request $request, DisputeShipment $shipment)
    {
        return $this->run(fn () => $this->disputes->payByAsso($shipment, $request->user()), 'Course prise en charge par ASSO.');
    }

    /** Étape logistique (partenaire / ASSO) ; « Retour confirmé » rembourse le client. */
    public function step(Request $request, DisputeShipment $shipment)
    {
        $validated = $request->validate([
            'step' => 'required|string',
            'note' => 'nullable|string|max:1000',
            'carrier_tracking_number' => 'nullable|string|max:100',
            'proof' => 'nullable|image|max:8192',
        ]);

        return $this->run(fn () => $this->disputes->recordStep(
            $shipment, $validated['step'], 'admin', $request->user()->id,
            $validated['note'] ?? null, $validated['carrier_tracking_number'] ?? null, $request->file('proof'),
        ), $validated['step'] === 'confirmed' ? 'Retour confirmé : client remboursé, dossier clôturé.' : 'Étape enregistrée.');
    }

    public function evidence(Request $request, Dispute $dispute)
    {
        $validated = $request->validate([
            'note' => 'nullable|string|max:2000',
            'files' => 'nullable|array|max:6',
            'files.*' => 'image|max:8192',
        ]);

        return $this->run(fn () => $this->disputes->addEvidence($dispute, 'admin', $request->user(), $validated['note'] ?? null, $request->file('files', [])), 'Pièce ajoutée.');
    }

    public function note(Request $request, Dispute $dispute)
    {
        $note = $request->validate(['note' => 'required|string|max:2000'])['note'];

        return $this->run(fn () => $this->disputes->addInternalNote($dispute, $request->user(), $note), 'Note interne ajoutée.');
    }

    private function run(callable $action, string $success)
    {
        try {
            $action();
        } catch (\Exception $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', $success);
    }
}
