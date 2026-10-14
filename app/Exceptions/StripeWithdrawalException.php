<?php

namespace App\Exceptions;

/**
 * Virement IBAN refusé ou échoué, sans débit du Wallet : `getMessage()` est déjà
 * traduit et affichable tel quel, `status` est le code HTTP à renvoyer par l'API.
 */
class StripeWithdrawalException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
