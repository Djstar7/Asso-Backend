<?php

namespace Tests\Unit;

use App\Support\CountryFlag;
use Database\Seeders\WorldCurrencySeeder;
use PHPUnit\Framework\TestCase;

class CountryFlagTest extends TestCase
{
    public function test_resolves_french_and_english_names_and_iso_codes(): void
    {
        $this->assertSame('🇨🇲', CountryFlag::flag('Cameroun'));
        $this->assertSame('🇨🇲', CountryFlag::flag('Cameroon'));
        $this->assertSame('🇨🇲', CountryFlag::flag('cm'));
        $this->assertSame('🇨🇮', CountryFlag::flag("Côte d'Ivoire"));
        $this->assertSame('🇨🇮', CountryFlag::flag('Ivory Coast'));
        $this->assertSame('🇦🇪', CountryFlag::flag('Émirats arabes unis (Dubaï)'));
        $this->assertSame('🇹🇷', CountryFlag::flag('Türkiye'));
        $this->assertSame('🇺🇸', CountryFlag::flag('USA'));
        $this->assertSame('🇰🇷', CountryFlag::flag('Korea, Republic of'));
    }

    public function test_distinguishes_the_two_congos_and_the_guineas(): void
    {
        $this->assertSame('CG', CountryFlag::code('Congo'));
        $this->assertSame('CD', CountryFlag::code('République démocratique du Congo'));
        $this->assertSame('GN', CountryFlag::code('Guinée'));
        $this->assertSame('GQ', CountryFlag::code('Guinée équatoriale'));
        $this->assertSame('GW', CountryFlag::code('Guinea-Bissau'));
        $this->assertSame('PG', CountryFlag::code('Papua New Guinea'));
    }

    public function test_british_nations_use_tag_sequence_flags(): void
    {
        $this->assertSame("\u{1F3F4}\u{E0067}\u{E0062}\u{E0065}\u{E006E}\u{E0067}\u{E007F}", CountryFlag::flag('England'));
        $this->assertSame("\u{1F3F4}\u{E0067}\u{E0062}\u{E0073}\u{E0063}\u{E0074}\u{E007F}", CountryFlag::flag('Scotland'));
        $this->assertSame("\u{1F3F4}\u{E0067}\u{E0062}\u{E0077}\u{E006C}\u{E0073}\u{E007F}", CountryFlag::flag('Pays de Galles'));
        $this->assertSame('🇬🇧', CountryFlag::flag('Northern Ireland'));
    }

    public function test_unknown_or_empty_country_gives_null(): void
    {
        $this->assertNull(CountryFlag::flag(null));
        $this->assertNull(CountryFlag::flag('   '));
        $this->assertNull(CountryFlag::flag('Atlantide'));
        $this->assertNull(CountryFlag::code('ZZ'));
    }

    public function test_every_country_of_the_world_currency_catalogue_has_a_flag(): void
    {
        $currencies = (fn () => $this->currencies())->call(new WorldCurrencySeeder());

        $missing = collect($currencies)
            ->pluck('countries')
            ->flatten()
            ->unique()
            ->filter(fn (string $name) => CountryFlag::flag($name) === null)
            ->values()
            ->all();

        $this->assertSame([], $missing, 'Pays du catalogue sans drapeau : ' . implode(', ', $missing));
    }
}
