<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Catalogue des moyens de paiement ENTRANTS (encaissement) de l'app.
 *
 * Source de vérité unique pour « quels moyens afficher, dans quel ordre, avec
 * quel minimum et quelle devise cible ». Consommé par PaymentController::methods
 * (API mobile) et réutilisable par n'importe quel flux de paiement (réservation
 * Diaspo, achat produit, packages…).
 *
 * Règles produit (cf. demande) :
 *  - On expose TOUS les moyens, jamais masqués selon le pays de l'utilisateur.
 *  - Un moyen est simplement « indisponible » (grisé côté mobile) si le montant
 *    est inférieur à son minimum, ou s'il n'est pas activé/configuré par l'admin.
 *  - Les minimums sont pilotés par l'admin (table settings, groupe `payment`),
 *    stockés dans la devise pivot XAF puis convertis à la volée.
 *  - Aucun solde de portefeuille n'intervient ici : ce sont des rails directs.
 *
 * Activation (« enabled ») = source de vérité réelle de chaque rail :
 *  - kpay   : MobileMoneyGateway::isConfigured() — rail Mobile Money servi par le
 *             prestataire actif (KPay OU ElgioPay, exclusifs)
 *  - stripe : Setting `stripe_enabled` ET clés Stripe présentes (carte NATIVE, PaymentIntent)
 *
 * NB : le rail PayPal a été RETIRÉ (encaissement + retrait). Les colonnes de solde
 * PayPal restent en base pour un traitement admin ultérieur, mais aucun rail PayPal
 * n'est plus exposé au mobile.
 */
class PaymentMethodService
{
    /** Devise pivot des minimums stockés en base. */
    public const PIVOT = 'XAF';

    /**
     * Définition statique des rails. `flow` pilote le sous-parcours mobile :
     *   - phone    : sélecteur pays→opérateur→numéro (KPay Mobile Money)
     *   - card     : Payment Sheet (Stripe PaymentIntent natif, SDK flutter_stripe)
     */
    private const RAILS = [
        'kpay' => [
            // Libellé destiné à l'utilisateur : le nom du prestataire (KPay)
            // ne lui parle pas, le moyen de paiement si.
            'label' => 'Mobile Money',
            'subtitle' => 'Orange Money, MTN MoMo, Airtel…',
            'flow' => 'phone',
            'setting_min' => 'pay_min_kpay',
            'default_min' => 100.0,
        ],
        'stripe' => [
            'label' => 'Carte bancaire',
            'subtitle' => 'Visa, Mastercard, Amex',
            'flow' => 'card',
            'setting_min' => 'pay_min_stripe',
            'default_min' => 300.0, // ≈ 0.50 USD
        ],
    ];

    /**
     * Liste des moyens pour un montant donné, avec disponibilité et conversion.
     *
     * @param float  $amount   Montant à payer, exprimé dans $currency.
     * @param string $currency Devise du montant (généralement la devise de l'offre).
     * @return array<int, array<string, mixed>>
     */
    public static function forAmount(float $amount, string $currency): array
    {
        $currency = strtoupper($currency ?: self::PIVOT);
        $amountInPivot = self::toPivot($amount, $currency);

        $methods = [];
        foreach (self::RAILS as $code => $rail) {
            $enabled = self::isEnabled($code);
            $minPivot = (float) Setting::get($rail['setting_min'], $rail['default_min']);

            // Devise cible d'encaissement (fixe pour paypal/stripe, variable pour kpay).
            $targetCurrency = self::currencyFor($code);

            // Minimum affiché dans la devise du montant.
            $minInCurrency = self::fromPivot($minPivot, $currency);

            // Montant converti dans la devise du rail (null pour kpay : décidé à
            // l'étape opérateur), via les taux de change stockés/live.
            $convertedAmount = null;
            if ($targetCurrency !== null) {
                $convertedAmount = ($targetCurrency === $currency)
                    ? $amount
                    : ExchangeRateService::convertAmount($currency, $targetCurrency, $amount);
            }

            $meetsMin = $amountInPivot !== null ? ($amountInPivot >= $minPivot) : true;
            $available = $enabled && $meetsMin;

            $reason = null;
            if (!$enabled) {
                $reason = 'disabled';
            } elseif (!$meetsMin) {
                $reason = 'below_min';
            }

            $method = [
                'code' => $code,
                'label' => $rail['label'],
                'subtitle' => $rail['subtitle'],
                'flow' => $rail['flow'],
                'enabled' => $enabled,
                'available' => $available,
                'unavailable_reason' => $reason,
                'min_amount' => $minInCurrency !== null ? round($minInCurrency, 2) : null,
                'min_currency' => $currency,
                'target_currency' => $targetCurrency,
                'converted_amount' => $convertedAmount !== null ? round($convertedAmount, 2) : null,
            ];

            if ($code === 'kpay') {
                $method = array_merge($method, self::mobileMoneyScope());
            }

            $methods[] = $method;
        }

        return $methods;
    }

