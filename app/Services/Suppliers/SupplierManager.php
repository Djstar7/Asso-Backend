<?php

namespace App\Services\Suppliers;

use App\Models\Supplier;
use App\Services\Suppliers\Cj\CjDriver;
use InvalidArgumentException;

/** Point d'entrée unique vers les fournisseurs : SupplierManager::driver('cj'). */
class SupplierManager
{
    /** @var array<string, class-string<SupplierDriver>> */
    private const DRIVERS = [
        Supplier::CJ => CjDriver::class,
    ];

    public function driver(string $code): SupplierDriver
    {
        $class = self::DRIVERS[$code] ?? throw new InvalidArgumentException("Fournisseur inconnu : {$code}");

        return new $class(Supplier::forCode($code));
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_keys(self::DRIVERS);
    }
}
