<?php

namespace Tests\Unit;

use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Joignabilité des URL de webhook.
 *
 * Stripe ACCEPTE de créer un endpoint vers `http://192.168.x.x:8000` sans broncher,
 * mais ne l'appellera jamais : les retraits resteraient bloqués en « processing »
 * alors que tout semble configuré. Ce test fige le filtre.
 */
class StripeWebhookUrlTest extends TestCase
{
    use RefreshDatabase;

    private function service(): StripeService
    {
        // Le constructeur lit la configuration en base ; aucune clé n'est nécessaire ici.
        return app(StripeService::class);
    }

    /** @dataProvider unreachableUrls */
    public function test_rejects_unreachable_urls(string $url): void
    {
        $this->assertFalse($this->service()->isPubliclyReachableUrl($url), $url);
    }

    public static function unreachableUrls(): array
    {
        return [
            'IP privée' => ['http://192.168.43.73:8000/api/v1/stripe/webhook'],
            'loopback IP' => ['http://127.0.0.1:8000/api/v1/stripe/webhook'],
            'localhost' => ['http://localhost:8000/api/v1/stripe/webhook'],
            'domaine .test' => ['https://asso.test/api/v1/stripe/webhook'],
            'domaine .local' => ['https://asso.local/api/v1/stripe/webhook'],
            'sans domaine' => ['https://serveur/api/v1/stripe/webhook'],
            'schéma invalide' => ['ftp://asso.example.com/webhook'],
            'pas une URL' => ['pas-une-url'],
        ];
    }

    /** @dataProvider reachableUrls */
    public function test_accepts_public_urls(string $url): void
    {
        $this->assertTrue($this->service()->isPubliclyReachableUrl($url), $url);
    }

    public static function reachableUrls(): array
    {
        return [
            'https public' => ['https://api.mon-asso.com/api/v1/stripe/webhook'],
            'http public' => ['http://api.mon-asso.com/api/v1/stripe/webhook'],
            'tunnel ngrok' => ['https://a1b2c3.ngrok-free.app/api/v1/stripe/webhook'],
        ];
    }
}
