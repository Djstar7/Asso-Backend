<?php

namespace App\Console\Commands;

use App\Services\RealEstate\RealEstateReservationService;
use Illuminate\Console\Command;

/**
 * Débloque la part du bailleur (ou du cabinet) quand le délai de visite est
 * passé sans confirmation ni litige.
 */
class RealEstateAutoRelease extends Command
{
    protected $signature = 'immo:auto-release';

    protected $description = 'Débloque les réservations immobilières dont le délai de visite est écoulé';

    public function handle(RealEstateReservationService $service): int
    {
        $count = $service->autoRelease();
        $this->info("$count réservation(s) débloquée(s).");

        return self::SUCCESS;
    }
}
