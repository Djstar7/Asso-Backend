<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Encaissements initiés par ASSO, retrouvés par la référence prestataire que l'app
 * a stockée à l'initiation (`elgiopay:<id>`, id KPay…).
 *
 * Un compte marchand peut porter d'autres encaissements que ceux d'ASSO (autre
 * site branché sur les mêmes clés, paiements carte…) et le prestataire les notifie
 * aussi au webhook d'ASSO. Seuls ceux retrouvés ici concernent ASSO : frais,
 * Revenus et Wallet plateforme ne doivent jamais tenir compte des autres.
 */
final class GatewayPaymentOwnership
{
    /** Colonnes portant la référence prestataire d'un encaissement. */
    public const PAYIN_SOURCES = [
        ['orders', 'payment_reference'],
        ['orders', 'balance_payment_reference'],
        ['package_subscriptions', 'payment_reference'],
        ['diaspo_bookings', 'payment_reference'],
        ['dispute_shipments', 'payment_reference'],
        ['maritime_bookings', 'payment_reference'],
        ['real_estate_reservations', 'payment_reference'],
    ];

    /** L'encaissement de référence stockée $reference a-t-il été initié par ASSO ? */
    public static function isKnown(string $reference): bool
    {
        if ($reference === '') {
            return false;
        }

        foreach (self::PAYIN_SOURCES as [$table, $column]) {
            if (DB::table($table)->where($column, $reference)->exists()) {
                return true;
            }
        }

        // Recharges Wallet : la référence est dans metadata.provider_reference.
        return DB::table('wallet_transactions')
            ->where('type', 'credit')
            ->where('metadata->provider_reference', $reference)
            ->exists();
    }

    /** Restreint $query aux lignes dont $column est la référence d'un encaissement ASSO. */
    public static function whereKnown(Builder $query, string $column): Builder
    {
        return $query->where(function (Builder $q) use ($column) {
            foreach (self::PAYIN_SOURCES as [$table, $source]) {
                $q->orWhereExists(fn (Builder $s) => $s->selectRaw('1')->from($table)
                    ->whereColumn("{$table}.{$source}", $column));
            }
            $q->orWhereExists(fn (Builder $s) => $s->selectRaw('1')->from('wallet_transactions')
                ->where('wallet_transactions.type', 'credit')
                ->whereColumn('wallet_transactions.metadata->provider_reference', $column));
        });
    }
}
