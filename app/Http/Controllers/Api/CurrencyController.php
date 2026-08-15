<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Currency;
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
     * Convert an amount between two currencies (exchangerate-api.com).
     * GET /v1/currencies/convert?from=XAF&to=ZMW&amount=100
     * Utilisé par le mobile pour prévisualiser le montant réellement débité.
     */
    public function convert(Request $request)
    {
        $from = strtoupper(trim((string) $request->query('from', '')));
        $to = strtoupper(trim((string) $request->query('to', '')));
        $amount = (float) $request->query('amount', 0);

        if ($from === '' || $to === '' || $amount <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Paramètres "from", "to" et "amount" requis.',
            ], 422);
        }

        $result = \App\Services\ExchangeRateService::convert($from, $to, $amount);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => "Conversion $from → $to indisponible.",
            ], 400);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'from' => $from,
                'to' => $to,
                'amount' => $amount,
                'rate' => $result['rate'],
                'converted' => round($result['amount']),
            ],
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

        // Source unique : API live prioritaire, taux DB en secours (voir ExchangeRateService).
        $rate = \App\Services\ExchangeRateService::rate($from, $to);

        if ($rate === null) {
            return response()->json([
                'success' => false,
                'message' => "Taux de change $from → $to indisponible.",
            ], 400);
        }

        return response()->json([
            'success' => true,
            'data' => ['from' => $from, 'to' => $to, 'rate' => $rate],
        ]);
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
