<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\PlatformRevenueService;
use Illuminate\Http\Request;

/**
 * Revenus ASSO : chiffre d'affaires par service, pertes et charges, net ;
 * chaque ligne s'ouvre sur la liste de ses mouvements.
 */
class PlatformRevenueController extends Controller
{
    public function __construct(private PlatformRevenueService $revenue) {}

    public function index(Request $request)
    {
        $summary = $this->revenue->summary($request->query('period'));
        $groups = collect($summary['sources'])->groupBy('type')->map(fn ($rows) => $rows->groupBy('group'));

        return view('admin.revenue.index', [
            'summary' => $summary,
            'groups' => $groups,
            'platform' => $this->revenue->platformAccount(),
        ]);
    }

    public function show(Request $request, string $source)
    {
        abort_unless(isset(PlatformRevenueService::SOURCES[$source]), 404);
        [$type, $group, $label, $help] = PlatformRevenueService::SOURCES[$source];
        $periodKey = $request->query('period');

        $movements = $this->revenue->movements($source, $periodKey);
        $movements->getCollection()->transform(fn ($m) => $this->decorate($m));

        return view('admin.revenue.show', [
            'source' => $source,
            'type' => $type,
            'group' => $group,
            'label' => $label,
            'help' => $help,
            'movements' => $movements,
            'total' => $this->revenue->sourceTotal($source, $periodKey),
            'period' => $this->revenue->periodFor($periodKey),
        ]);
    }

    /** Export CSV (Excel) : une ligne par mouvement, toutes sources ou une seule. */
    public function export(Request $request)
    {
        $periodKey = $request->query('period');
        $only = $request->query('source');
        $sources = $only && isset(PlatformRevenueService::SOURCES[$only])
            ? [$only => PlatformRevenueService::SOURCES[$only]]
            : PlatformRevenueService::SOURCES;

        $filename = 'revenus-asso-' . ($periodKey ?: '30d') . '-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($sources, $periodKey) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Date', 'Type', 'Groupe', 'Source', 'Référence', 'Détail', 'Montant (FCFA)'], ';');
            foreach ($sources as $key => [$type, $group, $label]) {
                foreach ($this->revenue->allMovements($key, $periodKey) as $m) {
                    $amount = (float) $m->amount * ($type === PlatformRevenueService::LOSS ? -1 : 1);
                    fputcsv($out, [
                        \Carbon\Carbon::parse($m->occurred_at)->format('d/m/Y H:i'),
                        $type === PlatformRevenueService::LOSS ? 'Déduction' : 'Revenu',
                        $group,
                        $label,
                        $m->reference,
                        trim((string) $m->detail),
                        number_format($amount, 2, ',', ''),
                    ], ';');
                }
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Lien vers la fiche d'origine du mouvement. */
    private function decorate(object $m): object
    {
        $m->url = match ($m->subject_type) {
            'order' => $this->orderUrl((int) $m->subject_id),
            'dispute' => route('admin.disputes.show', $m->subject_id),
            'user' => $m->subject_id ? route('admin.users.show', $m->subject_id) : null,
            'sales_agent' => route('admin.sales.agents.show', $m->subject_id),
            'wallet_transaction' => route('admin.transactions.show', $m->subject_id),
            default => null,
        };

        return $m;
    }

    private function orderUrl(int $id): ?string
    {
        $order = Order::find($id, ['id', 'is_wholesale', 'payment_plan', 'has_local_products']);
        if (!$order) {
            return null;
        }

        return match (true) {
            $order->payment_plan === Order::PLAN_DEPOSIT => route('admin.deposit-orders.show', $order->id),
            (bool) $order->is_wholesale => route('admin.wholesale-orders.show', $order->id),
            default => route('admin.shipments.show', $order->id),
        };
    }
}
