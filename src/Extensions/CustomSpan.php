<?php

declare(strict_types=1);

namespace Asignua\RichEditorToolkit\Extensions;

use DOMElement;
use Tiptap\Core\Mark;
use Tiptap\Utils\HTML;

/**
 * A bare `<span class="…">` in text.
 *
 * It needs a mark of its own because no existing one catches it: `textColor` takes only
 * `span.color`, and `textStyle` demands `style` and is not in Filament's list of PHP
 * extensions (`RichContentRenderer::getTipTapPhpExtensions()`) at all — such a span vanished
 * together with its class.
 *
 * The attributes arrive globally ({@see CustomAttributes}); this class holds only the parse
 * rule and the render. `span.color` is handed to its owner explicitly, otherwise two marks
 * would sit on the same text and the output would be a nested `<span><span>`. The same goes for every span
 * that carries `data-type` — Filament's mention and merge-tag nodes: the editor parses all MARK
 * rules before the NODE rules of the same priority, so without this the node would come back
 * as plain text with a mark (its `data-id` lost) after a copy-paste in the browser.
 *
 * A bare `<span>` without attributes is kept as well: in migrated markup it is often a CSS
 * hook on its own (`.bg-primary span { … }`), and losing the tag broke styles just like
 * losing a class did.
 */
class CustomSpan extends Mark
{
    /** @var string */
    public static $name = 'customSpan';

    /**
     * Below the default 100: specialised rules (`textColor`) must parse their span first.
     *
     * @var int
     */
    public static $priority = 50;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parseHTML(): array
    {
        return [
            [
                'tag' => 'span',
                // null = match, false = skip (the tiptap-php convention).
                'getAttrs' => static fn (DOMElement $node): mixed => $node->hasAttribute('data-type') || in_array(
                    'color',
                    preg_split('/\s+/', trim($node->getAttribute('class'))) ?: [],
                    true,
                ) ? false : null,
            ],
        ];
    }

    /**
     * @param object               $mark
     * @param array<string, mixed> $HTMLAttributes
     *
     * @return array<mixed>
     */
    public function renderHTML($mark, $HTMLAttributes = []): array
    {
        return ['span', HTML::mergeAttributes($HTMLAttributes), 0];
    }
}
