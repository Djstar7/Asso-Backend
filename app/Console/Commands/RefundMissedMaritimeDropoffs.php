<?php

namespace App\Console\Commands;

use App\Models\MaritimeDeparture;
use App\Services\MaritimeBookingService;
use Illuminate\Console\Command;

/**
 * Diaspo Maritime, chaque jour : ferme les départs dont la clôture des
 * réservations est passée, puis rembourse (hors commission ASSO) les envois
 * dont le colis n'a pas été déposé au QG avant la date limite.
 */
class RefundMissedMaritimeDropoffs extends Command
{
    protected $signature = 'maritime:refund-missed-dropoffs';

    protected $description = 'Ferme les départs maritimes clos et rembourse les colis non déposés à temps';

    public function handle(MaritimeBookingService $service): int
    {
        $closed = 0;
        MaritimeDeparture::where('status', MaritimeDeparture::STATUS_OPEN)->get()
            ->filter(fn (MaritimeDeparture $d) => $d->daysLeftToBook() < 0)
            ->each(function (MaritimeDeparture $d) use (&$closed) {
                $d->update(['status' => MaritimeDeparture::STATUS_CLOSED]);
                $closed++;
            });

        $refunded = $service->refundMissedDropoffs();

        $this->info("Départs fermés : {$closed} — envois remboursés : {$refunded}");

        return self::SUCCESS;
    }
}
