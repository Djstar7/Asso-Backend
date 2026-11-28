<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\SupplierOrder;
use App\Services\Suppliers\Cj\CjApiException;
use App\Services\Suppliers\SupplierManager;
use Illuminate\Http\Request;

/**
 * Commandes CJ dans le back-office (admin uniquement) : validation et envoi à
 * CJ, annulation avec remboursement du client, suivi, réglages de livraison.
 */
class SupplierOrderController extends Controller
{
    public function __construct(private SupplierManager $suppliers) {}

    public function index(Request $request)
    {
        $cj = Supplier::forCode(Supplier::CJ);
        $query = SupplierOrder::where('supplier_id', $cj->id)
            ->with(['order.user', 'items.orderItem', 'approver', 'receiver', 'disputes'])->latest('id');
        $status = $request->input('status');
        if ($status === 'todo' || $status === null) {
            $query->where(fn ($q) => $q->whereIn('status', [...SupplierOrder::SUBMITTABLE, SupplierOrder::SUBMITTING, SupplierOrder::SUPPLIER_CANCELLED, SupplierOrder::DELIVERED])
                ->orWhere(fn ($late) => $late->late()));
        } elseif ($status === 'late') {
            $query->late();
        } elseif ($status === 'transit') {
            $query->whereIn('status', SupplierOrder::RECEIVABLE);
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }
        if ($search = trim((string) $request->input('q'))) {
            $query->where(fn ($q) => $q->where('external_number', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('external_order_id', $search)->orWhere('tracking_number', $search));
        }

        $orders = $this->suppliers->driver(Supplier::CJ)->orders();

        return view('admin.suppliers.orders', [
            'cj' => $cj,
            'addressProblem' => $orders->shippingAddressProblem(),
            'shipTo' => $orders->shippingAddress(),
            'orders' => $query->paginate(25)->withQueryString(),
            'status' => $status ?? 'todo',
            'counts' => SupplierOrder::where('supplier_id', $cj->id)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all(),
            'lateCount' => SupplierOrder::where('supplier_id', $cj->id)->late()->count(),
            'transitCount' => SupplierOrder::where('supplier_id', $cj->id)->whereIn('status', SupplierOrder::RECEIVABLE)->count(),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'auto_submit' => 'nullable|boolean',
            'cost_tolerance_percent' => 'required|numeric|min:0|max:100',
            'ship_to_name' => 'nullable|string|max:50',
            'ship_to_phone' => 'nullable|string|max:20',
            'ship_to_address' => 'nullable|string|max:500',
            'ship_to_city' => 'required|string|max:50',
            'ship_to_province' => 'required|string|max:50',
            'ship_to_zip' => 'nullable|string|max:20',
            'ship_to_latitude' => 'nullable|numeric|between:-90,90',
            'ship_to_longitude' => 'nullable|numeric|between:-180,180',
        ]);
        $data['auto_submit'] = (bool) ($data['auto_submit'] ?? false);
        Supplier::forCode(Supplier::CJ)->putSettings($data);

        return back()->with('success', 'Réglages des commandes CJ enregistrés.');
    }

    /** Validation de l'admin : envoi et paiement chez CJ en arrière-plan. */
    public function submit(Request $request)
    {
        $data = $request->validate(['ids' => 'required|array|min:1|max:100', 'ids.*' => 'integer', 'force' => 'nullable|boolean'],
            ['ids.required' => 'Cochez au moins une commande.']);
        $cj = Supplier::forCode(Supplier::CJ);
        if (! $cj->is_active || ! $cj->isConnected()) {
            return back()->with('error', 'CJ n’est pas actif ou pas connecté (onglet Connexion).');
        }

        $orders = $this->suppliers->driver(Supplier::CJ)->orders();
        $queued = 0;
        SupplierOrder::where('supplier_id', $cj->id)->whereIn('id', $data['ids'])->get()
            ->each(function (SupplierOrder $supplierOrder) use ($orders, $request, $data, &$queued) {
                if ($orders->queueSubmission($supplierOrder, $request->user()->id, (bool) ($data['force'] ?? false))) {
                    $queued++;
                }
            });

        return back()->with('success', "{$queued} commande(s) envoyée(s) à CJ en arrière-plan".($cj->is_sandbox ? ' (bac à sable : paiement simulé)' : '').'. Rechargez la page dans une minute.');
    }

    public function cancel(Request $request, SupplierOrder $supplierOrder)
    {
        $this->ensureCj($supplierOrder);
        $data = $request->validate(['reason' => 'required|string|max:500']);

        try {
            $refunded = $this->suppliers->driver(Supplier::CJ)->orders()->cancel($supplierOrder, $data['reason'], $request->user()->id);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Commande annulée.'.($refunded > 0 ? ' '.number_format($refunded, 0, ',', ' ').' FCFA rendus au client.' : ''));
    }

    public function sync(SupplierOrder $supplierOrder)
    {
        $this->ensureCj($supplierOrder);
        try {
            $this->suppliers->driver(Supplier::CJ)->orders()->sync($supplierOrder);
        } catch (CjApiException $e) {
            return back()->with('error', 'Suivi CJ indisponible : '.$e->describe());
        }

        return back()->with('success', 'État relu chez CJ : '.($supplierOrder->fresh()->external_status ?? 'inconnu').'.');
    }

    public function sandboxAdvance(SupplierOrder $supplierOrder)
    {
        $this->ensureCj($supplierOrder);
        try {
            $step = $this->suppliers->driver(Supplier::CJ)->orders()->sandboxAdvance($supplierOrder);
        } catch (CjApiException $e) {
            return back()->with('error', 'Bac à sable CJ : '.$e->describe());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Commande de test passée à l’état « {$step} » chez CJ.");
    }

    /** Réception à l'entrepôt par numéro scanné ou saisi (suivi, n° CJ, ASSO-…, n° de commande). */
    public function receiveByCode(Request $request)
    {
        $code = trim((string) $request->validate(['code' => 'required|string|max:100'])['code']);
        $matches = $this->suppliers->driver(Supplier::CJ)->orders()->findByCode($code)
            ->filter(fn ($o) => in_array($o->status, SupplierOrder::RECEIVABLE, true));
        if ($matches->count() !== 1) {
            return back()->withInput()->with('error', $matches->isEmpty()
                ? "Aucun colis CJ attendu ne correspond à « {$code} »."
                : "Plusieurs colis correspondent à « {$code} » : réceptionnez-les depuis leur ligne.");
        }

        return $this->receiveOne($request, $matches->first());
    }

    /** Réception à l'entrepôt depuis la ligne de la commande. */
    public function receiveOne(Request $request, SupplierOrder $supplierOrder)
    {
        $this->ensureCj($supplierOrder);
        try {
            $message = $this->suppliers->driver(Supplier::CJ)->orders()->receive($supplierOrder, $request->user());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Commande #'.($supplierOrder->order?->order_number ?? $supplierOrder->external_number).' : '.$message);
    }

    public function disputeForm(SupplierOrder $supplierOrder)
    {
        $this->ensureCj($supplierOrder);
        $form = null;
        $error = null;
        try {
            $form = $this->suppliers->driver(Supplier::CJ)->disputes()->prepare($supplierOrder);
        } catch (CjApiException $e) {
            $error = 'CJ ne propose pas de litige pour cette commande : '.$e->describe();
        } catch (\InvalidArgumentException $e) {
            $error = $e->getMessage();
        }

        return view('admin.suppliers.dispute', [
            'cj' => Supplier::forCode(Supplier::CJ),
            'supplierOrder' => $supplierOrder->load(['order', 'items.orderItem', 'disputes.opener']),
            'form' => $form,
            'error' => $error,
        ]);
    }

    public function openDispute(Request $request, SupplierOrder $supplierOrder)
    {
        $this->ensureCj($supplierOrder);
        $data = $request->validate([
            'reason_id' => 'required|integer',
            'expect_type' => 'required|integer|in:1,2',
            'message' => 'required|string|min:10|max:500',
            'quantities' => 'required|array',
            'quantities.*' => 'nullable|integer|min:0|max:1000',
        ]);

        try {
            $dispute = $this->suppliers->driver(Supplier::CJ)->disputes()->open(
                $supplierOrder, (int) $data['reason_id'], (int) $data['expect_type'], $data['message'],
                array_map('intval', array_filter($data['quantities'], fn ($q) => $q !== null)), $request->user()
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        } catch (CjApiException $e) {
            return back()->withInput()->with('error', 'CJ a refusé le litige : '.$e->describe());
        }

        return redirect()->route('admin.suppliers.cj.orders.dispute', $supplierOrder)
            ->with('success', 'Litige envoyé à CJ'.($dispute->external_dispute_id ? ' (n° '.$dispute->external_dispute_id.')' : '').'. Sa réponse s’affichera ici.');
    }

    private function ensureCj(SupplierOrder $supplierOrder): void
    {
        abort_unless($supplierOrder->supplier?->code === Supplier::CJ, 404);
    }
}
