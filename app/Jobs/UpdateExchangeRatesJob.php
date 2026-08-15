<?php

namespace App\Jobs;

use App\Services\ExchangeRateService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class UpdateExchangeRatesJob implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 60;

    /**
     * Execute the job.
     * Updates exchange rates from external API
     */
    public function handle(ExchangeRateService $exchangeRateService): void
    {
        Log::info('Starting UpdateExchangeRatesJob');

        try {
            // Update rates for main currencies (XOF, USD, EUR)
            $results = $exchangeRateService->updateAllRates();

            $successCount = 0;
            $failCount = 0;

            foreach ($results as $currency => $result) {
                if ($result['success']) {
                    $successCount++;
                    Log::info("Successfully updated rates for {$currency}: {$result['count']} rates saved");
                } else {
                    $failCount++;
                    Log::warning("Failed to update rates for {$currency}: {$result['message']}");
                }
            }

            Log::info("UpdateExchangeRatesJob completed. Success: {$successCount}, Failed: {$failCount}");

        } catch (\Exception $e) {
            Log::error("UpdateExchangeRatesJob failed: " . $e->getMessage());
            throw $e; // Re-throw to trigger retry mechanism
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("UpdateExchangeRatesJob failed permanently: " . $exception->getMessage());
    }
}
