<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Recette du 16/10/2026 (A1) : le back-office affichait l'heure UTC, l'app
 * l'heure du Cameroun. Les dates restent en UTC, seul l'affichage est converti.
 */
class DisplayTimezoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_dates_are_displayed_in_cameroon_time(): void
    {
        $utc = Carbon::parse('2026-10-16 15:12:00', 'UTC');

        $this->assertSame('16/10/2026 16:12', $utc->displayTz()->format('d/m/Y H:i'));
        // La date d'origine n'est pas modifiée.
        $this->assertSame('UTC', $utc->timezoneName);
    }

    public function test_admin_timezone_setting_takes_precedence(): void
    {
        Setting::set('timezone', 'UTC', 'string', 'system');

        $this->assertSame('15:12', Carbon::parse('2026-10-16 15:12:00', 'UTC')->displayTz()->format('H:i'));
    }
}
