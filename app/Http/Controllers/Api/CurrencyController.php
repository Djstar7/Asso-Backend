<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\ExchangeRate;
use Illuminate\Http\Request;

class CurrencyController extends Controller
{
    /**
     * List all active currencies (flat list).
     * GET /v1/currencies
     */
    public function index()
    {
        $currencies = Currency::where('is_active', true)
            ->orderBy('code')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $currencies->map(fn($c) => $this->transform($c)),
        ]);
    }

    /**
     * List all currencies together with their countries.
     * GET /v1/currencies/all-with-countries
     */
    public function allWithCountries()
    {
        $currencies = Currency::where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $currencies->map(fn($c) => $this->transform($c)),
        ]);
    }

    /**
     * Resolve the currency of a given country.
     * GET /v1/currencies/by-country?country=Benin
     */
    public function byCountry(Request $request)
    {
        $country = trim((string) $request->query('country', ''));

        if ($country === '') {
            return response()->json([
                'success' => false,
                'message' => 'Le paramètre "country" est requis.',
            ], 422);
        }

        // Case-insensitive match inside the JSON countries array.
        $currency = Currency::where('is_active', true)
            ->get()
            ->first(function ($c) use ($country) {
                return collect($c->countries)->contains(
                    fn($name) => mb_strtolower($name) === mb_strtolower($country)
                );
            });

        if (!$currency) {
            return response()->json([
                'success' => false,
                'message' => "Aucune devise trouvée pour le pays: {$country}",
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->transform($currency),
        ]);
    }

    /**
     * Exchange rate between two currency codes.
     * GET /v1/currencies/exchange-rate?from=EUR&to=XOF
     */
    public function exchangeRate(Request $request)
    {
        $from = strtoupper(trim((string) $request->query('from', '')));
        $to = strtoupper(trim((string) $request->query('to', '')));

        if ($from === '' || $to === '') {
            return response()->json([
                'success' => false,
                'message' => 'Les paramètres "from" et "to" sont requis.',
            ], 422);
        }

        if ($from === $to) {
            return response()->json([
                'success' => true,
                'data' => ['from' => $from, 'to' => $to, 'rate' => 1.0],
            ]);
        }

        $rate = $this->resolveRate($from, $to);

        return response()->json([
            'success' => true,
            'data' => ['from' => $from, 'to' => $to, 'rate' => $rate],
        ]);
    }

    /**
     * Look up a stored rate, falling back to the inverse of the reverse pair,
     * then to 1.0 when nothing is configured.
     */
    private function resolveRate(string $from, string $to): float
    {
        $direct = ExchangeRate::where('is_active', true)
            ->where('from_currency', $from)
            ->where('to_currency', $to)
            ->first();

        if ($direct) {
            return (float) $direct->rate;
        }

        $inverse = ExchangeRate::where('is_active', true)
            ->where('from_currency', $to)
            ->where('to_currency', $from)
            ->first();

        if ($inverse && (float) $inverse->rate != 0.0) {
            return round(1 / (float) $inverse->rate, 8);
        }

        return 1.0;
    }

    /**
     * Shape a Currency model for the mobile client (matches CurrencyModel.fromJson).
     */
    private function transform(Currency $c): array
    {
        return [
            'id' => $c->id,
            'code' => $c->code,
            'name' => $c->name,
            'symbol' => $c->symbol,
            'countries' => $c->countries ?? [],
            'is_active' => $c->is_active,
        ];
    }
}
