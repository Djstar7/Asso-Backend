<?php

namespace App\Services;

use App\Models\Package;
use App\Models\PackageSubscription;
use App\Models\Product;
use App\Models\ProductBoost;
use App\Models\ShopAnalyticsEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Asso Ads — sponsoring de produits, source unique pour l'app, le feed et l'admin.
 *
 * Modèle économique : le vendeur achète un forfait (`packages.type = 'boost'`)
 * pour UN produit. Le forfait fixe un quota d'impressions (`reach_users`) et une
 * durée (`duration_days`). La campagne s'arrête au premier des deux atteint.
 *
 * Une « impression » = une carte produit sponsorisée réellement servie dans une
 * réponse du feed / de la recherche. C'est ce qui est décompté du quota, donc ce
 * qui est facturé : le décompte a lieu à l'endroit exact où la carte est envoyée
 * (`consume()`), jamais sur une simple intention d'affichage.
 */
class ProductBoostService
{
    /** Nombre de produits sponsorisés injectés par page de feed. */
    public const DEFAULT_SLOTS_PER_PAGE = 2;

    /** Position (index 0-based) des slots sponsorisés dans la page. */
    public const DEFAULT_SLOT_POSITIONS = [0, 5];

    // ------------------------------------------------------------------
    // Réglages (admin → Asso Ads)
    // ------------------------------------------------------------------

    public function enabled(): bool
    {
        return (bool) \App\Models\Setting::get('ads_enabled', true);
    }

    public function slotsPerPage(): int
    {
        return max(0, min(10, (int) \App\Models\Setting::get('ads_slots_per_page', self::DEFAULT_SLOTS_PER_PAGE)));
    }

    /** Positions d'insertion, normalisées et bornées à la taille de page. */
    public function slotPositions(): array
    {
        $raw = \App\Models\Setting::get('ads_slot_positions', null);
        $positions = is_string($raw) && $raw !== ''
            ? array_map('intval', array_filter(explode(',', $raw), fn ($v) => trim($v) !== ''))
            : self::DEFAULT_SLOT_POSITIONS;

        $positions = array_values(array_unique(array_filter($positions, fn ($p) => $p >= 0)));
        sort($positions);

        return $positions ?: self::DEFAULT_SLOT_POSITIONS;
    }

    // ------------------------------------------------------------------
    // Achat / activation
    // ------------------------------------------------------------------

    /**
     * Vérifie qu'un produit peut être sponsorisé par cet utilisateur, AVANT paiement.
     * Lance une exception au message affichable sinon.
     */
    public function assertBoostable(User $user, Product $product): void
    {
        if ((int) $product->user_id !== (int) $user->id) {
            throw new \Exception(__('packages.boost_not_owner'));
        }
        if ($product->status !== 'active') {
            throw new \Exception(__('packages.boost_product_inactive'));
        }
        if (!$product->shop_id) {
            throw new \Exception(__('packages.boost_product_without_shop'));
        }
        if ($this->activeBoostFor($product)) {
            throw new \Exception(__('packages.boost_already_active'));
        }
    }

    /**
     * Campagne réellement en cours sur un produit, s'il y en a une.
     *
     * Le quota est pris en compte au même titre que l'échéance : une campagne
     * dont les vues sont épuisées mais pas encore basculée en `completed` ne
     * doit ni bloquer un nouvel achat, ni continuer à compter des clics.
     */
    public function activeBoostFor(Product $product): ?ProductBoost
    {
        return ProductBoost::where('product_id', $product->id)
            ->where('status', ProductBoost::ACTIVE)
            ->where('ends_at', '>', now())
            ->whereColumn('impressions_served', '<', 'impressions_quota')
            ->latest('starts_at')
            ->first();
    }

    /**
     * Ouvre la campagne une fois le forfait payé.
     *
     * Les conditions sont figées ici depuis le forfait : si l'admin change le
     * prix ou le quota demain, les campagnes déjà vendues gardent les leurs.
     */
    public function startCampaign(
        User $user,
        Product $product,
        Package $package,
        ?PackageSubscription $subscription = null
    ): ProductBoost {
        $quota = (int) $package->reach_users;
        if ($quota <= 0) {
            throw new \Exception(__('packages.boost_misconfigured'));
        }

        return ProductBoost::create([
            'product_id' => $product->id,
            // Figé à l'achat : la campagne reste identifiable si le produit
            // est renommé ou supprimé plus tard.
            'product_name' => $product->name,
            'shop_id' => $product->shop_id,
            'user_id' => $user->id,
            'package_id' => $package->id,
            'package_subscription_id' => $subscription?->id,
            'impressions_quota' => $quota,
            'duration_days' => (int) $package->duration_days,
            'amount_xaf' => (float) $package->price,
            'impressions_served' => 0,
            'clicks' => 0,
            'status' => ProductBoost::ACTIVE,
            'starts_at' => now(),
            'ends_at' => now()->addDays((int) $package->duration_days),
        ]);
    }

