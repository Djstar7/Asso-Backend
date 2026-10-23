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

    public function settings(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate([
            'response_hours' => 'required|integer|min:1|max:720',
            'replacement_hours' => 'required|integer|min:1|max:720',
        ]);
        \App\Models\Setting::set('dispute_response_hours', $data['response_hours'], 'integer', 'disputes');
        \App\Models\Setting::set('dispute_replacement_hours', $data['replacement_hours'], 'integer', 'disputes');
        return back()->with('success', 'Délais enregistrés pour les prochaines échéances.');
    }

    public function show(Dispute $dispute)
    {
        $dispute->load([
            'order.items', 'order.deliveryCompany', 'item.product.primaryImage', 'client', 'seller', 'decider',
            'attachments', 'events.actor', 'shipments.company',
        ]);

        $replacement = $dispute->shipment(DisputeShipment::TYPE_REPLACEMENT);
        $return = $dispute->shipment(DisputeShipment::TYPE_RETURN);
        $collection = $dispute->shipment(DisputeShipment::TYPE_COLLECTION);
        $pending = collect([$collection, $return, $replacement])->first(fn ($s) => $s && !$s->isPaid());

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
            'collection' => $collection,
            'return' => $return,
            'pending' => $pending,
            'quotes' => $quotes,
            'bankTransfers' => $this->disputes->bankTransferOptions($dispute),
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
                : ($dispute->replacement_count >= Dispute::MAX_REPLACEMENTS
                    ? 'Réclamation fondée sur le remplacement : retour lancé, le vendeur doit payer la course.'
                    : 'Réclamation fondée : le vendeur peut remplacer le produit ou organiser le retour.')
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

        return $this->run(fn () => $this->disputes->startReturn($dispute, 'admin', $request->user()->id, $note), 'Retour et remboursement organisés selon le responsable du financement.');
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

    /**
     * Étape logistique saisie par ASSO (secours si le livreur n'a pas l'app) : la
     * remise au destinataire exige son code ; celle du retour rembourse le client.
     */
    public function step(Request $request, DisputeShipment $shipment)
    {
        $validated = $request->validate([
            'step' => 'required|string',
            'confirmation_code' => 'nullable|string|max:6',
            'note' => 'nullable|string|max:1000',
            'carrier_tracking_number' => 'nullable|string|max:100',
            'proof' => 'nullable|image|max:8192',
        ]);

        return $this->run(fn () => $this->disputes->recordStep(
            $shipment, $validated['step'], 'admin', $request->user()->id,
            $validated['note'] ?? null, $validated['carrier_tracking_number'] ?? null, $request->file('proof'),
            $validated['confirmation_code'] ?? null,
        ), 'Étape enregistrée.');
    }

    public function evidence(Request $request, Dispute $dispute)
    {
        $validated = $request->validate([
            'note' => 'required_without:files|nullable|string|max:2000',
            'files' => 'nullable|array|max:6',
            'files.*' => 'image|max:8192',
        ]);

        return $this->run(fn () => $this->disputes->addEvidence($dispute, $dispute->isAssoManaged() ? 'vendor' : 'admin', $request->user(), $validated['note'] ?? null, $request->file('files', [])), 'Pièce ajoutée.');
    }

    public function note(Request $request, Dispute $dispute)
    {
        $note = $request->validate(['note' => 'required|string|max:2000'])['note'];

        return $this->run(fn () => $this->disputes->addInternalNote($dispute, $request->user(), $note), 'Note interne ajoutée.');
    }

    /** Commande payée par carte : ASSO vire le remboursement (client) ou la part (vendeur) sur l'IBAN. */
    public function bankTransfer(Request $request, Dispute $dispute)
    {
        $beneficiary = $request->validate(['beneficiary' => 'required|in:client,vendor'])['beneficiary'];

        return $this->run(
            fn () => $this->disputes->bankTransfer($dispute, $beneficiary, $request->user()),
            $beneficiary === 'client' ? 'Virement IBAN du remboursement lancé vers le client.' : 'Virement IBAN lancé vers le vendeur.'
        );
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
