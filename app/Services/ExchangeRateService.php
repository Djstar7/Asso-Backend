<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\ExchangeRate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ExchangeRateService
{
    /**
     * ExchangeRate-API.com base URL
     * Free tier: 1500 requests/month
     */
    private const API_BASE_URL = 'https://v6.exchangerate-api.com/v6';

    /**
     * Cache duration in minutes (1 hour)
     */
    private const CACHE_DURATION_MINUTES = 60;

    /**
     * Get exchange rate between two currencies
     * Uses cache if available, otherwise fetches from external API
     */
    public function getRate(string $from, string $to): ?float
    {
        // Check cache first
        $cachedRate = $this->getCachedRate($from, $to);

        if ($cachedRate) {
            Log::info("Using cached exchange rate: {$from} to {$to} = {$cachedRate->rate}");
            return (float) $cachedRate->rate;
        }

        // Fetch from external API
        return $this->fetchAndCacheRate($from, $to);
    }

    /**
     * Get cached exchange rate if available and not expired
     */
    private function getCachedRate(string $from, string $to): ?ExchangeRate
    {
        $expiryTime = Carbon::now()->subMinutes(self::CACHE_DURATION_MINUTES);

        return ExchangeRate::where('from_currency', $from)
            ->where('to_currency', $to)
            ->where('is_active', true)
            ->where('updated_at', '>=', $expiryTime)
            ->orderBy('effective_date', 'desc')
            ->first();
    }

    /**
     * Fetch exchange rate from external API and cache it
     */
    private function fetchAndCacheRate(string $from, string $to): ?float
    {
        try {
            $apiKey = config('services.exchangerate.api_key');

            if (!$apiKey) {
                Log::error('ExchangeRate API key not configured');
                return null;
            }

            // Fetch latest rates for the base currency
            $response = Http::timeout(10)->get("{$this->getApiUrl()}/{$apiKey}/latest/{$from}");

            if (!$response->successful()) {
                Log::error("ExchangeRate API error: {$response->status()} - {$response->body()}");
                return null;
            }

            $data = $response->json();

            if ($data['result'] !== 'success') {
                Log::error("ExchangeRate API returned error: " . json_encode($data));
                return null;
            }

            $conversionRates = $data['conversion_rates'] ?? [];

            if (!isset($conversionRates[$to])) {
                Log::error("Currency {$to} not found in conversion rates");
                return null;
            }

            $rate = $conversionRates[$to];

            // Save to database
            $this->saveRate($from, $to, $rate);

            Log::info("Fetched and cached new exchange rate: {$from} to {$to} = {$rate}");

            return $rate;

        } catch (\Exception $e) {
            Log::error("Error fetching exchange rate: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Save exchange rate to database
     */
    private function saveRate(string $from, string $to, float $rate): void
    {
        try {
            $today = Carbon::today();

            ExchangeRate::updateOrCreate(
                [
                    'from_currency' => $from,
                    'to_currency' => $to,
                    'effective_date' => $today,
                ],
                [
                    'rate' => $rate,
                    'is_active' => true,
                    'source' => 'exchangerate-api',
                ]
            );
        } catch (\Exception $e) {
            Log::error("Error saving exchange rate: " . $e->getMessage());
        }
    }

    /**
     * Update all exchange rates for a base currency
     * Useful for batch updates
     */
    public function updateRatesForCurrency(string $baseCurrency): array
    {
        try {
            $apiKey = config('services.exchangerate.api_key');

            if (!$apiKey) {
                return ['success' => false, 'message' => 'API key not configured'];
            }

            $response = Http::timeout(10)->get("{$this->getApiUrl()}/{$apiKey}/latest/{$baseCurrency}");

            if (!$response->successful()) {
                return ['success' => false, 'message' => "API error: {$response->status()}"];
            }

            $data = $response->json();

            if ($data['result'] !== 'success') {
                return ['success' => false, 'message' => 'API returned error'];
            }

            $conversionRates = $data['conversion_rates'] ?? [];
            $savedCount = 0;
            $today = Carbon::today();

            foreach ($conversionRates as $targetCurrency => $rate) {
                // Only save rates for currencies that exist in our database
                if (Currency::where('code', $targetCurrency)->where('is_active', true)->exists()) {
                    ExchangeRate::updateOrCreate(
                        [
                            'from_currency' => $baseCurrency,
                            'to_currency' => $targetCurrency,
                            'effective_date' => $today,
                        ],
                        [
                            'rate' => $rate,
                            'is_active' => true,
                            'source' => 'exchangerate-api',
                        ]
                    );
                    $savedCount++;
                }
            }

            Log::info("Updated {$savedCount} exchange rates for {$baseCurrency}");

            return [
                'success' => true,
                'message' => "Updated {$savedCount} rates",
                'count' => $savedCount,
            ];

        } catch (\Exception $e) {
            Log::error("Error updating rates for {$baseCurrency}: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Update exchange rates for all major currencies
     * This method fetches rates from XOF to all other currencies
     */
    public function updateAllRates(): array
    {
        $baseCurrencies = ['XOF', 'USD', 'EUR']; // Base currencies to fetch from
        $results = [];

        foreach ($baseCurrencies as $baseCurrency) {
            $results[$baseCurrency] = $this->updateRatesForCurrency($baseCurrency);

            // Sleep to respect API rate limits (if needed)
            if (count($baseCurrencies) > 1) {
                sleep(1);
            }
        }

        return $results;
    }

    /**
     * Convert amount from one currency to another
     */
    public function convert(float $amount, string $from, string $to): ?float
    {
        if ($from === $to) {
            return $amount;
        }

        $rate = $this->getRate($from, $to);

        if (!$rate) {
            return null;
        }

        return $amount * $rate;
    }

    /**
     * Get the latest rate from database (fallback when API is down)
     */
    public function getLatestRateFromDb(string $from, string $to): ?float
    {
        $rate = ExchangeRate::where('from_currency', $from)
            ->where('to_currency', $to)
            ->where('is_active', true)
            ->orderBy('effective_date', 'desc')
            ->first();

        return $rate ? (float) $rate->rate : null;
    }

    /**
     * Get API URL (can be overridden for testing)
     */
    protected function getApiUrl(): string
    {
        return self::API_BASE_URL;
    }
}
