<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Dispute;
use App\Models\DisputeEvent;
use App\Models\OrderTrackingEvent;
use App\Models\Package;
use App\Models\User;
use App\Services\FirebaseMessagingService;
use App\Services\OrderTrackingService;
use App\Support\Translation\StoredLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Libellés et messages produits par le serveur, dans la langue de la requête. */
class ServerLabelsLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_labels_follow_the_locale_and_stay_identical_in_french(): void
    {
        app()->setLocale('fr');
        $this->assertSame('En analyse', Dispute::statusLabel('in_review'));
        $this->assertSame('Remis au transporteur', OrderTrackingService::stepLabel('handed_to_carrier'));

        app()->setLocale('en');
        $this->assertSame('Under review', Dispute::statusLabel('in_review'));
        $this->assertSame('Handed over to the carrier', OrderTrackingService::stepLabel('handed_to_carrier'));
        $this->assertSame('unknown', Dispute::statusLabel('unknown'));
    }

    public function test_events_stored_in_french_are_shown_in_english(): void
    {
        app()->setLocale('en');

        $this->assertSame('Under review by ASSO', StoredLabel::translate('Analyse par ASSO', ['disputes.events']));
        $this->assertSame('Courier selected', StoredLabel::translate('Livreur sélectionné', ['disputes.events', 'disputes.shipment_steps']));
        $this->assertSame('Texte libre', StoredLabel::translate('Texte libre', ['disputes.events']));

        $event = new DisputeEvent(['type' => 'in_review', 'label' => 'Analyse par ASSO']);
        $this->assertSame('Under review by ASSO', $event->toApi()['label']);

        $tracking = new OrderTrackingEvent(['step' => 'customs', 'label' => 'En dédouanement']);
        $this->assertSame('In customs clearance', $tracking->toApi()['label']);
    }

    public function test_package_duration_and_storage_are_localized(): void
    {
        $package = new Package(['duration_days' => 90, 'storage_size_mb' => 2048]);

        app()->setLocale('fr');
        $this->assertSame('3 mois', $package->formatted_duration);
        $this->assertSame('2.0 Go', $package->formatted_storage_size);

        app()->setLocale('en');
        $this->assertSame('3 months', $package->formatted_duration);
        $this->assertSame('2.0 GB', $package->formatted_storage_size);
    }

    public function test_sales_code_message_is_translated(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->withHeader('Accept-Language', 'en')
            ->getJson('/api/v1/sales-codes/NOPE')
            ->assertNotFound()
            ->assertJsonPath('message', 'This sales code does not exist or is no longer active. Check it or leave the field empty.');
    }

    public function test_announcement_goes_out_in_each_language(): void
    {
        $sends = [];
        $this->partialMock(FirebaseMessagingService::class, function ($mock) use (&$sends) {
            $mock->shouldReceive('sendToTopic')->andReturnUsing(function (string $topic, string $title, string $body) use (&$sends) {
                $sends[$topic] = [$title, $body];

                return ['success' => true];
            });
        });

        $english = User::factory()->create(['locale' => 'en']);
        $announcement = Announcement::create(['title' => 'Promo', 'message' => 'Soldes ce week-end', 'channel' => 'push', 'target_type' => 'all', 'status' => 'draft']);
        $announcement->syncTranslations(['en' => ['message' => 'Sale this weekend']]);

        app(FirebaseMessagingService::class)->sendToAllLocalized([
            'fr' => ['Promo', 'Soldes ce week-end'],
            'en' => ['Promo', 'Sale this weekend'],
        ], ['type' => 'announcement']);

        $this->assertSame(['Promo', 'Soldes ce week-end'], $sends['all_users']);
        $this->assertSame(['Promo', 'Sale this weekend'], $sends['all_users_en']);
        $this->assertDatabaseHas('notifications', ['user_id' => $english->id, 'body' => 'Sale this weekend']);
    }
}
