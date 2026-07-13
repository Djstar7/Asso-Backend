<?php

namespace App\Services;

use App\Models\ServiceConfiguration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Conversion de devises via exchangerate-api.com (API v6).
 * Clé configurable dans le panel admin (service_configurations 'exchange_rate').
 * Les taux sont mis en cache 1 heure.
 */
class ExchangeRateService
{
    private const CACHE_TTL = 3600; // 1 heure

    private static function apiKey(): ?string
    {
        $config = ServiceConfiguration::getConfig('exchange_rate') ?? [];
        return $config['api_key'] ?? env('EXCHANGE_RATE_API_KEY');
    }

    /**
     * Taux de conversion $from → $to. Retourne null si indisponible.
     */
    public static function rate(string $from, string $to): ?float
    {
        $from = strtoupper($from);
        $to = strtoupper($to);
        if ($from === $to) {
            return 1.0;
        }

        $cacheKey = "exrate_{$from}_{$to}";
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($from, $to) {
            $key = self::apiKey();
            if (empty($key)) {
                Log::warning('[ExchangeRateService] Clé API manquante');
                return null;
            }
            try {
                $response = Http::timeout(15)
                    ->get("https://v6.exchangerate-api.com/v6/{$key}/pair/{$from}/{$to}");
                $data = $response->json() ?? [];
                if ($response->successful() && ($data['result'] ?? null) === 'success') {
                    return (float) $data['conversion_rate'];
                }
                Log::warning('[ExchangeRateService] Réponse invalide', ['body' => $response->body()]);
                return null;
            } catch (\Exception $e) {
                Log::error('[ExchangeRateService] Exception', ['error' => $e->getMessage()]);
                return null;
            }
        });
    }

    /**
     * Convertit un montant. Retourne
     *   ['success' => bool, 'rate' => float, 'amount' => float, 'from' => .., 'to' => ..]
     * En cas d'échec (pas de clé / API indisponible) et devises différentes,
     * success = false (le montant n'est PAS deviné).
     */
    public static function convert(string $from, string $to, float $amount): array
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($from === $to) {
            return ['success' => true, 'rate' => 1.0, 'amount' => $amount, 'from' => $from, 'to' => $to];
        }

        $rate = self::rate($from, $to);
        if ($rate === null) {
            return ['success' => false, 'rate' => null, 'amount' => null, 'from' => $from, 'to' => $to];
        }

        return [
            'success' => true,
            'rate' => $rate,
            'amount' => round($amount * $rate, 2),
            'from' => $from,
            'to' => $to,
        ];
    }
}
