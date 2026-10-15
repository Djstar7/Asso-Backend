<?php

namespace App\Exceptions;

/**
 * Le vendeur a saisi son propre code commercial : il doit confirmer qu'il continue
 * sans commission (personne ne la perçoit sur son propre abonnement).
 */
class OwnSalesCodeException extends InvalidSalesCodeException {}
