<?php

namespace App\Services\Suppliers;

use RuntimeException;

/** Import refusé pour une raison connue (statut SupplierProduct associé). */
class SupplierImportException extends RuntimeException
{
    public function __construct(string $message, public readonly string $status = 'error')
    {
        parent::__construct($message);
    }
}
