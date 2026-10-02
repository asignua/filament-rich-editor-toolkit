<?php

declare(strict_types=1);

namespace Asignua\RichEditorToolkit\Extensions;

use Asignua\RichEditorToolkit\Support\EmbedIframe;
use Asignua\RichEditorToolkit\Support\EmbedSource;
use DOMElement;
use Tiptap\Core\Node;
use Tiptap\Utils\HTML;

/**
 * The `<iframe>` node for server-side rendering of rich content. Without it tiptap-php drops
 * the tag together with the video or map: everything missing from the schema is treated as
 * garbage and disappears on save.
 *
 * Only an iframe whose `src` passes {@see EmbedSource::allows()} becomes a node: the HTML goes
 * to the public site as stored, and the check in the "Embed" modal cannot see what was pasted
 * from the clipboard or typed in the source view.
 *
 * The hardening attributes (`sandbox`, `allow`, `referrerpolicy`, `loading`, `allowfullscreen`)
 * are forced on render and override whatever the stored HTML carried.
 */
class IframeNode extends Node
{
    /** @var string */
    public static $name = 'iframe';

    /**
     * @return array<string, mixed>
     */
    public function addOptions(): array
    {
        return [
            'HTMLAttributes' => [],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parseHTML(): array
    {
        return [
            [
                'tag' => 'iframe',
                // A disallowed source means no node at all, like any tag outside the schema.
                'getAttrs' => static fn (DOMElement $node): ?bool => EmbedSource::allows($node->getAttribute('src')) ? null : false,
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function addAttributes(): array
    {
        return [
            'src' => [],
            'width' => [],
            'height' => [],
            'title' => [],
        ];
    }

    /**
     * @param array<string, mixed> $HTMLAttributes
     *
     * @return array<int, mixed>
     */
    public function renderHTML(mixed $node, array $HTMLAttributes = []): array
    {
        return ['iframe', HTML::mergeAttributes(
            $this->options['HTMLAttributes'],
            $HTMLAttributes,
            EmbedIframe::hardening(),
        )];
    }
}
