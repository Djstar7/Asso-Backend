<?php

namespace Tests\Unit;

use App\Support\CountryCode;
use PHPUnit\Framework\TestCase;

class CountryCodeTest extends TestCase
{
    public function test_lists_iso_countries_with_searchable_display_names(): void
    {
        $this->assertCount(250, CountryCode::NAMES);
        $this->assertSame('Inde', CountryCode::NAMES['IN']);
        $this->assertSame('Kosovo', CountryCode::NAMES['XK']);
        $this->assertSame('IN', CountryCode::normalize('Inde'));
    }

    public function test_keeps_existing_country_aliases_and_accepts_all_country_codes(): void
    {
        $this->assertSame('CM', CountryCode::normalize('Cameroun'));
        $this->assertSame('CG', CountryCode::normalize('Congo'));
        $this->assertSame('CD', CountryCode::normalize('République démocratique du Congo'));
        $this->assertSame('IN', CountryCode::normalize('in'));
        $this->assertNull(CountryCode::normalize('Atlantide'));
    }
}
