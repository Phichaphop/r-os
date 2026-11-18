<?php

declare(strict_types=1);

namespace Blue\Core\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Allow-list HTML cleaner for document templates (6.15): headings, paragraphs, lists, simple tables and inline
 * emphasis only. Scripts, styles, event handlers, links, images and unknown tags are removed — text inside unknown
 * inline tags is kept. Only the attributes below survive, with safe values.
 */
final class HtmlSanitizer
{
    private const TAGS = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'h1', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'div', 'span', 'hr', 'blockquote'];
    private const DROP_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'template', 'noscript', 'head', 'title', 'form', 'textarea', 'select'];
    private const CLASSES = ['text-center', 'text-right', 'text-justify', 'signature', 'small', 'muted', 'page-break', 'indent', 'bordered', 'no-border'];

    public static function clean(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="__root">' . $html . '</div>', LIBXML_NONET | LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $root = $doc->getElementById('__root');
        if ($root === null) {
            return '';
        }
        self::walk($root);
        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }
        return trim($out);
    }

    private static function walk(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                    $node->removeChild($child);
                    continue;
                }
                self::walk($child);
                if (!in_array($tag, self::TAGS, true)) {
                    while ($child->firstChild !== null) {
                        $node->insertBefore($child->firstChild, $child); // unwrap: keep the text
                    }
                    $node->removeChild($child);
                    continue;
                }
                self::attributes($child);
            } elseif ($child->nodeType === XML_COMMENT_NODE || $child->nodeType === XML_PI_NODE) {
                $node->removeChild($child);
            }
        }
    }

    private static function attributes(DOMElement $el): void
    {
        foreach (iterator_to_array($el->attributes ?? []) as $attr) {
            $name = strtolower($attr->name);
            $value = trim($attr->value);
            $keep = match ($name) {
                'class' => implode(' ', array_intersect(preg_split('/\s+/', $value) ?: [], self::CLASSES)),
                'colspan', 'rowspan' => preg_match('/^[1-9]\d?$/', $value) === 1 ? $value : '',
                default => '',
            };
            $el->removeAttribute($attr->name);
            if ($keep !== '') {
                $el->setAttribute($name, $keep);
            }
        }
    }
}
