<?php

declare(strict_types=1);

namespace Asignua\RichEditorToolkit\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * The plain text of rich-editor state, and its character and word counts.
 *
 * This is the PHP half of one definition; `resources/js/src/character-count.js` is the other,
 * and `tests/fixtures/text-length.json` is run against both:
 *
 * - Every text block (paragraph, heading, list item paragraph, table cell, code block ...) yields
 *   its text; a hard break inside it is "\n". Blocks are joined with "\n", empty blocks dropped.
 * - Atoms add nothing: images, embeds, custom blocks, mentions, merge tags.
 * - Characters are the Unicode code points of that text with every "\n" removed (so paragraph
 *   and line breaks are free; spaces count).
 * - Words are runs of non-whitespace. Whitespace is the explicit list in {@see self::WHITESPACE}
 *   ("\n" included), the same code points the JS uses: JS `\s` and PCRE `\s` disagree.
 *
 * The state is what a RichEditor field holds: an HTML string, or the TipTap JSON document
 * (an array) of a `->json()` field. HTML is read like the editor reads it: runs of ASCII
 * whitespace collapse to one space (except in `<pre>`), a block's leading and trailing spaces
 * go, and a no-break space is a character like any other.
 */
final class PlainText
{
    /** The class body of a PCRE character class: what separates words. */
    private const string WHITESPACE = ' \t\n\r\x{000C}\x{000B}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

    /** Elements whose start and end break the text, as blocks do in the editor schema. */
    private const array BLOCKS = [
        'address', 'article', 'aside', 'blockquote', 'caption', 'dd', 'details', 'div', 'dl', 'dt',
        'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'header', 'hr', 'li', 'main', 'nav', 'ol', 'p', 'pre', 'section', 'summary', 'table', 'tbody',
        'td', 'tfoot', 'th', 'thead', 'tr', 'ul',
    ];

    /**
     * Stands in for an atom (image, mention, custom block) while a block is being built. The
     * editor treats a space after an atom as significant ("Hi <mention> there" keeps both
     * spaces) although the atom itself adds no text; the marker keeps that rule and is removed
     * when the block is closed. A private-use code point never occurs in real content.
     */
    private const string ATOM = "\u{E000}";

    /** `data-type` values of atom nodes: their markup holds a preview or a label, not text. */
    private const array ATOM_TYPES = ['customBlock', 'mention', 'mergeTag'];

    /**
     * @return list<string> the text blocks, in order, none empty
     */
    public static function blocks(mixed $state): array
    {
        if (is_array($state)) {
            $blocks = [];
            self::walkJson($state, $blocks);

            return $blocks;
        }

        if (is_string($state) && trim($state) !== '') {
            return self::fromHtml($state);
        }

        return [];
    }

    /**
     * The blocks joined with "\n".
     */
    public static function from(mixed $state): string
    {
        return implode("\n", self::blocks($state));
    }

    public static function characters(string $text): int
    {
        return mb_strlen(str_replace("\n", '', $text), 'UTF-8');
    }

    public static function words(string $text): int
    {
        return (int) preg_match_all('/[^'.self::WHITESPACE.']+/u', $text);
    }

    /**
     * @param array<mixed> $node
     * @param list<string> $blocks
     */
    private static function walkJson(array $node, array &$blocks): void
    {
        $children = $node['content'] ?? null;

        if (!is_array($children)) {
            return;
        }

        $isTextBlock = false;

        foreach ($children as $child) {
            if (is_array($child) && in_array($child['type'] ?? null, ['text', 'hardBreak'], true)) {
                $isTextBlock = true;

                break;
            }
        }

        if (!$isTextBlock) {
            foreach ($children as $child) {
                if (is_array($child)) {
                    self::walkJson($child, $blocks);
                }
            }

            return;
        }

        $text = '';

        foreach ($children as $child) {
            if (!is_array($child)) {
                continue;
            }

            if (($child['type'] ?? null) === 'text' && is_string($child['text'] ?? null)) {
                $text .= $child['text'];
            } elseif (($child['type'] ?? null) === 'hardBreak') {
                $text .= "\n";
            }
        }

        if ($text !== '') {
            $blocks[] = $text;
        }
    }

    /**
     * @return list<string>
     */
    private static function fromHtml(string $html): array
    {
        $previous = libxml_use_internal_errors(true);

        $dom = new DOMDocument;
        $dom->loadHTML('<?xml encoding="utf-8"?><body>'.$html.'</body>', LIBXML_NOERROR | LIBXML_NOWARNING);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $dom->getElementsByTagName('body')->item(0);

        if (!$body instanceof DOMElement) {
            return [];
        }

        $blocks = [];
        $current = null;

        self::walkHtml($body, false, $blocks, $current);
        self::flush($blocks, $current);

        return $blocks;
    }

    /**
     * @param list<string> $blocks
     */
    private static function walkHtml(DOMNode $node, bool $preformatted, array &$blocks, ?string &$current): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                self::appendText($child->data, $preformatted, $current);

                continue;
            }

            if (!$child instanceof DOMElement) {
                continue;
            }

            $name = strtolower($child->tagName);

            if (in_array($name, ['script', 'style', 'template'], true)) {
                continue;
            }

            if ($name === 'img' || in_array($child->getAttribute('data-type'), self::ATOM_TYPES, true)) {
                $current = ($current ?? '').self::ATOM;

                continue;
            }

            if ($name === 'br') {
                $current = ($current ?? '')."\n";

                continue;
            }

            $isBlock = in_array($name, self::BLOCKS, true);

            if ($isBlock) {
                self::flush($blocks, $current);
            }

            self::walkHtml($child, $preformatted || $name === 'pre', $blocks, $current);

            if ($isBlock) {
                self::flush($blocks, $current);
            }
        }
    }

    private static function appendText(string $text, bool $preformatted, ?string &$current): void
    {
        if (!$preformatted) {
            $text = (string) preg_replace('/[ \t\r\n\x{000C}]+/u', ' ', $text);

            // The editor drops a leading space at the start of a block, after a line break and
            // after a space that is already there.
            if (str_starts_with($text, ' ') && ($current === null || $current === '' || str_ends_with($current, ' ') || str_ends_with($current, "\n"))) {
                $text = substr($text, 1);
            }
        }

        if ($text !== '') {
            $current = ($current ?? '').$text;
        }
    }

    /**
     * @param list<string> $blocks
     *
     * @param-out null     $current
     */
    private static function flush(array &$blocks, ?string &$current): void
    {
        if ($current !== null) {
            // The editor trims the trailing spaces of a block (not other whitespace, not a no-break space).
            $text = str_replace(self::ATOM, '', rtrim($current, ' '));

            if ($text !== '') {
                $blocks[] = $text;
            }
        }

        $current = null;
    }
}