    // ------------------------------------------------------------------
    // Diffusion
    // ------------------------------------------------------------------

    /**
     * Tire au sort les campagnes à servir sur cette page de feed.
     *
     * Tirage aléatoire pondéré par le quota restant : une campagne qui a encore
     * beaucoup d'impressions à délivrer passe plus souvent, ce qui lisse la
     * consommation sur toute la durée au lieu d'épuiser la première vendue.
     *
     * @param  array<int>  $excludeProductIds  produits déjà présents dans la page (pas de doublon)
     * @return \Illuminate\Support\Collection<int, ProductBoost>
     */
    public function pickForFeed(int $limit, array $excludeProductIds = [], array $filters = [])
    {
        if (!$this->enabled() || $limit <= 0) {
            return collect();
        }

        $query = ProductBoost::servable()
            ->with(['product.images', 'product.primaryImage', 'product.category', 'product.subcategory', 'product.shop', 'product.user', 'product.variants'])
            ->whereHas('product', function ($q) use ($filters) {
                $q->where('status', 'active')
                    ->whereHas('shop', fn ($s) => $s->where('status', 'active'));

                // Une publicité doit rester pertinente : on respecte le filtrage
                // de la page où elle s'insère.
                if (!empty($filters['category_id'])) {
                    $q->where('category_id', $filters['category_id']);
                }
                if (!empty($filters['subcategory_id'])) {
                    $q->where('subcategory_id', $filters['subcategory_id']);
                }
                if (!empty($filters['type'])) {
                    $q->where('type', $filters['type']);
                }

                // Le feed local n'affiche pas de produits importés, et inversement.
                if (!empty($filters['origin_country'])) {
                    $q->where('origin_country', strtoupper($filters['origin_country']));
                } else {
                    $q->where(function ($sub) {
                        $sub->whereNull('origin_country')->orWhere('origin_country', '');
                    });
                }
            });

        if ($excludeProductIds) {
            $query->whereNotIn('product_id', $excludeProductIds);
        }

        // Pondération par quota restant, calculée en base pour ne pas charger
        // toutes les campagnes actives en mémoire. `random()` existe sur
        // PostgreSQL et SQLite ; MySQL l'écrit `rand()`.
        $random = DB::connection()->getDriverName() === 'mysql' ? 'rand()' : 'random()';

        return $query
            ->orderByRaw("{$random} * (impressions_quota - impressions_served) DESC")
            ->limit($limit)
            ->get();
    }

    /**
     * Décompte les impressions réellement servies et clôt les campagnes dont le
     * quota vient d'être épuisé.
     *
     * Appelé avec la liste des campagnes envoyées dans la réponse. Le décompte
     * est atomique (increment SQL) : deux requêtes simultanées ne peuvent pas
     * servir la même impression deux fois.
     *
     * @param  iterable<ProductBoost>  $boosts
     */
    public function consume(iterable $boosts): void
    {
        $ids = collect($boosts)->pluck('id')->filter()->all();
        if (!$ids) {
            return;
        }

        try {
            DB::transaction(function () use ($ids) {
                $today = now()->toDateString();

                // Campagnes réellement décomptables : les autres (quota atteint
                // entre-temps par une requête concurrente) ne doivent ni être
                // facturées, ni apparaître dans la courbe du vendeur.
                $served = ProductBoost::whereIn('id', $ids)
                    ->where('status', ProductBoost::ACTIVE)
                    ->whereColumn('impressions_served', '<', 'impressions_quota')
                    ->lockForUpdate()
                    ->pluck('id')
                    ->all();

                if (!$served) {
                    return;
                }

                ProductBoost::whereIn('id', $served)->increment('impressions_served');

                // Une ligne par campagne effectivement servie : la somme des
                // stats journalières reste égale à `impressions_served`.
                foreach ($served as $id) {
                    $this->bumpDailyStat($id, $today, impressions: 1);
                }

                // Quota atteint : la campagne a délivré ce qui a été acheté.
                $finished = ProductBoost::whereIn('id', $served)
                    ->where('status', ProductBoost::ACTIVE)
                    ->whereColumn('impressions_served', '>=', 'impressions_quota')
                    ->pluck('id')
                    ->all();

                if ($finished) {
                    ProductBoost::whereIn('id', $finished)
                        ->update(['status' => ProductBoost::COMPLETED, 'completed_at' => now(), 'updated_at' => now()]);

                    // Notifié après le commit : le vendeur ne doit pas être
                    // prévenu d'une fin que la transaction annulerait.
                    DB::afterCommit(fn () => $this->notifyFinished($finished));
                }
            });
        } catch (\Throwable $e) {
            // Une impression perdue ne doit jamais faire échouer le feed.
            Log::warning('[AssoAds] Décompte d\'impressions échoué: ' . $e->getMessage());
        }
    }

