<?php

namespace App\Console\Commands;

use App\Services\NotificationDispatchService;
use Illuminate\Console\Command;

class RetryNotificationPushes extends Command
{
    protected $signature = 'notifications:retry';

    protected $description = 'Retry pending push deliveries without duplicating notification history';

    public function handle(NotificationDispatchService $dispatch): int
    {
        $dispatch->retry();

        return self::SUCCESS;
    }
}
