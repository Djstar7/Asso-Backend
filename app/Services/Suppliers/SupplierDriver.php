<?php

namespace App\Services\Suppliers;

use App\Models\Supplier;

/**
 * Contrat commun aux fournisseurs de dropshipping (CJdropshipping, Printful…).
 *
 * Chaque lot de l'intégration ajoute ses méthodes (catalogue, commandes, suivi) ;
 * le reste d'ASSO ne parle qu'à ce contrat, jamais directement à une API.
 */
interface SupplierDriver
{
    /** Code du fournisseur (Supplier::CJ, Supplier::PRINTFUL). */
    public function code(): string;

    public function supplier(): Supplier;

    /**
     * Vérifie la clé API (obtention d'un jeton) et relève le solde.
     *
     * @return array{ok: bool, message: string, balance_usd: ?float}
     */
    public function testConnection(): array;

    /** Relève le solde du compte fournisseur (USD) et l'enregistre. */
    public function refreshBalance(): ?float;
}
