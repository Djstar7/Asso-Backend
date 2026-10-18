<?php

namespace App\Services;

/** Refus métier d'un envoi maritime : message affichable, réponse 422. */
class MaritimeBookingException extends \Exception
{
    public function __construct(string $message, private ?string $field = null)
    {
        parent::__construct($message);
    }

    public function field(): ?string
    {
        return $this->field;
    }
}
