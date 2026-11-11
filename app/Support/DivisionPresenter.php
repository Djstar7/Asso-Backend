<?php

namespace App\Support;

/** Libellés de l'espace « Ma rubrique » (vues admin.division_space.*). */
class DivisionPresenter
{
    /** @return array<string, \Closure> à extraire dans la vue */
    public static function helpers(): array
    {
        return [
            'orderStatus' => fn (?string $s) => [
                'pending' => 'En attente', 'confirmed' => 'Validée', 'processing' => 'En préparation', 'shipped' => 'Expédiée',
                'in_transit' => 'En transit', 'delivered' => 'Livrée', 'cancelled' => 'Annulée', 'refunded' => 'Remboursée',
            ][$s] ?? ucfirst((string) $s),
            'reservationStatus' => fn (?string $s) => [
                'pending_payment' => 'Paiement en attente', 'paid' => 'Payée', 'confirmed' => 'Confirmée', 'disputed' => 'En litige',
                'released' => 'Débloquée', 'refunded' => 'Remboursée', 'cancelled' => 'Annulée',
            ][$s] ?? ucfirst((string) $s),
            // Client réduit à son prénom et l'initiale de son nom : ni contact ni adresse.
            'clientName' => fn ($u) => $u ? trim($u->first_name.' '.mb_substr((string) $u->last_name, 0, 1).'.') : '—',
            'money' => fn ($v) => number_format((float) $v, 0, ',', ' ').' FCFA',
        ];
    }
}
