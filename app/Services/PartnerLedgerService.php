<?php

namespace App\Services;

use App\Models\DelivererCompany;
use App\Models\PartnerLedgerEntry;
use Illuminate\Database\QueryException;

/**
 * Compte des partenaires (livraison et maritime) : le prix de leurs courses et
 * de leur fret reste chez ASSO, qui le leur vire ensuite sur leur IBAN depuis
 * l'admin (PartnerPayoutService) ou le paie hors application. Seule source de
 * ce qu'ASSO leur doit ; aucun Wallet n'est crédité.
 */
class PartnerLedgerService
{
    /**
     * Enregistre ce qu'ASSO doit au partenaire pour une course (idempotent :
     * une même source ne crée qu'un dû).
     */
    public static function earn(int $companyId, float $amountXaf, string $sourceType, int $sourceId, string $description): void
    {
        if ($amountXaf <= 0) {
            return;
        }

        $exists = PartnerLedgerEntry::where('type', PartnerLedgerEntry::TYPE_EARNING)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->exists();
        if ($exists) {
            return;
        }

        try {
            PartnerLedgerEntry::create([
                'deliverer_company_id' => $companyId,
                'type' => PartnerLedgerEntry::TYPE_EARNING,
                'amount' => round($amountXaf, 2),
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'description' => $description,
            ]);
        } catch (QueryException $e) {
            // Course déjà comptée par un règlement concurrent (index unique).
        }
    }

    /** Versement fait au partenaire hors application (espèces, Mobile Money…), saisi par l'admin. */
    public static function recordPayout(DelivererCompany $company, float $amountXaf, string $method, ?string $reference, string $paidOn, ?int $adminId, ?string $note = null): PartnerLedgerEntry
    {
        return PartnerLedgerEntry::create([
            'deliverer_company_id' => $company->id,
            'type' => PartnerLedgerEntry::TYPE_PAYOUT,
            'amount' => round($amountXaf, 2),
            'description' => $note ?: 'Versement au partenaire',
            'payment_method' => $method,
            'reference' => $reference,
            'paid_on' => $paidOn,
            'recorded_by' => $adminId,
        ]);
    }

    /** @return array{earned: float, paid: float, due: float} */
    public static function balance(int $companyId): array
    {
        $sums = PartnerLedgerEntry::where('deliverer_company_id', $companyId)
            ->where('status', '!=', PartnerLedgerEntry::STATUS_FAILED)
            ->selectRaw('type, sum(amount) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $earned = (float) ($sums[PartnerLedgerEntry::TYPE_EARNING] ?? 0);
        $paid = (float) ($sums[PartnerLedgerEntry::TYPE_PAYOUT] ?? 0);

        return ['earned' => $earned, 'paid' => $paid, 'due' => round($earned - $paid, 2)];
    }

    /** Solde dû par partenaire, en une requête : [company_id => due]. */
    public static function dueByCompany(): array
    {
        return PartnerLedgerEntry::where('status', '!=', PartnerLedgerEntry::STATUS_FAILED)
            ->selectRaw("deliverer_company_id, sum(case when type = 'earning' then amount else -amount end) as due")
            ->groupBy('deliverer_company_id')
            ->pluck('due', 'deliverer_company_id')
            ->map(fn ($v) => round((float) $v, 2))
            ->all();
    }
}
