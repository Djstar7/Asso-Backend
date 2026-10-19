<?php

namespace App\Console\Commands;

use App\Models\RealEstate\Reservation;
use App\Services\RealEstate\RealEstateReservationService;
use Illuminate\Console\Command;

/**
 * Filet de sécurité des paiements Mobile Money et carte : relit chez le
 * prestataire les réservations restées en attente (webhook perdu, application
 * fermée pendant le paiement) et annule celles abandonnées depuis plus d'un jour.
 */
class RealEstateReconcilePending extends Command
{
    protected $signature = 'immo:reconcile-pending';

    protected $description = 'Relit les paiements immobiliers en attente';

    public function handle(RealEstateReservationService $service): int
    {
        Reservation::where('status', 'pending_payment')
            ->where('created_at', '>=', now()->subDays(2))
            ->whereNotNull('payment_reference')
            ->get()
            ->each(fn (Reservation $r) => $service->checkAndConfirm($r));

        $abandoned = Reservation::where('status', 'pending_payment')
            ->where('created_at', '<', now()->subDay())
            ->update(['status' => 'cancelled']);
        $this->info("Paiements relus ; $abandoned réservation(s) abandonnée(s) annulée(s).");

        return self::SUCCESS;
    }
}
