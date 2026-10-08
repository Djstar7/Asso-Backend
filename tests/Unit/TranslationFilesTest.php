<?php

namespace Tests\Unit;

use Illuminate\Support\Arr;
use PHPUnit\Framework\TestCase;

/**
 * Chaque langue tient dans lang/<langue>.json : mêmes clés partout, aucune
 * valeur vide, mêmes paramètres :x.
 */
class TranslationFilesTest extends TestCase
{
    private function load(string $locale): array
    {
        $path = dirname(__DIR__, 2)."/lang/{$locale}.json";

        return Arr::dot(json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR));
    }

    private function params(string $value): array
    {
        preg_match_all('/:([a-zA-Z_]+)/', $value, $matches);
        $params = array_unique($matches[1]);
        sort($params);

        return $params;
    }

    public function test_french_and_english_have_the_same_keys(): void
    {
        $fr = $this->load('fr');
        $en = $this->load('en');

        $this->assertSame([], array_values(array_diff(array_keys($fr), array_keys($en))), 'absentes de en.json');
        $this->assertSame([], array_values(array_diff(array_keys($en), array_keys($fr))), 'absentes de fr.json');
    }

    public function test_values_are_filled_and_share_placeholders(): void
    {
        $fr = $this->load('fr');
        $en = $this->load('en');

        foreach ($fr as $key => $value) {
            $this->assertNotSame('', trim((string) $value), "fr: {$key} vide");
            $this->assertNotSame('', trim((string) ($en[$key] ?? '')), "en: {$key} vide");
            $this->assertSame($this->params((string) $value), $this->params((string) ($en[$key] ?? '')), "paramètres différents : {$key}");
        }
    }
}
