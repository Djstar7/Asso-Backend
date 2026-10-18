<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Revenus ASSO — source unique du chiffre d'affaires de la plateforme et de ce
 * qui vient en déduction, calculés depuis les tables métier (montants en XAF).
 *
 * Chaque source produit des « mouvements » normalisés : date, montant, libellé,
 * référence. Une recette n'est comptée qu'une fois acquise (commande réglée,
 * forfait payé, code Diaspo validé) ; une perte à la date où elle est subie.
 */
class PlatformRevenueService
{
    public const REVENUE = 'revenue';
    public const LOSS = 'loss';

    /** Sources dans l'ordre d'affichage : [type, groupe, libellé, explication]. */
    public const SOURCES = [
        'package_certification' => [self::REVENUE, 'Forfaits', 'Certification', 'Forfaits « boutique certifiée » payés par les vendeurs.'],
        'package_boost' => [self::REVENUE, 'Forfaits', 'Visibilité (Asso Ads)', 'Forfaits de sponsoring de produits payés par les vendeurs.'],
        'package_storage' => [self::REVENUE, 'Forfaits', 'Stockage', 'Forfaits d\'espace de stockage payés par les vendeurs.'],
        'sale_commission' => [self::REVENUE, 'Commissions', 'Commission sur les produits', 'Majoration ASSO figée sur chaque commande réglée.'],
        'delivery_commission' => [self::REVENUE, 'Commissions', 'Commission sur les livraisons', 'Part ASSO de chaque course, y compris quand la livraison est offerte au client, et des courses de litige payées par le vendeur.'],
        'diaspo_commission' => [self::REVENUE, 'Commissions', 'Commission Diaspo', 'Part ASSO des réservations de kilos, acquise à la validation du code.'],
        'maritime_commission' => [self::REVENUE, 'Commissions', 'Commission Maritime', 'Part ASSO du fret maritime, acquise à la remise du colis au destinataire.'],
        'parcel_insurance' => [self::REVENUE, 'Commissions', 'Assurance des colis', 'Suppléments d\'assurance des colis Diaspo (avion et maritime), acquis à la validation du code.'],
        'deposit_kept' => [self::REVENUE, 'Commissions', 'Acomptes conservés', 'Part d\'acompte revenant à ASSO lors de la clôture d\'une commande avec acompte.'],
        'platform_products' => [self::REVENUE, 'Produits ASSO', 'Ventes des produits ASSO', 'Part vendeur des articles vendus par les boutiques du compte plateforme (la commission est comptée à part).'],

        'dispute_refunds' => [self::LOSS, 'Retours', 'Retours remboursés', 'Commission rendue au client sur un retour (prix complet pour un produit ASSO).'],
        'asso_shipments' => [self::LOSS, 'Retours', 'Courses de litige payées par ASSO', 'Courses de retour ou de remplacement prises en charge par ASSO (commandes en gros).'],
        'sales_commissions' => [self::LOSS, 'Commissions versées', 'Commissions des commerciaux', 'Dues dès l\'activation du forfait vendu avec un code commercial.'],
        'affiliate_commissions' => [self::LOSS, 'Commissions versées', 'Commissions d\'affiliation', 'Commissions approuvées ou payées aux parrains.'],
        'gateway_fees' => [self::LOSS, 'Frais de paiement', 'Frais Mobile Money (KPay, ElgioPay)', 'Frais réellement prélevés sur les encaissements : feeAmount chez KPay, amount.fees chez ElgioPay.'],
    ];

    private ?int $platformId = null;

    public function __construct(private ShopStatisticsService $statistics) {}

    public function resolvePeriod(?string $key): array
    {
        return $this->statistics->resolvePeriod($key, $this->firstActivity());
    }

    /** Période affichable (sélecteur), sans calcul. */
    public function periodFor(?string $key): array
    {
        return $this->periodPayload($this->resolvePeriod($key));
    }

