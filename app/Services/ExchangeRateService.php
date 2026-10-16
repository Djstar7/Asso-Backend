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
    // 6 h : le plan gratuit d'exchangerate-api ne met ses taux à jour qu'une fois
    // par jour. Avec 1 h, les appels /pair dépassaient le quota mensuel et l'API
    // répondait « quota-reached » à tout (paiements carte compris).
    private const CACHE_TTL = 21600;

    // Pause des appels API après un échec : sans elle, CHAQUE conversion relançait
    // un appel HTTP (plus de 800 par jour), ce qui épuisait encore le quota.
    private const COOLDOWN_KEY = 'exrate_api_cooldown';
    private const COOLDOWN_QUOTA = 21600;  // quota atteint / 429 / clé refusée : 6 h
    private const COOLDOWN_ERROR = 600;    // autre échec (réseau…) : 10 min

    // Parités FIXES du franc CFA (BEAC/BCEAO) : 1 EUR = 655,957 XAF = 655,957 XOF.
    // Dernier recours quand ni l'API ni la base n'ont de taux.
    private const CFA_EUR_PARITY = 655.957;

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

        // 1) API live (mise en cache 6 h) — source de vérité.
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
            return $stored;
        }

        // 3) Dernier recours : parité fixe du franc CFA (taux officiel, pas deviné).
        return self::fixedParityRate($from, $to);
    }

    /**
     * Taux via l'API live exchangerate-api.com (caché 6 h). null si indisponible.
     */
    private static function liveRate(string $from, string $to): ?float
    {
        $cacheKey = "exrate_{$from}_{$to}";

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return (float) $cached;
        }

        // API en pause après un échec récent : ne pas la rappeler à chaque requête.
        if (self::apiInCooldown()) {
            return null;
        }

        $key = self::apiKey();
        if (empty($key)) {
            Log::warning('[ExchangeRateService] Clé API manquante');
            self::startCooldown(self::COOLDOWN_ERROR);
            return null;
        }

        try {
            $response = Http::timeout(15)
                ->get("https://v6.exchangerate-api.com/v6/{$key}/pair/{$from}/{$to}");
            $data = $response->json() ?? [];
            if ($response->successful() && ($data['result'] ?? null) === 'success') {
                $rate = (float) $data['conversion_rate'];
                Cache::put($cacheKey, $rate, self::CACHE_TTL);
                return $rate;
            }
            Log::warning('[ExchangeRateService] Réponse invalide', ['body' => $response->body()]);
            self::startCooldown(self::cooldownFor($response->status(), $data['error-type'] ?? null));
        } catch (\Exception $e) {
            Log::error('[ExchangeRateService] Exception', ['error' => $e->getMessage()]);
            self::startCooldown(self::COOLDOWN_ERROR);
        }

        return null;
    }

    /** L'API est-elle en pause suite à un échec récent ? */
    public static function apiInCooldown(): bool
    {
        return Cache::has(self::COOLDOWN_KEY);
    }

    private static function startCooldown(int $seconds): void
    {
        Cache::put(self::COOLDOWN_KEY, true, $seconds);
        Log::warning('[ExchangeRateService] Appels API suspendus', ['seconds' => $seconds]);
    }

    /** Durée de pause selon l'échec : longue si le quota est épuisé ou la clé refusée. */
    private static function cooldownFor(int $status, ?string $errorType): int
    {
        $quotaOrKey = in_array($errorType, ['quota-reached', 'invalid-key', 'inactive-account'], true);

        return ($status === 429 || $status === 403 || $quotaOrKey)
            ? self::COOLDOWN_QUOTA
            : self::COOLDOWN_ERROR;
    }

    /**
     * Taux par parité fixe du franc CFA (XAF/XOF ↔ EUR, XAF ↔ XOF). null sinon.
     */
    private static function fixedParityRate(string $from, string $to): ?float
    {
        $cfa = ['XAF', 'XOF'];

        if (in_array($from, $cfa, true) && in_array($to, $cfa, true)) {
            return 1.0;
        }
        if (in_array($from, $cfa, true) && $to === 'EUR') {
            return round(1 / self::CFA_EUR_PARITY, 8);
        }
        if ($from === 'EUR' && in_array($to, $cfa, true)) {
            return self::CFA_EUR_PARITY;
        }

        return null;
    }

    /** Devises par lesquelles un taux stocké peut passer (ex. GHS → XOF → XAF). */
    private const CROSS_CURRENCIES = ['XAF', 'XOF', 'USD', 'EUR'];

    /**
     * Taux stocké en base (`exchange_rates` actifs) : paire directe ou inverse,
     * sinon taux croisé via une devise intermédiaire (la sync ne stocke que
     * quelques devises de base). null si rien de configuré.
     */
    private static function storedRate(string $from, string $to): ?float
    {
        $pair = self::storedPairRate($from, $to);
        if ($pair !== null) {
            return $pair;
        }

        foreach (self::CROSS_CURRENCIES as $via) {
            if ($via === $from || $via === $to) {
                continue;
            }
            $first = self::storedPairRate($from, $via);
            $second = $first !== null ? self::storedPairRate($via, $to) : null;
            if ($second !== null) {
                return round($first * $second, 8);
            }
        }

        return null;
    }

    /** Paire stockée directe, ou inverse de la paire opposée. */
    private static function storedPairRate(string $from, string $to): ?float
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
        if (self::apiInCooldown()) {
            return null;
        }

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
            if (self::apiInCooldown()) {
                return ['success' => false, 'message' => 'API en pause (échec récent)'];
            }

            $apiKey = self::apiKey();
            if (!$apiKey) {
                return ['success' => false, 'message' => 'API key not configured'];
            }

            $response = Http::timeout(10)->get("{$this->getApiUrl()}/{$apiKey}/latest/{$baseCurrency}");
            $data = $response->json() ?? [];
            if (!$response->successful()) {
                self::startCooldown(self::cooldownFor($response->status(), $data['error-type'] ?? null));
                return ['success' => false, 'message' => "API error: {$response->status()}"];
            }

            if (($data['result'] ?? null) !== 'success') {
                self::startCooldown(self::cooldownFor($response->status(), $data['error-type'] ?? null));
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
        // XAF est la devise pivot des commandes et du Wallet : ses paires doivent
        // exister en base pour le secours quand l'API live est indisponible.
        $baseCurrencies = ['XAF', 'XOF', 'USD', 'EUR'];
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
