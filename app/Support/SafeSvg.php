<?php

namespace App\Support;

use DOMDocument;
use DOMElement;

/** Static category icons only: no scripts, styles, animation or external resources. */
class SafeSvg
{
    public static function sanitize(?string $svg): ?string
    {
        if (! $svg) {
            return $svg;
        }
        if (strlen($svg) > 20000 || preg_match('/<!DOCTYPE|<!ENTITY/i', $svg)) {
            return null;
        }
        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            if (! $doc->loadXML($svg, LIBXML_NONET | LIBXML_NOBLANKS)) {
                return null;
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $root = $doc->documentElement;
        if (! $root || $root->localName !== 'svg') {
            return null;
        }
        $elements = ['svg', 'g', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'defs', 'linearGradient', 'radialGradient', 'stop', 'title', 'desc', 'text', 'tspan'];
        $attributes = ['viewBox', 'width', 'height', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'd', 'points', 'fill', 'fill-rule', 'fill-opacity', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-opacity', 'stroke-dasharray', 'stroke-dashoffset', 'opacity', 'transform', 'id', 'class', 'role', 'aria-hidden', 'aria-label', 'gradientUnits', 'gradientTransform', 'offset', 'stop-color', 'stop-opacity', 'preserveAspectRatio'];
        $clean = function (DOMElement $node) use (&$clean, $elements, $attributes): void {
            foreach (iterator_to_array($node->attributes) as $attribute) {
                $name = $attribute->nodeName;
                if (! in_array($name, $attributes, true)) {
                    $node->removeAttributeNode($attribute);
                } elseif (in_array($name, ['fill', 'stroke', 'stop-color'], true)
                    && ! preg_match('/^(?:none|currentColor|transparent|inherit|#[0-9a-f]{3,8}|[a-z]{1,20}|rgba?\([0-9.,% ]+\)|hsla?\([0-9.,% ]+\)|url\(#[a-z_][\w:.-]*\))$/i', trim($attribute->value))) {
                    $node->removeAttributeNode($attribute);
                }
            }
            foreach (iterator_to_array($node->childNodes) as $child) {
                if ($child instanceof DOMElement) {
                    if (! in_array($child->localName, $elements, true) || ($child->namespaceURI && $child->namespaceURI !== 'http://www.w3.org/2000/svg')) {
                        $node->removeChild($child);
                    } else {
                        $clean($child);
                    }
                }
            }
        };
        $clean($root);
        $root->setAttribute('xmlns', 'http://www.w3.org/2000/svg');

        return $doc->saveXML($root);
    }
}
