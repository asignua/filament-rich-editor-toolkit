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
 * Only an iframe whose `src` passes {@see EmbedSource::allows()} becomes a node (after a
 * recognised video link is rebuilt to its built-in form, {@see EmbedIframe::canonicalSrc()}): the HTML goes
 * to the public site as stored, and the check in the "Embed" modal cannot see what was pasted
 * from the clipboard or typed in the source view.
 *
 * The same check runs AGAIN on render: JSON content (`RichEditor::json()`, an array handed to
 * the renderer, the editor state posted through Livewire) never passes through parseHTML(),
 * so a node built by hand could otherwise carry any `src`. A node that fails renders nothing.
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
                'getAttrs' => static fn (DOMElement $node): ?bool => EmbedSource::allows(EmbedIframe::canonicalSrc($node->getAttribute('src'))) ? null : false,
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function addAttributes(): array
    {
        return [
            // A recognised video link in another form (YouTube's own embed code) is rebuilt.
            'src' => [
                'parseHTML' => static fn (DOMElement $node): ?string => EmbedIframe::canonicalSrc($node->getAttribute('src')) ?: null,
            ],
            'width' => [],
            'height' => [],
            'title' => [],
        ];
    }

    /**
     * @param array<string, mixed> $HTMLAttributes
     *
     * @return array<int, mixed>|null
     */
    public function renderHTML(mixed $node, array $HTMLAttributes = []): ?array
    {
        $src = is_object($node) && isset($node->attrs) && is_object($node->attrs) ? ($node->attrs->src ?? null) : null;

        if (!is_string($src) || !EmbedSource::allows($src)) {
            return null;
        }

        return ['iframe', HTML::mergeAttributes(
            $this->options['HTMLAttributes'],
            $HTMLAttributes,
            EmbedIframe::hardening(),
        )];
    }
}
