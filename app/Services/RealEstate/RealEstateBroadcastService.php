<?php

namespace App\Services\RealEstate;

use App\Models\RealEstate\Listing;

/** Publications are discovered by opted-in criteria alerts, not broadcast to every device. */
class RealEstateBroadcastService
{
    public function send(int $listingId): void
    {
        Listing::published()->whereKey($listingId)->whereNull('announced_at')
            ->update(['announced_at' => now(), 'announced_topics' => json_encode([])]);
    }
}
