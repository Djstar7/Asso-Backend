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
            ->with(['order.user', 'items.orderItem', 'approver'])->latest('id');
        $status = $request->input('status');
        if ($status === 'todo' || $status === null) {
            $query->whereIn('status', [...SupplierOrder::SUBMITTABLE, SupplierOrder::SUBMITTING, SupplierOrder::SUPPLIER_CANCELLED]);
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }
        if ($search = trim((string) $request->input('q'))) {
            $query->where(fn ($q) => $q->where('external_number', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('external_order_id', $search)->orWhere('tracking_number', $search));
        }

        return view('admin.suppliers.orders', [
            'cj' => $cj,
            'orders' => $query->paginate(25)->withQueryString(),
            'status' => $status ?? 'todo',
            'counts' => SupplierOrder::where('supplier_id', $cj->id)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all(),
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

    private function ensureCj(SupplierOrder $supplierOrder): void
    {
        abort_unless($supplierOrder->supplier?->code === Supplier::CJ, 404);
    }
}
