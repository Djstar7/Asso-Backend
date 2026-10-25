<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class GuideCapturesTest extends TestCase
{
    public function test_current_screenshots_match_their_annotations_and_cache_versions(): void
    {
        $root = dirname(__DIR__, 2).'/resources/guide';
        $manifest = json_decode(file_get_contents($root.'/captures.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(51, $manifest['shots']);
        $images = [];
        foreach ($manifest['shots'] as $key => $shot) {
            $this->assertSame('png', $shot['format'], $key);
            $this->assertMatchesRegularExpression('/^[a-zA-Z0-9_-]+$/', $shot['img']);
            $file = $root.'/img/'.$shot['img'].'.png';
            $this->assertFileExists($file);
            $dimensions = getimagesize($file);
            $this->assertSame([$shot['width'], $shot['height']], array_slice($dimensions, 0, 2), $key);
            $this->assertSame(1440, $shot['width']);
            $this->assertGreaterThanOrEqual(500, $shot['height']);
            $this->assertSame('image/png', $dimensions['mime']);
            $this->assertSame(substr(hash_file('sha256', $file), 0, 12), $shot['version'], $key);
            $this->assertNotEmpty($shot['heading']);
            $this->assertGreaterThanOrEqual(2, count($shot['marks']), $key);
            foreach ($shot['marks'] as [$x, $y, $width, $height, $title, $description]) {
                $this->assertGreaterThanOrEqual(0, $x, $key);
                $this->assertGreaterThanOrEqual(0, $y, $key);
                $this->assertGreaterThan(0, $width, $key);
                $this->assertGreaterThan(0, $height, $key);
                $this->assertLessThanOrEqual($shot['width'], $x + $width, $key);
                $this->assertLessThanOrEqual($shot['height'], $y + $height, $key);
                $this->assertNotEmpty($title);
                $this->assertNotEmpty($description);
            }
            $images[] = realpath($file);
        }
        $this->assertCount(51, array_unique($images));
        $this->assertSame([], glob($root.'/img/*.webp'), 'Outdated captures must not remain alongside replacements.');
    }
}
