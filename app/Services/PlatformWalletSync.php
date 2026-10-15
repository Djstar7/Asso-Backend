<?php

namespace App\Services;

use App\Models\User;
use App\Models\WalletBalance;
use App\Models\WalletTransaction;
use App\Support\Translation\LocalizedText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Aligne le Wallet du compte plateforme (solde retirable par ASSO) sur la page
 * Revenus ASSO.
 *
 * Les commissions sur commandes, Diaspo, acomptes et courses de litige sont
 * créditées au fil de l'eau sur ce Wallet, mais d'autres lignes des Revenus n'y
 * passaient jamais : forfaits encaissés, frais Mobile Money, commissions des
 * commerciaux et des parrains, courses de litige payées par ASSO. Le retrait
 * affichait alors 0 alors que le net était positif.
 *
 * Chaque ligne de ces sources est reportée une fois sur le Wallet (crédit pour une
 * recette, débit pour une déduction), référencée par sa ligne d'origine ; une ligne
 * modifiée ou annulée depuis est corrigée par l'écart. Les lignes viennent des
 * requêtes mêmes de PlatformRevenueService : les deux pages ne peuvent pas diverger.
 * Idempotent.
 */
class PlatformWalletSync
{
    /** Sources des Revenus que seul ce report inscrit au Wallet => clé du libellé. */
    public const SOURCES = [
        'package_certification' => 'asso_package',
        'package_boost' => 'asso_package',
        'package_storage' => 'asso_package',
        'asso_shipments' => 'asso_dispute_course',
        'sales_commissions' => 'asso_sales_commission',
        'affiliate_commissions' => 'asso_affiliate_commission',
        'gateway_fees' => 'asso_gateway_fee',
    ];

    /** Préfixe du reference_type des écritures de report (suivi de la source). */
    public const REFERENCE_PREFIX = 'asso_revenue.';

    private const CURRENCY = 'XAF';

    public function __construct(private PlatformRevenueService $revenue) {}

    /** Report sans jamais bloquer l'appelant (page de retrait, retrait). */
    public function safeSync(): void
    {
        try {
            $this->sync();
        } catch (\Throwable $e) {
            Log::error('[PlatformWalletSync] Report des revenus sur le Wallet ASSO impossible', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Inscrit les écarts entre les Revenus et le Wallet plateforme.
     *
     * @return list<array{source: string, source_id: int, reference: string, type: string, amount: float}>
     */
    public function sync(bool $dryRun = false): array
    {
        $platform = CommissionService::platformAccount();
        if (!$platform) {
            return [];
        }

        return DB::transaction(function () use ($platform, $dryRun) {
            // Verrou du solde XAF : sérialise les reports concurrents et les retraits.
            $row = $dryRun ? null : $this->lockedBalance($platform);

            $posted = WalletTransaction::where('user_id', $platform->id)
                ->where('reference_type', 'like', self::REFERENCE_PREFIX . '%')
                ->selectRaw("reference_type, reference_id, sum(case when type = 'credit' then amount else -amount end) as net")
                ->groupBy('reference_type', 'reference_id')
                ->get()
                ->groupBy('reference_type')
                ->map(fn ($rows) => $rows->mapWithKeys(fn ($r) => [(int) $r->reference_id => (float) $r->net]));

            $entries = [];
            foreach (self::SOURCES as $source => $labelKey) {
                $sign = PlatformRevenueService::SOURCES[$source][0] === PlatformRevenueService::REVENUE ? 1 : -1;

                $expected = [];
                $references = [];
                foreach ($this->revenue->query($source, null, now())->get(['source_id', 'amount', 'reference']) as $line) {
                    $id = (int) $line->source_id;
                    $expected[$id] = ($expected[$id] ?? 0) + $sign * (float) $line->amount;
                    $references[$id] = (string) $line->reference;
                }

                $already = $posted->get(self::REFERENCE_PREFIX . $source, collect());
                foreach ($already->keys()->merge(array_keys($expected))->unique() as $id) {
                    $delta = round(($expected[$id] ?? 0) - ($already[$id] ?? 0), 2);
                    if (abs($delta) < 0.01) {
                        continue;
                    }

                    $reference = $references[$id] ?? (string) $id;
                    $entries[] = ['source' => $source, 'source_id' => $id, 'reference' => $reference, 'type' => $delta > 0 ? 'credit' : 'debit', 'amount' => abs($delta)];
                    if (!$dryRun) {
                        $this->post($platform, $row, $source, $id, $delta, WalletTransaction::label($labelKey, ['reference' => $reference]));
                    }
                }
            }

            if ($entries && !$dryRun) {
                Log::info('[PlatformWalletSync] Revenus reportés sur le Wallet ASSO', ['entries' => count($entries), 'balance' => (float) $row->balance]);
            }

            return $entries;
        });
    }

    /** Écriture signée ; un débit peut rendre le solde négatif (déduction réelle). */
    private function post(User $platform, WalletBalance $row, string $source, int $id, float $delta, LocalizedText $label): void
    {
        $before = (float) $row->balance;
        $row->balance = $before + $delta;
        $row->save();

        WalletTransaction::create([
            'user_id' => $platform->id,
            'type' => $delta > 0 ? 'credit' : 'debit',
            'amount' => abs($delta),
            'balance_before' => $before,
            'balance_after' => (float) $row->balance,
            'description' => $label,
            'reference_type' => self::REFERENCE_PREFIX . $source,
            'reference_id' => $id,
            'metadata' => ['asso_revenue_source' => $source, 'source_id' => $id],
            'status' => 'completed',
            'provider' => 'kpay',
        ]);
    }

    private function lockedBalance(User $platform): WalletBalance
    {
        WalletBalance::firstOrCreate(
            ['user_id' => $platform->id, 'currency' => self::CURRENCY],
            ['balance' => 0, 'locked_balance' => 0]
        );

        return WalletBalance::where('user_id', $platform->id)
            ->where('currency', self::CURRENCY)
            ->lockForUpdate()
            ->first();
    }
}
