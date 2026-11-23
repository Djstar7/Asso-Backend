<?php

namespace App\Services\Suppliers\Cj;

use RuntimeException;

/** Erreur renvoyée par l'API CJ (code CJ, message et requestId pour le support CJ). */
class CjApiException extends RuntimeException
{
    // Codes de l'annexe « Global Error Codes » de CJ.
    public const INVALID_TOKEN = 1600001;

    public const EMPTY_TOKEN = 1600002;

    public const INVALID_REFRESH_TOKEN = 1600003;

    public const API_DISABLED = 1600014;

    public const WRONG_API_KEY = 1600005;

    public const USER_NOT_FOUND = 1601000;

    public const TOO_MANY_REQUESTS = 1600200;

    public const QUOTA_EXHAUSTED = 1600201;

    public const SYSTEM_BUSY = 1600000;

    public const READ_TIMEOUT = 1600301;

    public function __construct(
        string $message,
        public readonly ?int $cjCode = null,
        public readonly ?string $requestId = null,
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct($message, $cjCode ?? 0);
    }

    /** Le jeton n'est plus valable : en redemander un peut suffire. */
    public function isTokenError(): bool
    {
        return in_array($this->cjCode, [self::INVALID_TOKEN, self::EMPTY_TOKEN], true);
    }

    /** Erreur passagère : la même requête peut être retentée un peu plus tard. */
    public function isRetryable(): bool
    {
        return $this->httpStatus === 429
            || in_array($this->cjCode, [self::TOO_MANY_REQUESTS, self::SYSTEM_BUSY, self::READ_TIMEOUT], true);
    }

    /** Message lisible par l'admin, avec la référence à donner au support CJ. */
    public function describe(): string
    {
        $text = $this->getMessage();
        if ($this->cjCode !== null) {
            $text .= " (code CJ {$this->cjCode})";
        }
        if ($this->requestId) {
            $text .= " — réf. {$this->requestId}";
        }

        return $text;
    }
}
