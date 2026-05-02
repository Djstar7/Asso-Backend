<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\ExchangeRate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CurrencyController extends Controller
{
    /**
     * Get all active currencies
     */
    public function index()
    {
        $currencies = Cache::remember('currencies_all', 3600, function () {
            return Currency::active()->get();
        });

        return response()->json([
            'success' => true,
            'data' => $currencies,
        ]);
    }

    /**
     * Get currency by country code (ISO 3166-1 alpha-2)
     */
    public function getCurrencyByCountry(Request $request)
    {
        $countryName = $request->input('country');

        if (!$countryName) {
            return response()->json([
                'success' => false,
                'message' => 'Country name is required',
            ], 400);
        }

        // Try exact match first
        $currency = Currency::active()
            ->whereJsonContains('countries', $countryName)
            ->first();

        // If not found, try partial match
        if (!$currency) {
            $currency = Currency::active()
                ->where(function ($query) use ($countryName) {
                    $query->whereRaw('LOWER(countries) LIKE ?', ['%' . strtolower($countryName) . '%']);
                })
                ->first();
        }

        // Special cases for common country variations
        if (!$currency) {
            $countryMap = [
                'United States' => 'USD',
                'USA' => 'USD',
                'US' => 'USD',
                'France' => 'EUR',
                'Senegal' => 'XOF',
                'Sénégal' => 'XOF',
                'Ivory Coast' => 'XOF',
                'Côte d\'Ivoire' => 'XOF',
            ];

            $currencyCode = $countryMap[$countryName] ?? null;
            if ($currencyCode) {
                $currency = Currency::where('code', $currencyCode)->first();
            }
        }

        if (!$currency) {
            // Default to XOF if country not found
            $currency = Currency::where('code', 'XOF')->first();
        }

        return response()->json([
            'success' => true,
            'data' => $currency,
            'debug' => [
                'searched_country' => $countryName,
                'found_currency' => $currency ? $currency->code : null,
            ],
        ]);
    }

    /**
     * Get exchange rate between two currencies
     */
    public function getExchangeRate(Request $request)
    {
        $from = strtoupper($request->input('from', 'XOF'));
        $to = strtoupper($request->input('to', 'USD'));

        $rate = Cache::remember("exchange_rate_{$from}_{$to}", 3600, function () use ($from, $to) {
            return ExchangeRate::latestRate($from, $to);
        });

        if (!$rate) {
            return response()->json([
                'success' => false,
                'message' => "Exchange rate not found for {$from} to {$to}",
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'from_currency' => $from,
                'to_currency' => $to,
                'rate' => $rate->rate,
                'effective_date' => $rate->effective_date,
            ],
        ]);
    }

    /**
     * Get all exchange rates from a base currency
     */
    public function getAllRates(Request $request)
    {
        $baseCurrency = strtoupper($request->input('base', 'XOF'));

        $rates = Cache::remember("exchange_rates_{$baseCurrency}", 3600, function () use ($baseCurrency) {
            return ExchangeRate::where('from_currency', $baseCurrency)
                ->where('is_active', true)
                ->with('toCurrency')
                ->get()
                ->groupBy('to_currency')
                ->map(function ($group) {
                    return $group->sortByDesc('effective_date')->first();
                })
                ->values();
        });

        return response()->json([
            'success' => true,
            'base_currency' => $baseCurrency,
            'data' => $rates,
        ]);
    }

    /**
     * Convert amount from one currency to another
     */
    public function convert(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0',
            'from' => 'required|string|size:3',
            'to' => 'required|string|size:3',
        ]);

        $amount = $request->input('amount');
        $from = strtoupper($request->input('from'));
        $to = strtoupper($request->input('to'));

        // If same currency, no conversion needed
        if ($from === $to) {
            return response()->json([
                'success' => true,
                'data' => [
                    'original_amount' => $amount,
                    'original_currency' => $from,
                    'converted_amount' => $amount,
                    'converted_currency' => $to,
                    'rate' => 1.0,
                ],
            ]);
        }

        $rate = ExchangeRate::latestRate($from, $to);

        if (!$rate) {
            return response()->json([
                'success' => false,
                'message' => "Exchange rate not found for {$from} to {$to}",
            ], 404);
        }

        $convertedAmount = $rate->convert($amount);

        return response()->json([
            'success' => true,
            'data' => [
                'original_amount' => $amount,
                'original_currency' => $from,
                'converted_amount' => round($convertedAmount, 2),
                'converted_currency' => $to,
                'rate' => $rate->rate,
                'effective_date' => $rate->effective_date,
            ],
        ]);
    }

    /**
     * Get currency data for a specific country (with exchange rates to XOF)
     */
    public function getCountryCurrency(string $countryName)
    {
        $currency = Cache::remember("currency_country_full_{$countryName}", 3600, function () use ($countryName) {
            return Currency::active()
                ->whereJsonContains('countries', $countryName)
                ->with(['exchangeRatesFrom' => function ($query) {
                    $query->where('is_active', true)
                        ->orderBy('effective_date', 'desc')
                        ->limit(10);
                }])
                ->first();
        });

        if (!$currency) {
            // Default to XOF if country not found
            $currency = Currency::where('code', 'XOF')
                ->with(['exchangeRatesFrom' => function ($query) {
                    $query->where('is_active', true)
                        ->orderBy('effective_date', 'desc')
                        ->limit(10);
                }])
                ->first();
        }

        // Get rate to XOF (base currency)
        $rateToXOF = ExchangeRate::latestRate($currency->code, 'XOF');

        return response()->json([
            'success' => true,
            'data' => [
                'currency' => $currency,
                'rate_to_xof' => $rateToXOF?->rate ?? 1.0,
            ],
        ]);
    }
}
