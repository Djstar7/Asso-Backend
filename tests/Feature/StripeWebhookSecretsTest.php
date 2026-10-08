<?php

namespace Tests\Feature;

use App\Models\ServiceConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Plusieurs endpoints Stripe (plateforme, Connect, extras) livrent la même URL,
 * chacun signé avec SON secret : tous doivent être acceptés, et eux seuls.
 */
class StripeWebhookSecretsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ServiceConfiguration::setConfig('stripe', [
            'mode' => 'test',
            'secret_key' => 'sk_test_x',
            'publishable_key' => 'pk_test_x',
            'webhook_secret' => 'whsec_platform',
            'webhook_secret_connect' => 'whsec_connect',
            'webhook_secrets_extra' => ['whsec_extra'],
        ], true);
    }

    private function signedPost(string $secret)
    {
        $payload = json_encode(['id' => 'evt_1', 'object' => 'event', 'type' => 'customer.created', 'data' => ['object' => []]]);
        $t = time();
        $signature = hash_hmac('sha256', "{$t}.{$payload}", $secret);

        return $this->call('POST', '/api/v1/stripe/webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$t},v1={$signature}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }

    public function test_every_configured_secret_is_accepted(): void
    {
        foreach (['whsec_platform', 'whsec_connect', 'whsec_extra'] as $secret) {
            $this->signedPost($secret)->assertOk()->assertJsonPath('received', true);
        }
    }

    public function test_unknown_secret_is_rejected(): void
    {
        $this->signedPost('whsec_unknown')->assertStatus(400);
    }
}