    /**
     * Option « Wallet ASSO » (paiement depuis le solde) pour un utilisateur connecté.
     *
     * N'est ajoutée que par les parcours qui l'acceptent côté serveur (commandes,
     * forfaits) via `include_wallet=1`. Toujours listée (jamais masquée) : grisée avec
     * le motif `insufficient_balance` si le solde disponible ne couvre pas le montant.
     */
    public static function walletOption(\App\Models\User $user, float $amount, string $currency): array
    {
        $currency = strtoupper($currency ?: self::PIVOT);
        $amountInPivot = self::toPivot($amount, $currency);
        $available = $user->kpayAvailableFor(self::PIVOT);
        $enough = $amountInPivot !== null && $available >= $amountInPivot;

        return [
            'code' => 'wallet',
            'label' => 'Wallet ASSO',
            'subtitle' => 'Payer avec mon solde',
            'flow' => 'wallet',
            'enabled' => true,
            'available' => $enough,
            'unavailable_reason' => $enough ? null : 'insufficient_balance',
            'min_amount' => null,
            'min_currency' => $currency,
            'target_currency' => self::PIVOT,
            'converted_amount' => $amountInPivot !== null ? round($amountInPivot, 2) : null,
            'balance' => round($available, 2),
            'missing_amount' => $enough || $amountInPivot === null ? 0 : round($amountInPivot - $available, 2),
        ];
    }

    /**
     * Périmètre du rail Mobile Money selon le prestataire ACTIF (un seul à la fois) :
     * le mobile ne propose que ces opérateurs / pays.
     *   gateway   : 'kpay' | 'elgiopay' | null
     *   providers : codes opérateurs acceptés (ex. MTN_MOMO_CMR)
     *   countries : pays ISO3 couverts (ex. CMR)
     */
    public static function mobileMoneyScope(): array
    {
        $gateway = app(MobileMoneyGateway::class);
        $active = $gateway->activeGateway();

        return [
            'gateway' => $active,
            'providers' => $gateway->allowedProviders(),
            'countries' => $gateway->allowedCountries(),
        ] + ($active === 'elgiopay' ? ['subtitle' => 'MTN MoMo, Orange Money (Cameroun)'] : []);
    }

    /** Minimum (pivot XAF) d'un rail, pour la validation serveur d'un paiement. */
    public static function minPivotFor(string $code): float
    {
        $rail = self::RAILS[$code] ?? null;
        return $rail ? (float) Setting::get($rail['setting_min'], $rail['default_min']) : 0.0;
    }

    /**
     * Un rail est-il activé ET configuré ? (source de vérité réelle par rail).
     */
    public static function isEnabled(string $code): bool
    {
        return match ($code) {
            'kpay' => app(MobileMoneyGateway::class)->isConfigured(),
            'stripe' => (bool) Setting::get('stripe_enabled', false) && (new StripeService())->isConfigured(),
            default => false,
        };
    }

    /** Devise d'encaissement d'un rail (null pour kpay : dépend de l'opérateur). */
    public static function currencyFor(string $code): ?string
    {
        return match ($code) {
            // ElgioPay n'encaisse qu'en XAF ; KPay : devise de l'opérateur choisi.
            'kpay' => app(MobileMoneyGateway::class)->activeGateway() === 'elgiopay' ? ElgioPayService::CURRENCY : null,
            'stripe' => strtoupper((string) Setting::get('stripe_currency', 'USD')),
            default => null,
        };
    }

    private static function toPivot(float $amount, string $currency): ?float
    {
        if (strtoupper($currency) === self::PIVOT) {
            return $amount;
        }
        return ExchangeRateService::convertAmount($currency, self::PIVOT, $amount);
    }

    private static function fromPivot(float $amount, string $currency): ?float
    {
        if (strtoupper($currency) === self::PIVOT) {
            return $amount;
        }
        return ExchangeRateService::convertAmount(self::PIVOT, $currency, $amount);
    }
}
