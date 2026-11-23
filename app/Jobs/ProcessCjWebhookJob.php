<?php

namespace App\Jobs;

use App\Models\Supplier;
use App\Models\SupplierWebhookEvent;
use App\Services\Suppliers\SupplierManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Traitement en arrière-plan d'une alerte CJ reçue (voir CjWebhooks::process). */
class ProcessCjWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $eventId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(SupplierManager $suppliers): void
    {
        $event = SupplierWebhookEvent::find($this->eventId);
        if (! $event || $event->status !== SupplierWebhookEvent::PENDING) {
            return;
        }
        $suppliers->driver(Supplier::CJ)->webhooks()->process($event);
    }

    public function failed(\Throwable $e): void
    {
        SupplierWebhookEvent::find($this->eventId)?->finish(SupplierWebhookEvent::FAILED, $e->getMessage());
    }
}
