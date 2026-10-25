<?php

namespace Tests\Unit;

use App\Support\SafeSvg;
use PHPUnit\Framework\TestCase;

class SafeSvgTest extends TestCase
{
    public function test_static_paths_survive_while_active_content_and_external_resources_are_removed(): void
    {
        $svg = SafeSvg::sanitize('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" onload="alert(1)"><script>alert(1)</script><foreignObject><p>HTML</p></foreignObject><image href="https://example.com/x"/><path d="M0 0h2" fill="url(https://example.com/x)" style="display:block"/></svg>');
        $this->assertStringContainsString('viewBox="0 0 24 24"', $svg);
        $this->assertStringContainsString('d="M0 0h2"', $svg);
        foreach (['script', 'foreignObject', '<image', 'onload', 'style=', 'https://'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $svg);
        }
    }

    public function test_xml_entities_and_non_svg_markup_are_rejected(): void
    {
        $this->assertNull(SafeSvg::sanitize('<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg>&x;</svg>'));
        $this->assertNull(SafeSvg::sanitize('<img src="x"/>'));
    }
}
