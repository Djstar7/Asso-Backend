<?php

namespace App\Console\Commands;

use App\Models\RealEstate\Listing;
use App\Services\RealEstate\RealEstateBroadcastService;
use Illuminate\Console\Command;

class RealEstateNotifyPublications extends Command
{
    protected $signature = 'immo:notify-publications';

    protected $description = 'Reprend les notifications de publication immobilière en échec';

    public function handle(RealEstateBroadcastService $broadcast): int
    {
        Listing::published()->whereNull('announced_at')->select('id')->chunkById(100, function ($listings) use ($broadcast) {
            foreach ($listings as $listing) {
                $broadcast->send($listing->id);
            }
        });

        return self::SUCCESS;
    }
}
