<?php

namespace App\Console\Commands;

use App\Models\DiaspoOffer;
use App\Models\MaritimeDeparture;
use Illuminate\Console\Command;

/**
 * Chaque jour, pour les annonces dont le départ est passé : suppression quand
 * personne n'y a réservé ; sinon l'annonce reste, car le code de validation, les
 * litiges et la comptabilité en dépendent (supprimer une offre annule en plus ses
 * réservations, voir DiaspoOfferObserver).
 */
class PurgeExpiredDiaspoListings extends Command
{
    protected $signature = 'diaspo:purge-expired';

    protected $description = 'Supprime les offres et départs passés sans réservation, clôt les autres offres';

    public function handle(): int
    {
        $offers = 0;
        DiaspoOffer::where('departure_datetime', '<', today())
            ->whereDoesntHave('bookings')
            ->eachById(function (DiaspoOffer $offer) use (&$offers) {
                $offer->forceFill(['removal_reason' => $offer->removal_reason ?? 'Départ passé sans réservation'])->saveQuietly();
                $offer->delete();
                $offers++;
            });

        $expired = DiaspoOffer::where('departure_datetime', '<', now())
            ->whereNotIn('status', ['expired', 'rejected', 'completed'])
            ->update(['status' => 'expired']);

        $departures = 0;
        MaritimeDeparture::whereDate('departure_date', '<', today())
            ->whereDoesntHave('bookings')
            ->whereDoesntHave('freightInquiries')
            ->eachById(function (MaritimeDeparture $departure) use (&$departures) {
                $departure->delete();
                $departures++;
            });

        $this->info("Offres supprimées : {$offers} — offres clôturées : {$expired} — départs supprimés : {$departures}");

        return self::SUCCESS;
    }
}
