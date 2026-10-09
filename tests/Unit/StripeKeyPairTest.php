<?php

namespace Tests\Unit;

use App\Services\StripeService;
use PHPUnit\Framework\TestCase;

/**
 * Cohérence des clés Stripe et classification des erreurs Connect : une pk_test_
 * avec une sk_live_ empêche la Payment Sheet mobile de s'ouvrir, sans aucune
 * erreur côté serveur.
 */
class StripeKeyPairTest extends TestCase
{
    public function test_matching_pairs_are_accepted(): void
    {
        $this->assertNull(StripeService::keyPairProblem('sk_live_abc', 'pk_live_abc'));
        $this->assertNull(StripeService::keyPairProblem('sk_test_abc', 'pk_test_abc'));
    }

    public function test_mixed_modes_and_missing_publishable_key_are_rejected(): void
    {
        $this->assertNotNull(StripeService::keyPairProblem('sk_live_abc', 'pk_test_abc'));
        $this->assertNotNull(StripeService::keyPairProblem('sk_test_abc', 'pk_live_abc'));
        $this->assertNotNull(StripeService::keyPairProblem('sk_live_abc', ''));
        $this->assertNotNull(StripeService::keyPairProblem(null, 'pk_live_abc'));
    }

    public function test_account_created_in_another_mode_is_detected_as_missing(): void
    {
        $this->assertTrue(StripeService::isMissingAccountError(new \RuntimeException(
            "The provided key 'sk_live_***' does not have access to account 'acct_1U8P0SQGlixLggdg' (or that account does not exist)."
        )));
        $this->assertFalse(StripeService::isMissingAccountError(new \RuntimeException('Network timeout')));
    }

    public function test_platform_configuration_errors_are_recognised(): void
    {
        $this->assertTrue(StripeService::isPlatformConfigError(new \RuntimeException(
            'You must complete your platform profile to use Connect and create live connected accounts.'
        )));
        $this->assertTrue(StripeService::isPlatformConfigError(new \RuntimeException(
            'You must use a test bank account number. Try FR1420041010050500013M02606.'
        )));
        $this->assertFalse(StripeService::isPlatformConfigError(new \RuntimeException('Invalid IBAN provided.')));
    }
}