    /**
     * Enregistre l'ouverture d'une fiche produit venue d'une carte sponsorisée.
     * Le clic ne consomme pas de quota : seule l'impression est facturée.
     */
    public function recordClick(Product $product): void
    {
        try {
            $boost = $this->activeBoostFor($product);
            if (!$boost) {
                return;
            }

            DB::transaction(function () use ($boost) {
                ProductBoost::where('id', $boost->id)->increment('clicks');
                $this->bumpDailyStat($boost->id, now()->toDateString(), clicks: 1);
            });
        } catch (\Throwable $e) {
            Log::warning('[AssoAds] Décompte de clic échoué: ' . $e->getMessage());
        }
    }

    /**
     * Incrémente le compteur du jour pour une campagne, en créant la ligne au
     * besoin. Écrit en deux temps (UPDATE puis INSERT) plutôt qu'en `upsert`
     * pour rester portable entre PostgreSQL, SQLite et MySQL ; l'unicité
     * (product_boost_id, date) garantit qu'aucun doublon ne s'installe.
     */
    private function bumpDailyStat(int $boostId, string $date, int $impressions = 0, int $clicks = 0): void
    {
        $updated = DB::table('product_boost_daily_stats')
            ->where('product_boost_id', $boostId)
            ->where('date', $date)
            ->update([
                'impressions' => DB::raw('impressions + ' . (int) $impressions),
                'clicks' => DB::raw('clicks + ' . (int) $clicks),
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            DB::table('product_boost_daily_stats')->insert([
                'product_boost_id' => $boostId,
                'date' => $date,
                'impressions' => $impressions,
                'clicks' => $clicks,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Cycle de vie
    // ------------------------------------------------------------------

    /** Clôt les campagnes arrivées à échéance. Renvoie le nombre traité. */
    public function expireOverdue(): int
    {
        $ids = ProductBoost::where('status', ProductBoost::ACTIVE)
            ->where('ends_at', '<=', now())
            ->pluck('id')
            ->all();

        if (!$ids) {
            return 0;
        }

        ProductBoost::whereIn('id', $ids)
            ->update(['status' => ProductBoost::EXPIRED, 'completed_at' => now(), 'updated_at' => now()]);

        $this->notifyFinished($ids);

        return count($ids);
    }

    /**
     * Prévient les vendeurs dont la campagne vient de se terminer.
     *
     * Le message distingue les deux fins possibles : audience atteinte (le
     * forfait a tenu sa promesse) ou échéance avec des vues non délivrées —
     * ce dernier cas mérite d'être dit, c'est de l'argent que le vendeur n'a
     * pas consommé.
     *
     * @param  array<int>  $boostIds
     */
    public function notifyFinished(array $boostIds): void
    {
        if (!$boostIds) {
            return;
        }

        try {
            $fcm = app(\App\Services\FcmService::class);

            ProductBoost::whereIn('id', $boostIds)
                ->with(['product', 'user'])
                ->get()
                ->each(function (ProductBoost $boost) use ($fcm) {
                    if (!$boost->user) {
                        return;
                    }

                    $name = $boost->productLabel();
                    $served = number_format($boost->impressions_served, 0, ',', ' ');

                    $body = $boost->user->translate(
                        $boost->status === ProductBoost::COMPLETED
                            ? 'notifications.boost_finished.body_completed'
                            : 'notifications.boost_finished.body_ended',
                        ['product' => $name, 'served' => $served]
                    );

                    $fcm->sendToUser(
                        $boost->user,
                        $boost->user->translate('notifications.boost_finished.title'),
                        $body,
                        [
                            'type' => 'boost_finished',
                            'product_boost_id' => (string) $boost->id,
                            'product_id' => (string) ($boost->product_id ?? ''),
                        ]
                    );
                });
        } catch (\Throwable $e) {
            Log::warning('[AssoAds] Notification de fin de campagne échouée: ' . $e->getMessage());
        }
    }

    /**
     * Arrêt anticipé (pas de remboursement : la portée délivrée est due).
     *
     * [$notify] prévient le vendeur — utile quand c'est l'administration qui
     * coupe : son produit sort de la diffusion, il doit l'apprendre autrement
     * qu'en le constatant.
     */
    public function cancel(ProductBoost $boost, bool $notify = false): void
    {
        if ($boost->status !== ProductBoost::ACTIVE) {
            return;
        }

        $boost->update(['status' => ProductBoost::CANCELLED, 'completed_at' => now()]);

        if (!$notify) {
            return;
        }

        try {
            $boost->loadMissing('user');
            if ($boost->user) {
                app(\App\Services\FcmService::class)->sendToUser(
                    $boost->user,
                    $boost->user->translate('notifications.boost_cancelled.title'),
                    $boost->user->translate('notifications.boost_cancelled.body', ['product' => $boost->productLabel()]),
                    ['type' => 'boost_cancelled', 'product_boost_id' => (string) $boost->id]
                );
            }
        } catch (\Throwable $e) {
            Log::warning('[AssoAds] Notification d\'annulation échouée: ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Suivi vendeur
    // ------------------------------------------------------------------

    /**
     * Tableau de bord d'une campagne : ce que le vendeur a acheté, ce qui a été
     * délivré, et ce que ça lui a rapporté en attention.
     *
     * « Personnes touchées » = impressions servies (portée payée).
     * « Ont vu le produit »  = ouvertures de la fiche sur la période (product_view).
     * « Ont interagi »       = prises de contact sur la période (contact).
     *
     * Les deux derniers viennent de shop_analytics_events, dédoublonnés par
     * visiteur : ce sont des personnes distinctes, pas des événements bruts.
     */
    public function campaignSummary(ProductBoost $boost): array
    {
        $from = $boost->starts_at;
        $to = $boost->completed_at ?? min(now(), $boost->ends_at);

        $events = ShopAnalyticsEvent::where('product_id', $boost->product_id)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('event_type, COUNT(*) AS total, COUNT(DISTINCT visitor_hash) AS people')
            ->groupBy('event_type')
            ->get()
            ->keyBy('event_type');

        $views = (int) ($events[ShopAnalyticsEvent::PRODUCT_VIEW]->people ?? 0);
        $contacts = (int) ($events[ShopAnalyticsEvent::CONTACT]->people ?? 0);

        return [
            'id' => $boost->id,
            'product' => [
                'id' => $boost->product_id,
                'name' => $boost->productLabel(),
                'image' => $boost->product?->primaryImage?->image_path
                    ? media_url($boost->product->primaryImage->image_path)
                    : null,
            ],
            'package_name' => $boost->package?->name,
            'status' => $boost->status,
            'status_label' => $boost->statusLabel(),
            'is_running' => $boost->isServable(),
            'amount_xaf' => (float) $boost->amount_xaf,
            'starts_at' => $boost->starts_at->toIso8601String(),
            'ends_at' => $boost->ends_at->toIso8601String(),
            'remaining_days' => $boost->remainingDays(),

            // Portée achetée / délivrée
            'impressions_quota' => $boost->impressions_quota,
            'impressions_served' => $boost->impressions_served,
            'impressions_remaining' => $boost->remainingImpressions(),
            'progress_percent' => $boost->progressPercent(),

            // Ce que la portée a produit
            'reached' => $boost->impressions_served,
            'viewers' => $views,
            'interactions' => $contacts,
            'clicks' => $boost->clicks,
            'click_through_rate' => $boost->clickThroughRate(),
            'view_rate' => $boost->impressions_served > 0
                ? round($views / $boost->impressions_served * 100, 2)
                : 0.0,

            'series' => $this->campaignSeries($boost),
        ];
    }

    /** Courbe jour par jour, trous comblés à zéro pour un graphe continu. */
    private function campaignSeries(ProductBoost $boost): array
    {
        $rows = $boost->dailyStats()
            ->orderBy('date')
            ->get()
            ->keyBy(fn ($row) => $row->date->toDateString());

        $series = [];
        $cursor = $boost->starts_at->copy()->startOfDay();
        $last = ($boost->completed_at ?? min(now(), $boost->ends_at))->copy()->startOfDay();

        while ($cursor->lte($last)) {
            $key = $cursor->toDateString();
            $series[] = [
                'date' => $key,
                'impressions' => (int) ($rows[$key]->impressions ?? 0),
                'clicks' => (int) ($rows[$key]->clicks ?? 0),
            ];
            $cursor->addDay();
        }

        return $series;
    }

    /** Historique des campagnes d'un vendeur, la plus récente d'abord. */
    public function historyFor(User $user, int $limit = 20)
    {
        return ProductBoost::where('user_id', $user->id)
            ->with(['product.primaryImage', 'package'])
            ->latest('starts_at')
            ->limit($limit)
            ->get()
            ->map(fn (ProductBoost $boost) => $this->campaignSummary($boost));
    }
}
