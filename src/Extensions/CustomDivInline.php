<?php

declare(strict_types=1);

namespace Asignua\RichEditorToolkit\Extensions;

use DOMElement;
use Tiptap\Core\Node;
use Tiptap\Utils\HTML;

/**
 * A `<div>` that holds inline content only (`<div class="row"><img> <span>…</span></div>`).
 *
 * The second div type exists because of a fundamental ProseMirror limit: a node is either
 * block-level (`content: 'block+'`) or inline (`'inline*'`), never both. With only the block
 * {@see CustomDiv}, inline content was wrapped in a `<p>`, the image and the text stopped being
 * direct children of the div, and flex/grid layouts from old sites broke silently.
 *
 * The split happens at PARSE time: `getAttrs` looks for a block descendant, so the two rules
 * are mutually exclusive and their order does not matter — only that both rank below `div.lead`
 * and `div[data-type=grid]`.
 */
class CustomDivInline extends Node
{
    /** @var string */
    public static $name = 'customDivInline';

    /** @var int */
    public static $priority = 50;

    /**
     * Tags whose presence inside makes a div a block container.
     *
     * @var list<string>
     */
    public const BLOCK_TAGS = [
        'address', 'article', 'aside', 'blockquote', 'details', 'div', 'dl', 'fieldset',
        'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'header', 'hr', 'iframe', 'li', 'main', 'nav', 'ol', 'p', 'pre', 'section', 'table', 'ul',
    ];

    public static function hasBlockChild(DOMElement $node): bool
    {
        foreach ($node->getElementsByTagName('*') as $descendant) {
            if (in_array(strtolower($descendant->nodeName), self::BLOCK_TAGS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parseHTML(): array
    {
        return [
            [
                'tag' => 'div',
                'getAttrs' => static fn (DOMElement $node): mixed => self::hasBlockChild($node) ? false : null,
            ],
        ];
    }

    /**
     * @param object               $node
     * @param array<string, mixed> $HTMLAttributes
     *
     * @return array<mixed>
     */
    public function renderHTML($node, $HTMLAttributes = []): array
    {
        return ['div', HTML::mergeAttributes($HTMLAttributes), 0];
    }
}
