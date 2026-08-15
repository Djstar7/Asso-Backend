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
     *
     * Source de vérité : l'API live (exchangerate-api.com) en priorité ; si elle
     * est indisponible (pas de clé, réseau, quota), on retombe sur les taux
     * configurés en base par l'admin (`exchange_rates`). Aucun fallback à 1.0 :
     * si aucune source n'a de taux, on renvoie null (l'appelant refuse alors de
     * convertir un montant plutôt que de le fausser).
     */
    public static function rate(string $from, string $to): ?float
    {
        $from = strtoupper($from);
        $to = strtoupper($to);
        if ($from === $to) {
            return 1.0;
        }

        // 1) API live (mise en cache 1h) — source de vérité.
        $live = self::liveRate($from, $to);
        if ($live !== null) {
            return $live;
        }

        // 2) Secours : taux configurés en base par l'admin.
        $stored = self::storedRate($from, $to);
        if ($stored !== null) {
            Log::info('[ExchangeRateService] Taux live indisponible, fallback DB utilisé', [
                'from' => $from, 'to' => $to, 'rate' => $stored,
            ]);
        }
        return $stored;
    }

    /**
     * Taux via l'API live exchangerate-api.com (caché 1h). null si indisponible.
     */
    private static function liveRate(string $from, string $to): ?float
    {
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
     * Taux stocké en base par l'admin (`exchange_rates` actifs). Tente la paire
     * directe, puis l'inverse de la paire opposée. null si rien de configuré.
     */
    private static function storedRate(string $from, string $to): ?float
    {
        $direct = \App\Models\ExchangeRate::where('is_active', true)
            ->where('from_currency', $from)
            ->where('to_currency', $to)
            ->first();
        if ($direct) {
            return (float) $direct->rate;
        }

        $inverse = \App\Models\ExchangeRate::where('is_active', true)
            ->where('from_currency', $to)
            ->where('to_currency', $from)
            ->first();
        if ($inverse && (float) $inverse->rate != 0.0) {
            return round(1 / (float) $inverse->rate, 8);
        }

        return null;
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
