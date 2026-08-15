<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\ServiceConfiguration;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service de taux de change.
 *
 * Deux responsabilités unifiées ici :
 *  - LECTURE (API statique) : rate()/convert() — source de vérité pour convertir un
 *    montant. API live (exchangerate-api.com /pair) prioritaire, puis secours sur les
 *    taux stockés en base (`exchange_rates`). Jamais de fallback 1.0 entre devises ≠.
 *  - SYNC (API d'instance) : getRate()/updateAllRates()/... — alimente la table
 *    `exchange_rates` (endpoint /latest), utilisée par le job planifié
 *    UpdateExchangeRatesJob et le panel admin. Le secours DB de la lecture s'appuie
 *    sur ce que la sync a stocké.
 */
class ExchangeRateService
{
    // --- Lecture (statique) ---
    private const CACHE_TTL = 3600; // 1 heure

    // --- Sync (instance) ---
    private const API_BASE_URL = 'https://v6.exchangerate-api.com/v6';
    private const CACHE_DURATION_MINUTES = 60;

    // =====================================================================
    // LECTURE — API statique (source de vérité pour convertir un montant)
    // =====================================================================

    private static function apiKey(): ?string
    {
        $config = ServiceConfiguration::getConfig('exchange_rate') ?? [];
        return $config['api_key'] ?? env('EXCHANGE_RATE_API_KEY') ?? config('services.exchangerate.api_key');
    }

    /**
     * Taux de conversion $from → $to. Retourne null si indisponible.
     *
     * Source de vérité : l'API live (exchangerate-api.com) en priorité ; si elle
     * est indisponible (pas de clé, réseau, quota), on retombe sur les taux
     * configurés/synchronisés en base (`exchange_rates`). Aucun fallback à 1.0 :
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

        // 2) Secours : taux stockés en base (par l'admin ou par la sync).
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
        $live = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($from, $to) {
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

        // Ne pas garder un échec en cache (on veut réessayer + tenter la DB).
        if ($live === null) {
            Cache::forget($cacheKey);
        }
        return $live;
    }

    /**
     * Taux stocké en base (`exchange_rates` actifs). Tente la paire directe,
     * puis l'inverse de la paire opposée. null si rien de configuré.
     */
    private static function storedRate(string $from, string $to): ?float
    {
        $direct = ExchangeRate::where('is_active', true)
            ->where('from_currency', $from)
            ->where('to_currency', $to)
            ->orderBy('effective_date', 'desc')
            ->first();
        if ($direct) {
            return (float) $direct->rate;
        }

        $inverse = ExchangeRate::where('is_active', true)
            ->where('from_currency', $to)
            ->where('to_currency', $from)
            ->orderBy('effective_date', 'desc')
            ->first();
        if ($inverse && (float) $inverse->rate != 0.0) {
            return round(1 / (float) $inverse->rate, 8);
        }

        return null;
    }

    /**
     * Convertit un montant. Retourne
     *   ['success' => bool, 'rate' => float|null, 'amount' => float|null, 'from' => .., 'to' => ..]
     * En cas d'échec (pas de clé / API indisponible / pas de taux DB) et devises
     * différentes, success = false (le montant n'est PAS deviné).
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

    /**
     * Raccourci pratique : convertit un montant et renvoie le float (ou null).
     * Remplace l'ancien `->convert($amount, $from, $to)` d'instance (signature
     * unifiée : ordre from/to/amount, cohérent avec convert()).
     */
    public static function convertAmount(string $from, string $to, float $amount): ?float
    {
        $res = self::convert($from, $to, $amount);
        return $res['success'] ? (float) $res['amount'] : null;
    }

    // =====================================================================
    // SYNC — API d'instance (alimente la table exchange_rates)
    // =====================================================================

    /**
     * Taux $from → $to pour la sync : cache DB récent d'abord, sinon fetch API + cache.
     */
    public function getRate(string $from, string $to): ?float
    {
        $cachedRate = $this->getCachedRate($from, $to);
        if ($cachedRate) {
            return (float) $cachedRate->rate;
        }
        return $this->fetchAndCacheRate($from, $to);
    }

    /**
     * Taux en cache DB si présent et non expiré.
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
     * Récupère le taux depuis l'API (endpoint /latest) et le stocke en base.
     */
    private function fetchAndCacheRate(string $from, string $to): ?float
    {
        try {
            $apiKey = self::apiKey();
            if (!$apiKey) {
                Log::error('ExchangeRate API key not configured');
                return null;
            }

            $response = Http::timeout(10)->get("{$this->getApiUrl()}/{$apiKey}/latest/{$from}");
            if (!$response->successful()) {
                Log::error("ExchangeRate API error: {$response->status()} - {$response->body()}");
                return null;
            }

            $data = $response->json();
            if (($data['result'] ?? null) !== 'success') {
                Log::error("ExchangeRate API returned error: " . json_encode($data));
                return null;
            }

            $conversionRates = $data['conversion_rates'] ?? [];
            if (!isset($conversionRates[$to])) {
                Log::error("Currency {$to} not found in conversion rates");
                return null;
            }

            $rate = $conversionRates[$to];
            $this->saveRate($from, $to, $rate);
            Log::info("Fetched and cached new exchange rate: {$from} to {$to} = {$rate}");

            return $rate;
        } catch (\Exception $e) {
            Log::error("Error fetching exchange rate: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Enregistre un taux en base (upsert par from/to/effective_date).
     */
    private function saveRate(string $from, string $to, float $rate): void
    {
        try {
            $today = Carbon::today();
            ExchangeRate::updateOrCreate(
                ['from_currency' => $from, 'to_currency' => $to, 'effective_date' => $today],
                ['rate' => $rate, 'is_active' => true, 'source' => 'exchangerate-api']
            );
        } catch (\Exception $e) {
            Log::error("Error saving exchange rate: " . $e->getMessage());
        }
    }

    /**
     * Met à jour tous les taux pour une devise de base (batch).
     */
    public function updateRatesForCurrency(string $baseCurrency): array
    {
        try {
            $apiKey = self::apiKey();
            if (!$apiKey) {
                return ['success' => false, 'message' => 'API key not configured'];
            }

            $response = Http::timeout(10)->get("{$this->getApiUrl()}/{$apiKey}/latest/{$baseCurrency}");
            if (!$response->successful()) {
                return ['success' => false, 'message' => "API error: {$response->status()}"];
            }

            $data = $response->json();
            if (($data['result'] ?? null) !== 'success') {
                return ['success' => false, 'message' => 'API returned error'];
            }

            $conversionRates = $data['conversion_rates'] ?? [];
            $savedCount = 0;
            $today = Carbon::today();

            foreach ($conversionRates as $targetCurrency => $rate) {
                if (Currency::where('code', $targetCurrency)->where('is_active', true)->exists()) {
                    ExchangeRate::updateOrCreate(
                        ['from_currency' => $baseCurrency, 'to_currency' => $targetCurrency, 'effective_date' => $today],
                        ['rate' => $rate, 'is_active' => true, 'source' => 'exchangerate-api']
                    );
                    $savedCount++;
                }
            }

            Log::info("Updated {$savedCount} exchange rates for {$baseCurrency}");
            return ['success' => true, 'message' => "Updated {$savedCount} rates", 'count' => $savedCount];
        } catch (\Exception $e) {
            Log::error("Error updating rates for {$baseCurrency}: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Met à jour les taux pour les devises de base majeures.
     */
    public function updateAllRates(): array
    {
        $baseCurrencies = ['XOF', 'USD', 'EUR'];
        $results = [];

        foreach ($baseCurrencies as $baseCurrency) {
            $results[$baseCurrency] = $this->updateRatesForCurrency($baseCurrency);
            if (count($baseCurrencies) > 1) {
                sleep(1);
            }
        }

        return $results;
    }

    /**
     * Dernier taux stocké en base (fallback quand l'API est indisponible).
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
     * URL de l'API (surchargeable en test).
     */
    protected function getApiUrl(): string
    {
        return self::API_BASE_URL;
    }
}
