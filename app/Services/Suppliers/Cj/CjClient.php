<?php

namespace App\Services\Suppliers\Cj;

use App\Models\Supplier;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * Client HTTP de l'API CJdropshipping (API 2.0).
 *
 * - Jeton : obtenu avec la clé API, valable 180 jours, renouvelé avec le jeton
 *   de rafraîchissement ; redemandé une fois si CJ le refuse.
 * - Débit : CJ limite à N requêtes par seconde pour tout le compte (1 au niveau
 *   gratuit). Chaque appel réserve un créneau partagé entre tous les processus
 *   (file, planificateur, back-office) via le cache.
 * - « Trop de requêtes » et erreurs passagères : nouvelles tentatives espacées.
 */
class CjClient
{
    private const SLOT_KEY = 'suppliers:cj:next-slot';

    private const SLOT_LOCK = 'suppliers:cj:slot-lock';

    public function __construct(private Supplier $supplier) {}

    public function get(string $path, array $query = []): mixed
    {
        return $this->call('GET', $path, $query);
    }

    public function post(string $path, array $body = []): mixed
    {
        return $this->call('POST', $path, $body);
    }

    public function patch(string $path, array $body = []): mixed
    {
        return $this->call('PATCH', $path, $body);
    }

    public function delete(string $path, array $query = []): mixed
    {
        return $this->call('DELETE', $path, $query);
    }

    /** Appel authentifié : renvoie le champ `data` de la réponse CJ. */
    public function call(string $method, string $path, array $params = []): mixed
    {
        $retriedToken = false;
        $attempt = 0;

        while (true) {
            try {
                return $this->send($method, $path, $params, $this->accessToken());
            } catch (CjApiException $e) {
                if ($e->isTokenError() && ! $retriedToken) {
                    // Jeton révoqué ou expiré côté CJ : on repart de la clé API.
                    $retriedToken = true;
                    $this->forgetTokens();

                    continue;
                }
                if ($e->isRetryable() && $attempt < $this->retries()) {
                    $this->backoff(++$attempt);

                    continue;
                }
                throw $e;
            }
        }
    }

    /** Jeton valable, renouvelé ou redemandé si besoin. */
    public function accessToken(): string
    {
        $margin = (int) config('suppliers.cj.token_refresh_margin_hours', 24);
        $s = $this->supplier;

        if (filled($s->access_token) && $s->access_token_expires_at?->isAfter(now()->addHours($margin))) {
            return $s->access_token;
        }

        if (filled($s->refresh_token) && $s->refresh_token_expires_at?->isFuture()) {
            try {
                return $this->storeTokens($this->send('POST', 'authentication/refreshAccessToken', [
                    'refreshToken' => $s->refresh_token,
                ]));
            } catch (CjApiException $e) {
                if ($e->cjCode !== CjApiException::INVALID_REFRESH_TOKEN) {
                    throw $e;
                }
                // Jeton de rafraîchissement refusé : on repart de la clé API.
            }
        }

        if (! $s->hasApiKey()) {
            throw new CjApiException('Aucune clé API CJ enregistrée.');
        }

        return $this->storeTokens($this->send('POST', 'authentication/getAccessToken', [
            'apiKey' => $s->api_key,
        ]));
    }

    /** Oublie les jetons enregistrés (clé API changée, jeton refusé par CJ). */
    public function forgetTokens(): void
    {
        $this->supplier->forceFill([
            'access_token' => null,
            'access_token_expires_at' => null,
            'refresh_token' => null,
            'refresh_token_expires_at' => null,
        ])->save();
    }

    private function storeTokens(mixed $data): string
    {
        if (! is_array($data) || empty($data['accessToken'])) {
            throw new CjApiException('Réponse CJ sans jeton d’accès.');
        }

        $attributes = [
            'access_token' => $data['accessToken'],
            'access_token_expires_at' => $this->date($data['accessTokenExpiryDate'] ?? null) ?? now()->addDays(15),
            'refresh_token' => $data['refreshToken'] ?? $this->supplier->refresh_token,
            'refresh_token_expires_at' => $this->date($data['refreshTokenExpiryDate'] ?? null)
                ?? $this->supplier->refresh_token_expires_at,
        ];
        // openId n'est renvoyé qu'à la première obtention (pas au rafraîchissement).
        if (! empty($data['openId'])) {
            $attributes['account_id'] = (string) $data['openId'];
        }
        $this->supplier->forceFill($attributes)->save();

        return $data['accessToken'];
    }

    private function send(string $method, string $path, array $params, ?string $token = null): mixed
    {
        $this->waitForSlot();

        $request = Http::baseUrl(rtrim((string) config('suppliers.cj.base_url'), '/'))
            ->timeout((int) config('suppliers.cj.timeout', 20))
            ->acceptJson()
            ->asJson();
        if ($token !== null) {
            $request = $request->withHeaders(['CJ-Access-Token' => $token]);
        }

        $path = ltrim($path, '/');
        try {
            /** @var Response $response */
            $response = in_array($method, ['GET', 'DELETE'], true)
                ? $request->send($method, $path, ['query' => $params])
                : $request->send($method, $path, ['json' => $params]);
        } catch (ConnectionException $e) {
            throw new CjApiException('CJ injoignable : '.$e->getMessage(), CjApiException::READ_TIMEOUT);
        }

        $json = $response->json();
        $code = is_array($json) && isset($json['code']) ? (int) $json['code'] : null;

        if ($response->successful() && $code === 200 && ($json['result'] ?? $json['success'] ?? true)) {
            return $json['data'] ?? null;
        }

        $exception = new CjApiException(
            is_array($json) ? (string) ($json['message'] ?? 'Erreur CJ') : 'Réponse CJ illisible (HTTP '.$response->status().')',
            $code,
            is_array($json) ? ($json['requestId'] ?? null) : null,
            $response->status(),
        );
        Log::warning('[CJ] Appel refusé', [
            'path' => $path,
            'http' => $response->status(),
            'code' => $code,
            'message' => $exception->getMessage(),
            'request_id' => $exception->requestId,
        ]);

        throw $exception;
    }

    /**
     * Réserve le prochain créneau d'appel libre pour tout le compte CJ, puis
     * attend son heure. Le verrou ne couvre que la réservation, pas l'appel.
     */
    private function waitForSlot(): void
    {
        $qps = (int) config('suppliers.cj.requests_per_second', 1);
        if ($qps <= 0) {
            return;
        }
        $interval = 1 / $qps;

        $slot = Cache::lock(self::SLOT_LOCK, 5)->block(10, function () use ($interval) {
            $now = microtime(true);
            $slot = max($now, (float) Cache::get(self::SLOT_KEY, 0));
            Cache::put(self::SLOT_KEY, $slot + $interval, 60);

            return $slot;
        });

        $wait = $slot - microtime(true);
        if ($wait > 0) {
            Sleep::usleep((int) round($wait * 1_000_000));
        }
    }

    private function backoff(int $attempt): void
    {
        Sleep::for($attempt * 2)->seconds();
    }

    private function retries(): int
    {
        return (int) config('suppliers.cj.retries', 2);
    }

    private function date(?string $value): ?CarbonImmutable
    {
        if (! $value) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
