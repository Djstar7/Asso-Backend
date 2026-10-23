<?php

namespace App\Observers;

use App\Models\RealEstate\Listing;
use App\Services\RealEstate\RealEstateBroadcastService;
use Illuminate\Support\Facades\DB;

class RealEstateListingObserver
{
    public function saving(Listing $listing): void
    {
        if ($listing->status === Listing::STATUS_PUBLISHED && ! $listing->published_at) {
            $listing->published_at = now();
        }
    }

    public function saved(Listing $listing): void
    {
        if ($listing->status === Listing::STATUS_PUBLISHED
            && ($listing->wasRecentlyCreated || $listing->wasChanged('status'))) {
            // Pas d'envoi pour une publication annulée par un rollback.
            DB::afterCommit(fn () => app(RealEstateBroadcastService::class)->send($listing->id));
        }
    }
}
