<?php

declare(strict_types=1);

namespace Asignua\RichEditorToolkit\Extensions;

use DOMElement;
use Tiptap\Core\Node;
use Tiptap\Utils\HTML;

/**
 * A generic `<div>` container.
 *
 * The stock schema has none: Filament describes only `div.lead` (LeadExtension) and
 * `div[data-type=grid]` (GridExtension), so every other `<div>` was unwrapped into paragraphs
 * together with its class.
 *
 * The priority is LOWER than the default 100: both specialised rules parse the same tag and
 * must win, otherwise `lead` and the grid would turn into plain divs.
 *
 * Takes only divs with block descendants — inline ones belong to {@see CustomDivInline}. The
 * split exists because a ProseMirror node is either block-level or inline: one type cannot
 * cover both `<div><p>…</p></div>` and `<div><img> <span>…</span></div>`.
 */
class CustomDiv extends Node
{
    /** @var string */
    public static $name = 'customDiv';

    /** @var int */
    public static $priority = 50;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parseHTML(): array
    {
        return [
            [
                'tag' => 'div',
                'getAttrs' => static fn (DOMElement $node): mixed => CustomDivInline::hasBlockChild($node) ? null : false,
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
