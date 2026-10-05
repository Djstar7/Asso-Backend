<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\DepositOrderService;
use App\Support\DeliveryPresenter;
use App\Support\DepositOrderStage;
use Illuminate\Http\Request;

/**
 * Commandes avec acompte : l'employé ASSO vérifie la marchandise avec le client à sa
 * présentation (livraison ou retrait), valide pour débloquer le solde, ou clôture la
 * commande en répartissant l'acompte au cas par cas.
 */
class DepositOrderController extends Controller
{
    public function __construct(private DepositOrderService $deposits)
    {
    }

    public function index(Request $request)
    {
        $stage = $request->input('stage', 'to_contact');
        $query = Order::with(['user', 'items.product.primaryImage', 'deliveryCompany', 'verifier'])->withCount('items');
        DepositOrderStage::apply($query, $stage);

        if ($request->filled('search')) {
            $like = '%' . mb_strtolower(addcslashes(trim($request->input('search')), '%_\\')) . '%';
            $query->where(fn ($q) => $q->whereRaw('LOWER(order_number) LIKE ?', [$like])
                ->orWhere('customer_phone', 'like', $like)
                ->orWhereHas('user', fn ($u) => $u->whereRaw("LOWER(first_name || ' ' || last_name) LIKE ?", [$like])
                    ->orWhere('phone', 'like', $like)));
        }

        return view('admin.deposit_orders.index', [
            'orders' => $query->latest()->paginate(20)->withQueryString(),
            'stage' => $stage,
            'counts' => collect(array_keys(DepositOrderStage::STAGES))->mapWithKeys(fn ($s) => [
                $s => DepositOrderStage::apply(Order::query(), $s)->count(),
            ]),
        ]);
    }

    public function show(Order $order)
    {
        abort_unless($order->isDepositOrder(), 404);
        $order->load(['user', 'items.product.primaryImage', 'items.seller', 'deliveryCompany', 'deliveryPerson', 'trackingEvents', 'verifier']);

        return view('admin.deposit_orders.show', [
            'order' => $order,
            'stage' => DepositOrderStage::of($order),
            'delivery' => DeliveryPresenter::forOrder($order),
            'collected' => $order->collectedAmounts(),
        ]);
    }

    public function contact(Request $request, Order $order)
    {
        $note = $request->validate(['note' => 'nullable|string|max:1000'])['note'] ?? null;

        return $this->run(fn () => $this->deposits->recordContact($order, $request->user(), $note), 'Contact avec le client enregistré.');
    }

    public function validateVerification(Request $request, Order $order)
    {
        $note = $request->validate(['note' => 'nullable|string|max:1000'])['note'] ?? null;

        return $this->run(fn () => $this->deposits->validateVerification($order, $request->user(), $note), 'Vérification validée : le client peut payer le solde (notification envoyée).');
    }

    public function reportIssue(Request $request, Order $order)
    {
        $note = $request->validate(['note' => 'required|string|max:1000'])['note'];

        return $this->run(fn () => $this->deposits->reportIssue($order, $request->user(), $note), 'Problème enregistré : le solde reste bloqué.');
    }

    public function close(Request $request, Order $order)
    {
        $validated = $request->validate([
            'refund_amount' => 'required|numeric|min:0',
            'vendor_amount' => 'required|numeric|min:0',
            'delivery_amount' => 'required|numeric|min:0',
            'note' => 'required|string|max:1000',
        ]);

        return $this->run(fn () => $this->deposits->closeWithDepositSplit(
            $order,
            $request->user(),
            (float) $validated['refund_amount'],
            (float) $validated['vendor_amount'],
            (float) $validated['delivery_amount'],
            $validated['note'],
        ), 'Commande clôturée, acompte réparti.');
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
