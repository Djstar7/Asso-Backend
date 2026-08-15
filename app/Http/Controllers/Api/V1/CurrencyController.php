<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Currency;

class CurrencyController extends Controller
{
    /**
     * Get all active currencies
     */
    public function index()
    {
        try {
            $currencies = Currency::where('is_active', true)
                ->orderBy('code')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Currencies retrieved successfully',
                'data' => $currencies,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving currencies',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get all active currencies with their countries (for country selection)
     */
    public function getAllWithCountries()
    {
        try {
            $currencies = Currency::where('is_active', true)
                ->orderBy('name')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Currencies with countries retrieved successfully',
                'data' => $currencies,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving currencies',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get currency by country name
     */
    public function getByCountry(Request $request)
    {
        try {
            $country = $request->query('country');

            if (!$country) {
                return response()->json([
                    'success' => false,
                    'message' => 'Country parameter is required',
                ], 400);
            }

            // Detect database driver
            $driver = \DB::connection()->getDriverName();

            if ($driver === 'pgsql') {
                // PostgreSQL: Check if countries JSONB array contains the country
                $currency = Currency::where('is_active', true)
                    ->whereRaw("countries::jsonb @> ?::jsonb", [json_encode([$country])])
                    ->first();

                if (!$currency) {
                    // Try case-insensitive search
                    $currency = Currency::where('is_active', true)
                        ->whereRaw("EXISTS (
                            SELECT 1
                            FROM jsonb_array_elements_text(countries) AS country_name
                            WHERE LOWER(country_name) = LOWER(?)
                        )", [$country])
                        ->first();
                }
            } else {
                // MySQL/MariaDB: Use JSON_SEARCH
                $currency = Currency::where('is_active', true)
                    ->whereRaw("JSON_SEARCH(countries, 'one', ?) IS NOT NULL", [$country])
                    ->first();

                if (!$currency) {
                    // Try case-insensitive search
                    $currency = Currency::where('is_active', true)
                        ->whereRaw("LOWER(JSON_EXTRACT(countries, '$[*]')) LIKE ?", ['%' . strtolower($country) . '%'])
                        ->first();
                }
            }

            if (!$currency) {
                return response()->json([
                    'success' => false,
                    'message' => "No currency found for country: {$country}",
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Currency retrieved successfully',
                'data' => $currency,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving currency by country',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get exchange rate between two currencies
     * Uses ExchangeRateService with caching and external API fallback
     */
    public function getExchangeRate(Request $request)
    {
        try {
            $from = $request->query('from');
            $to = $request->query('to');

            if (!$from || !$to) {
                return response()->json([
                    'success' => false,
                    'message' => 'Both "from" and "to" currency parameters are required',
                ], 400);
            }

            // Verify currencies exist
            $fromCurrency = Currency::where('code', $from)->where('is_active', true)->first();
            $toCurrency = Currency::where('code', $to)->where('is_active', true)->first();

            if (!$fromCurrency || !$toCurrency) {
                return response()->json([
                    'success' => false,
                    'message' => 'One or both currencies not found',
                ], 404);
            }

            // Use ExchangeRateService to get the rate
            $exchangeRateService = app(\App\Services\ExchangeRateService::class);
            $rate = $exchangeRateService->getRate($from, $to);

            // If API fails, try to get latest rate from database
            if ($rate === null) {
                $rate = $exchangeRateService->getLatestRateFromDb($from, $to);
            }

            if ($rate === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unable to retrieve exchange rate. Please try again later.',
                ], 503);
            }

            return response()->json([
                'success' => true,
                'message' => 'Exchange rate retrieved successfully',
                'data' => [
                    'from' => $from,
                    'to' => $to,
                    'rate' => $rate,
                    'effective_date' => now()->toIso8601String(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving exchange rate',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
