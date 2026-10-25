<?php

namespace App\Console\Commands;

use App\Services\NotificationDigestService;
use Illuminate\Console\Command;

class SendNotificationDigests extends Command
{
    protected $signature = 'notifications:send-digests {--date= : Business date YYYY-MM-DD, yesterday by default}';

    protected $description = 'Send one localized promotional summary per user and business day';

    public function handle(NotificationDigestService $digests): int
    {
        $date = $this->option('date') ?: now(config('notifications.digest_timezone'))->subDay()->toDateString();
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->error('Invalid date (YYYY-MM-DD).');

            return self::FAILURE;
        }
        try {
            $count = $digests->send($date);
        } catch (\InvalidArgumentException $e) {
            $this->error('Invalid date (YYYY-MM-DD).');

            return self::FAILURE;
        }
        $this->info($count.' summary notification(s) recorded.');

        return self::SUCCESS;
    }
}
