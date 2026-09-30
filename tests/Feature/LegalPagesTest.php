<?php

namespace Tests\Feature;

use App\Models\LegalPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    private function pages(): void
    {
        LegalPage::create(['slug' => 'cgu', 'title' => "Conditions Générales d'Utilisation", 'content' => '<p>Règles CGU</p>', 'is_active' => true, 'order' => 1]);
        LegalPage::create(['slug' => 'cgv', 'title' => 'Conditions Générales de Vente', 'content' => '<p>Règles CGV</p>', 'is_active' => true, 'order' => 2]);
        LegalPage::create(['slug' => 'brouillon', 'title' => 'Brouillon', 'content' => '<p>x</p>', 'is_active' => false, 'order' => 3]);
    }

    public function test_api_lists_only_active_pages_in_order_with_their_public_url(): void
    {
        $this->pages();

        $this->getJson('/api/v1/legal-pages')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.slug', 'cgu')
            ->assertJsonPath('data.1.url', route('legal.show', 'cgv'));
    }

    public function test_about_payload_carries_the_legal_pages(): void
    {
        $this->pages();

        $this->getJson('/api/v1/app/about')
            ->assertOk()
            ->assertJsonCount(2, 'about.legal.pages')
            ->assertJsonPath('about.legal.pages.1.title', 'Conditions Générales de Vente');
    }

    public function test_public_page_renders_content_and_hides_inactive_ones(): void
    {
        $this->pages();

        $this->get('/legal/cgv')->assertOk()->assertSee('Règles CGV', false)->assertSee("Conditions Générales d&#039;Utilisation", false);
        $this->get('/legal/brouillon')->assertNotFound();
        $this->getJson('/api/v1/legal-pages/brouillon')->assertNotFound();
        $this->getJson('/api/v1/legal-pages/cgu')->assertOk()->assertJsonPath('data.content', '<p>Règles CGU</p>');
    }
}