    /** Tableau de bord : totaux par source, période précédente et série temporelle. */
    public function summary(?string $periodKey): array
    {
        $period = $this->resolvePeriod($periodKey);
        $current = $this->totals($period['from'], $period['to']);
        $previous = $period['previous_from']
            ? $this->totals($period['previous_from'], $period['previous_to'])
            : null;

        $sources = [];
        foreach (self::SOURCES as $key => [$type, $group, $label, $help]) {
            $sources[$key] = [
                'key' => $key,
                'type' => $type,
                'group' => $group,
                'label' => $label,
                'help' => $help,
                'amount' => $current['sources'][$key]['amount'],
                'count' => $current['sources'][$key]['count'],
                'previous' => $previous['sources'][$key]['amount'] ?? null,
            ];
        }

        return [
            'period' => $this->periodPayload($period),
            'sources' => $sources,
            'totals' => [
                'revenue' => $current['revenue'],
                'losses' => $current['losses'],
                'net' => round($current['revenue'] - $current['losses'], 2),
            ],
            'previous' => $previous ? [
                'revenue' => $previous['revenue'],
                'losses' => $previous['losses'],
                'net' => round($previous['revenue'] - $previous['losses'], 2),
            ] : null,
            'series' => $this->series($current['rows'], $period),
        ];
    }

