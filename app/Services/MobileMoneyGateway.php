<?php

namespace App\Services;

/**
 * Rail « Mobile Money » unique pour toute l'app (wallet, commandes, diaspo,
 * forfaits, jobs de réconciliation) : délègue au prestataire ACTIF.
 *
 * Un seul prestataire est actif à la fois — KPay OU ElgioPay — l'activation de
 * l'un désactive l'autre (cf. SettingsController::updateServiceConfiguration).
 * ElgioPay ne sert que le Cameroun : quand il est actif, seuls MTN_MOMO_CMR et
 * ORANGE_CMR sont acceptés (allowedProviders(), exposé au mobile).
 *
 * Les ids ElgioPay sont renvoyés préfixés (`elgiopay:<id>`) : c'est la valeur
 * stockée par les appelants (provider_reference, payment_reference,
 * kpay_reference). Les vérifications de statut se routent sur ce préfixe, si bien
 * qu'une transaction lancée avant une bascule de prestataire continue d'être
 * suivie par celui qui l'a émise. Les ids KPay (pay_xxx / wdr_xxx) sont inchangés.
 *
 * Interface identique à KPayService (drop-in).
 */
class MobileMoneyGateway
{
    public const ELGIOPAY_PREFIX = 'elgiopay:';

    public function __construct(
        private KPayService $kpay,
        private ElgioPayService $elgiopay,
    ) {}

    /** 'elgiopay' | 'kpay' | null : prestataire recevant les nouveaux paiements. */
    public function activeGateway(): ?string
    {
        return match (true) {
            $this->elgiopay->isConfigured() => 'elgiopay',
            $this->kpay->isConfigured() => 'kpay',
            default => null,
        };
    }

    public function isConfigured(): bool
    {
        return $this->activeGateway() !== null;
    }

    /** Codes opérateurs acceptés par le prestataire actif. */
    public function allowedProviders(): array
    {
        return match ($this->activeGateway()) {
            'elgiopay' => array_keys(ElgioPayService::PROVIDER_METHODS),
            'kpay' => array_keys(KPayCatalog::PROVIDERS),
            default => [],
        };
    }

    /** Pays ISO3 couverts par le prestataire actif. */
    public function allowedCountries(): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($p) => KPayCatalog::countryForProvider($p),
            $this->allowedProviders()
        ))));
    }

    /** Prestataire ayant émis une référence stockée. */
    public static function gatewayOfReference(?string $reference): string
    {
        return str_starts_with((string) $reference, self::ELGIOPAY_PREFIX) ? 'elgiopay' : 'kpay';
    }

    public function initializePayment(array $params): array
    {
        return match ($this->activeGateway()) {
            'elgiopay' => $this->tag($this->elgiopay->initializePayment($params)),
            'kpay' => $this->kpay->initializePayment($params) + ['gateway' => 'kpay'],
            default => $this->unavailable(),
        };
    }

    public function initiateDisbursement(array $params): array
    {
        return match ($this->activeGateway()) {
            'elgiopay' => $this->tag($this->elgiopay->initiateDisbursement($params)),
            'kpay' => $this->kpay->initiateDisbursement($params) + ['gateway' => 'kpay'],
            default => $this->unavailable(),
        };
    }

    public function checkPaymentStatus(string $reference): array
    {
        if (self::gatewayOfReference($reference) === 'elgiopay') {
            return $this->elgiopay->checkPaymentStatus($this->strip($reference)) + ['gateway' => 'elgiopay'];
        }
        return $this->kpay->checkPaymentStatus($reference) + ['gateway' => 'kpay'];
    }

    public function checkDisbursementStatus(string $reference): array
    {
        if (self::gatewayOfReference($reference) === 'elgiopay') {
            return $this->elgiopay->checkDisbursementStatus($this->strip($reference)) + ['gateway' => 'elgiopay'];
        }
        return $this->kpay->checkDisbursementStatus($reference) + ['gateway' => 'kpay'];
    }

    private function tag(array $result): array
    {
        if (!empty($result['id'])) {
            $result['id'] = self::ELGIOPAY_PREFIX . $result['id'];
        }
        return $result + ['gateway' => 'elgiopay'];
    }

    private function strip(string $reference): string
    {
        return substr($reference, strlen(self::ELGIOPAY_PREFIX));
    }

    private function unavailable(): array
    {
        return ['success' => false, 'message' => "Le paiement Mobile Money n'est pas disponible pour le moment."];
    }
}
