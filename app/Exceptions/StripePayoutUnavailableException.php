<?php

namespace App\Exceptions;

/**
 * Le virement IBAN ne PEUT PAS être tenté : la plateforme n'est pas en état de le
 * faire (compte vendeur non activé côté Stripe, ou solde plateforme indisponible
 * dans la devise du versement).
 *
 * Distincte d'une erreur Stripe survenue EN COURS de virement : elle est levée AVANT
 * tout mouvement d'argent, ce qui permet au contrôleur de refuser proprement la
 * demande (sans débiter le wallet) et d'afficher un message spécifique au vendeur.
 *
 * `reason` sert au routage du message :
 *   - `account_not_ready`     : capability `transfers` inactive (KYC incomplet)
 *   - `platform_funds`        : solde plateforme insuffisant dans la devise demandée
 *   - `currency_unavailable`  : la plateforme ne détient aucun solde dans cette devise
 */
class StripePayoutUnavailableException extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