    /** Mouvements d'une source sur la période, du plus récent au plus ancien. */
    public function movements(string $source, ?string $periodKey, int $perPage = 50)
    {
        $period = $this->resolvePeriod($periodKey);

        return $this->query($source, $period['from'], $period['to'])
            ->orderByDesc('occurred_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** Tous les mouvements d'une source (export). */
    public function allMovements(string $source, ?string $periodKey)
    {
        $period = $this->resolvePeriod($periodKey);

        return $this->query($source, $period['from'], $period['to'])->orderByDesc('occurred_at')->get();
    }

    public function sourceTotal(string $source, ?string $periodKey): array
    {
        $period = $this->resolvePeriod($periodKey);
        $rows = $this->query($source, $period['from'], $period['to'])->get(['amount']);

        return ['amount' => round((float) $rows->sum('amount'), 2), 'count' => $rows->count()];
    }

    public function platformAccount(): ?User
    {
        return CommissionService::platformAccount();
    }

    // ------------------------------------------------------------------
    // Sources
    // ------------------------------------------------------------------

    /**
     * Requête normalisée d'une source : colonnes occurred_at, amount, reference,
     * subject_type, subject_id, detail.
     */
    public function query(string $source, ?Carbon $from, Carbon $to): Builder
    {
        $q = match ($source) {
            'package_certification' => $this->packages('certification'),
            'package_boost' => $this->packages('boost'),
            'package_storage' => $this->packages('storage'),
            'sale_commission' => $this->settledOrders('o.sale_commission'),
            'delivery_commission' => $this->deliveryCommissions(),
            'diaspo_commission' => $this->platformLedger('asso_commission_diaspo', 'diaspo'),
            'maritime_commission' => $this->platformLedger('asso_commission_maritime', 'maritime'),
            'parcel_insurance' => $this->platformLedger('asso_parcel_insurance', 'diaspo'),
            'deposit_kept' => $this->platformLedger('deposit_asso_share', 'order'),
            'platform_products' => $this->platformProducts(),
            'dispute_refunds' => $this->disputeRefunds(),
            'asso_shipments' => $this->assoShipments(),
            'sales_commissions' => $this->salesCommissions(),
            'affiliate_commissions' => $this->affiliateCommissions(),
            'gateway_fees' => $this->gatewayFees(),
            default => throw new \InvalidArgumentException("Source inconnue : {$source}"),
        };

        // Sous-requête : les filtres de période et le tri portent sur les alias.
        return DB::query()->fromSub($q, 'm')
            ->when($from, fn ($b) => $b->where('occurred_at', '>=', $from))
            ->where('occurred_at', '<=', $to);
    }

    private function packages(string $type): Builder
    {
        return DB::table('package_subscriptions as s')
            ->join('packages as p', 'p.id', '=', 's.package_id')
            ->leftJoin('users as u', 'u.id', '=', 's.user_id')
            ->where('s.status', 'paid')
            ->where('p.type', $type)
            ->whereNotNull('s.paid_at')
            ->selectRaw("s.paid_at as occurred_at, s.amount_xaf as amount, p.name as reference, s.id as source_id,
                'user' as subject_type, s.user_id as subject_id,
                " . $this->concat(["coalesce(u.first_name, '')", "' '", "coalesce(u.last_name, '')", "' · '", 's.payment_method']) . ' as detail');
    }

    /** Commandes réglées et non clôturées par répartition d'acompte (comptée à part). */
    private function settledOrders(string $amountColumn): Builder
    {
        return DB::table('orders as o')
            ->whereNotNull('o.settled_at')
            ->where('o.status', '!=', 'cancelled')
            ->where($amountColumn, '>', 0)
            ->selectRaw("o.settled_at as occurred_at, {$amountColumn} as amount, o.order_number as reference,
                'order' as subject_type, o.id as subject_id,
                " . $this->concat(["'Commande '", 'o.order_number', "case when o.free_delivery then ' · livraison offerte' else '' end"]) . ' as detail');
    }

    private function deliveryCommissions(): Builder
    {
        $disputeCourses = DB::table('dispute_shipments as ds')
            ->join('disputes as d', 'd.id', '=', 'ds.dispute_id')
            ->where('ds.payer', 'vendor')
            ->where('ds.payment_status', 'paid')
            ->where('ds.asso_commission', '>', 0)
            ->selectRaw("ds.paid_at as occurred_at, ds.asso_commission as amount, d.number as reference,
                'dispute' as subject_type, d.id as subject_id,
                " . $this->concat(["'Course de litige '", 'd.number']) . ' as detail');

        return $this->settledOrders('o.delivery_commission')->unionAll($disputeCourses);
    }

    /** Recettes connues seulement du Wallet plateforme (libellé wallet.transactions.<code>). */
    private function platformLedger(string $code, string $subjectType): Builder
    {
        return DB::table('wallet_transactions as w')
            ->where('w.user_id', $this->platformId())
            ->where('w.type', 'credit')
            ->whereNull('w.deleted_at')
            ->where('w.metadata->label->key', "wallet.transactions.{$code}")
            ->selectRaw("w.created_at as occurred_at, w.amount as amount, cast(w.id as varchar(20)) as reference,
                'wallet_transaction' as subject_type, w.id as subject_id, w.description as detail");
    }

    private function platformProducts(): Builder
    {
        return DB::table('order_items as i')
            ->join('orders as o', 'o.id', '=', 'i.order_id')
            ->leftJoin('products as p', 'p.id', '=', 'i.product_id')
            ->where('i.seller_id', $this->platformId())
            ->whereNotNull('o.settled_at')
            ->where('o.status', '!=', 'cancelled')
            ->selectRaw("o.settled_at as occurred_at, coalesce(i.seller_total_price, i.total_price) as amount, o.order_number as reference,
                'order' as subject_type, o.id as subject_id,
                " . $this->concat(["coalesce(p.name, 'Produit supprimé')", "' × '", 'cast(i.quantity as varchar(10))', "' · CA client '", 'cast(i.total_price as varchar(20))']) . ' as detail');
    }

    private function disputeRefunds(): Builder
    {
        $platform = (int) $this->platformId();

        return DB::table('disputes as d')
            ->join('order_items as i', 'i.id', '=', 'd.order_item_id')
            ->whereNotNull('d.refunded_at')
            ->selectRaw("d.refunded_at as occurred_at,
                case when d.seller_id = {$platform} then i.total_price else i.total_price - d.held_amount end as amount,
                d.number as reference, 'dispute' as subject_type, d.id as subject_id,
                " . $this->concat(["'Retour '", 'd.number', "case when d.seller_id = {$platform} then ' · produit ASSO, prix complet' else ' · commission rendue' end"]) . ' as detail')
            ->whereRaw("(case when d.seller_id = {$platform} then i.total_price else i.total_price - d.held_amount end) > 0");
    }

    private function assoShipments(): Builder
    {
        return DB::table('dispute_shipments as ds')
            ->join('disputes as d', 'd.id', '=', 'ds.dispute_id')
            ->where('ds.payer', 'asso')
            ->where('ds.payment_status', 'paid')
            ->where('ds.carrier_amount', '>', 0)
            ->selectRaw("ds.paid_at as occurred_at, ds.carrier_amount as amount, d.number as reference, ds.id as source_id,
                'dispute' as subject_type, d.id as subject_id,
                " . $this->concat(["'Course ASSO · litige '", 'd.number']) . ' as detail');
    }

    private function salesCommissions(): Builder
    {
        return DB::table('sales_commissions as c')
            ->leftJoin('sales_agents as a', 'a.id', '=', 'c.sales_agent_id')
            ->where('c.status', '!=', 'cancelled')
            ->selectRaw("c.sold_at as occurred_at, c.commission_amount as amount, c.sales_code as reference, c.id as source_id,
                'sales_agent' as subject_type, c.sales_agent_id as subject_id,
                " . $this->concat(["coalesce(a.first_name, '')", "' '", "coalesce(a.last_name, '')", "' · '", 'c.package_name', "' · '", 'c.status']) . ' as detail');
    }

    private function affiliateCommissions(): Builder
    {
        return DB::table('affiliate_commissions as c')
            ->whereIn('c.status', ['approved', 'paid'])
            ->selectRaw("c.created_at as occurred_at, c.amount as amount, cast(c.id as varchar(20)) as reference, c.id as source_id,
                'user' as subject_type, c.affiliate_id as subject_id,
                " . $this->concat(["'Niveau '", 'cast(c.level as varchar(4))', "' · '", 'c.status']) . ' as detail');
    }

    private function gatewayFees(): Builder
    {
        return DB::table('payment_gateway_fees as f')
            ->where('f.direction', 'payin')
            ->where('f.fee_amount', '>', 0)
            ->selectRaw("f.completed_at as occurred_at,
                coalesce(f.fee_amount_xaf, case when f.currency = 'XAF' then f.fee_amount end, 0) as amount,
                f.gateway_id as reference, f.id as source_id, 'gateway_fee' as subject_type, f.id as subject_id,
                " . $this->concat(["case when f.gateway = 'elgiopay' then 'ElgioPay' else 'KPay' end", "' · '", "coalesce(f.external_id, '')", "' · '", 'cast(f.amount as varchar(20))', "' '", 'f.currency', "' encaissés'"]) . ' as detail');
    }

    // ------------------------------------------------------------------
    // Agrégats
    // ------------------------------------------------------------------

    private function totals(?Carbon $from, Carbon $to): array
    {
        $sources = [];
        $rows = [];
        $revenue = $losses = 0.0;

        foreach (self::SOURCES as $key => [$type]) {
            $items = $this->query($key, $from, $to)->get(['occurred_at', 'amount']);
            $amount = round((float) $items->sum('amount'), 2);
            $sources[$key] = ['amount' => $amount, 'count' => $items->count()];
            $type === self::REVENUE ? $revenue += $amount : $losses += $amount;
            foreach ($items as $item) {
                $rows[] = [$type, Carbon::parse($item->occurred_at), (float) $item->amount];
            }
        }

        return ['sources' => $sources, 'revenue' => round($revenue, 2), 'losses' => round($losses, 2), 'rows' => $rows];
    }

    private function series(array $rows, array $period): array
    {
        $monthly = $period['granularity'] === 'month';
        $format = $monthly ? 'Y-m' : 'Y-m-d';
        $from = $period['from'] ?? collect($rows)->min(fn ($r) => $r[1]) ?? now();
        $cursor = Carbon::parse($from)->startOfDay();
        if ($monthly) {
            $cursor->startOfMonth();
        }

        $buckets = [];
        while ($cursor <= $period['to']) {
            $buckets[$cursor->format($format)] = ['date' => $cursor->format($monthly ? 'Y-m-01' : 'Y-m-d'), 'revenue' => 0.0, 'losses' => 0.0];
            $monthly ? $cursor->addMonth() : $cursor->addDay();
        }

        foreach ($rows as [$type, $date, $amount]) {
            $k = $date->format($format);
            if (isset($buckets[$k])) {
                $buckets[$k][$type === self::REVENUE ? 'revenue' : 'losses'] += $amount;
            }
        }

        return array_values(array_map(fn ($b) => $b + ['net' => round($b['revenue'] - $b['losses'], 2)], $buckets));
    }

    private function periodPayload(array $period): array
    {
        return [
            'key' => $period['key'],
            'label' => $period['label'],
            'from' => $period['from'],
            'to' => $period['to'],
            'granularity' => $period['granularity'],
            'available' => collect(ShopStatisticsService::PERIODS)
                ->map(fn ($p, $k) => ['key' => $k, 'label' => __("shops.statistics.periods.{$k}")])
                ->values()->all(),
        ];
    }

    /** Première activité monétaire connue (période « Depuis le début »). */
    private function firstActivity(): ?Carbon
    {
        $dates = array_filter([
            DB::table('package_subscriptions')->where('status', 'paid')->min('paid_at'),
            DB::table('orders')->whereNotNull('settled_at')->min('settled_at'),
            DB::table('wallet_transactions')->where('user_id', $this->platformId())->min('created_at'),
        ]);

        return $dates ? Carbon::parse(min($dates)) : null;
    }

    private function platformId(): int
    {
        return $this->platformId ??= (int) (CommissionService::platformAccount()?->id ?? 0);
    }

    /** Concaténation portable (PostgreSQL et SQLite acceptent ||). */
    private function concat(array $parts): string
    {
        return '(' . implode(' || ', $parts) . ')';
    }
}
