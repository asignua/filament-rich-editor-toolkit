<?php

declare(strict_types=1);

namespace Asignua\RichEditorToolkit\Extensions;

use DOMElement;
use DOMNode;
use Filament\Forms\Components\RichEditor\TipTapExtensions\ImageExtension;
use Tiptap\Utils\HTML;

/**
 * Filament's `image` node plus three things its stock PHP node cannot do: an EMPTY `alt`
 * (a decorative image), a plain-text caption and an alignment.
 *
 * It SHADOWS the stock node (same name, higher priority) instead of replacing the schema:
 * tiptap-php sorts the extensions by priority, renders with the first node of a name and parses
 * with the first matching rule, so this class wins both.
 *
 * Everything it does not touch (`data-id`, `width`/`height`, `loading`, a media library's `src`)
 * comes from the image node the application BOUND for `ImageExtension` (`app(ImageExtension::class)`,
 * the one point through which Filament creates its node), not from the stock class: a site or a
 * media manager that rebinds it (resolving `src` from `data-id`, lazy loading, alt from a library)
 * keeps all of that, and this class only adds the empty alt, the caption and the wrapper on top.
 * Inheriting the stock class alone silently shadowed such a node.
 *
 * MARKUP. An image with neither caption nor alignment is the stock `<img>`, byte for byte. One
 * with either is
 *
 *     <span class="asignua-rte-figure asignua-rte-align-center"><img ...><span class="asignua-rte-caption">Text</span></span>
 *
 * and NOT `<figure>`/`<figcaption>`: Filament's image is an INLINE node, so the editor stores it as
 * `<p><img></p>`, and a `<figure>` inside a `<p>` is invalid (the parser closes the paragraph at
 * `<figure>` and leaves two empty ones). A `<figure>` typed in the source view or pasted is READ
 * (and rewritten to the span form), see {@see self::adopt()}.
 *
 * `alt=""` cannot go through `HTML::renderAttributes()` (it drops empty values), so a decorative
 * image is rendered by hand, through the `['content' => ...]` escape hatch of tiptap-php.
 *
 * The browser mirror is `resources/js/src/image-properties.js`; the class names, the alignments
 * and the "what is a wrapper" rule must agree (a test pins them).
 */
class ImageProperties extends ImageExtension
{
    /** @var int */
    public static $priority = 200;

    public const string FIGURE_CLASS = 'asignua-rte-figure';

    public const string CAPTION_CLASS = 'asignua-rte-caption';

    public const string ALIGN_CLASS_PREFIX = 'asignua-rte-align-';

    /** @var list<string> */
    public const array ALIGNMENTS = ['left', 'center', 'right'];

    /**
     * Where {@see self::adopt()} leaves what it read from the wrapper, for the attribute parsers
     * below (the same names as in the browser module).
     */
    private const string STASH_ALIGN = 'data-asignua-align';

    private const string STASH_CAPTION = 'data-asignua-caption';

    private const string STASH_ADOPTED = 'data-asignua-adopted';

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parseHTML(): array
    {
        return [
            ...$this->base()->parseHTML(),
            // Above the span mark, whose rule matches every <span>; nodes win over marks in
            // tiptap-php anyway, the priority is for the day that changes.
            [
                'tag' => 'figure',
                'priority' => 60,
                'getAttrs' => static fn (DOMElement $node): array|false => self::adopt($node) ? [] : false,
            ],
            [
                'tag' => 'span',
                'priority' => 60,
                'getAttrs' => static fn (DOMElement $node): array|false => self::hasClass($node, self::FIGURE_CLASS) && self::adopt($node) ? [] : false,
            ],
        ];
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function addAttributes(): array
    {
        return [
            ...$this->base()->addAttributes(),
            // A MISSING alt and an EMPTY one are different things (the second says "decorative"),
            // and `getAttribute()` returns '' for both.
            'alt' => [
                'parseHTML' => static fn (DOMElement $node): ?string => $node->hasAttribute('alt') ? $node->getAttribute('alt') : null,
            ],
            'caption' => [
                'rendered' => false,
                'parseHTML' => static fn (DOMElement $node): ?string => self::cleanCaption($node->getAttribute(self::STASH_CAPTION)),
            ],
            'align' => [
                'rendered' => false,
                'parseHTML' => static fn (DOMElement $node): ?string => self::cleanAlign($node->getAttribute(self::STASH_ALIGN) ?: $node->getAttribute('data-align')),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $HTMLAttributes
     *
     * @return array<int|string, mixed>
     */
    public function renderHTML(mixed $node, $HTMLAttributes = []): array
    {
        $attrs = is_object($node) && isset($node->attrs) && is_object($node->attrs) ? $node->attrs : null;

        $alt = $attrs->alt ?? null;
        $caption = self::cleanCaption($attrs->caption ?? null);
        $align = self::cleanAlign($attrs->align ?? null);

        // The common case, and the only one before this class existed: the bound node's output.
        if ($caption === null && $align === null && $alt !== '') {
            return $this->base()->renderHTML($node, $HTMLAttributes);
        }

        $img = $this->imageMarkup($this->base()->renderHTML($node, $HTMLAttributes), $alt);

        if ($caption === null && $align === null) {
            return ['content' => $img];
        }

        $class = self::FIGURE_CLASS.($align === null ? '' : ' '.self::ALIGN_CLASS_PREFIX.$align);

        return ['content' => '<span class="'.$class.'">'.$img
            .($caption === null ? '' : '<span class="'.self::CAPTION_CLASS.'">'.htmlspecialchars($caption, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</span>')
            .'</span>'];
    }

    /**
     * The image node the application bound for {@see ImageExtension} (Filament's own unless a
     * site or a media manager rebinds it); this class when nothing else is bound, to avoid a loop.
     */
    private function base(): ImageExtension
    {
        $bound = app(ImageExtension::class);

        if ($bound instanceof self) {
            return new ImageExtension($this->options);
        }

        return $bound;
    }

    /**
     * The `<img>` of the bound node's render spec as markup. The alt is the bound node's (it may
     * have resolved a better one, e.g. from a media library), except for a decorative image,
     * which always gets an empty `alt=""` (`HTML::renderAttributes()` drops empty values, and a
     * library node may have filled in a default).
     *
     * @param array<int|string, mixed> $spec
     */
    private function imageMarkup(array $spec, mixed $alt): string
    {
        if (isset($spec['content']) && is_string($spec['content'])) {
            // A node that renders by hand: only the decorative alt has to be forced.
            if ($alt !== '') {
                return $spec['content'];
            }

            $withoutAlt = (string) preg_replace('/\salt=("[^"]*"|\'[^\']*\')/', '', $spec['content'], 1);

            return (string) preg_replace('/^<img\b/i', '<img alt=""', $withoutAlt, 1);
        }

        /** @var array<string, mixed> $attributes */
        $attributes = isset($spec[1]) && is_array($spec[1]) ? $spec[1] : [];

        if ($alt === '') {
            unset($attributes['alt']);
        }

        $tag = isset($spec[0]) && is_string($spec[0]) ? $spec[0] : 'img';

        return '<'.$tag.HTML::renderAttributes($attributes).($alt === '' ? ' alt=""' : '').'>';
    }

    public static function cleanAlign(mixed $value): ?string
    {
        return is_string($value) && in_array($value, self::ALIGNMENTS, true) ? $value : null;
    }

    /**
     * Plain text, whitespace collapsed; nothing else is ever stored (the markup is escaped on
     * render, this is for the editor: no line breaks inside a one-line caption).
     */
    public static function cleanCaption(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $value === '' ? null : $value;
    }

    /**
     * Turns a wrapper (`<figure>` or our span) into the element the stock `img` rules and the
     * attribute parsers already understand: the wrapper ends up carrying the IMAGE's attributes
     * plus what it said about itself (alignment, caption) under {@see self::STASH_ALIGN} /
     * {@see self::STASH_CAPTION}, and has no children.
     *
     * Removing the children is the point: tiptap-php would otherwise nest the inner `<img>` and
     * the caption text under the node as `content`, and that goes into the document JSON the
     * editor receives.
     *
     * A wrapper is accepted only when it holds exactly one `<img>` and, besides it, only a
     * `<figcaption>` or our caption span: a `<figure>` around a table or a quote is not an image
     * and is left to the generic handling. The class, style and data attributes of the wrapper are
     * dropped, the image's own are kept.
     *
     * tiptap-php asks the rule more than once for the same node, so a second call is a no-op.
     */
    private static function adopt(DOMElement $wrapper): bool
    {
        if ($wrapper->hasAttribute(self::STASH_ADOPTED)) {
            return true;
        }

        $found = self::singleImageOf($wrapper);

        if ($found === null) {
            return false;
        }

        [$image, $caption] = $found;

        $align = self::alignOf($wrapper);

        $attributes = [];

        foreach ($image->attributes as $attribute) {
            $attributes[$attribute->nodeName] = $attribute->nodeValue;
        }

        foreach (array_map(static fn ($attribute): string => $attribute->nodeName, iterator_to_array($wrapper->attributes)) as $name) {
            $wrapper->removeAttribute($name);
        }

        while ($wrapper->firstChild !== null) {
            $wrapper->removeChild($wrapper->firstChild);
        }

        foreach ($attributes as $name => $value) {
            $wrapper->setAttribute($name, (string) $value);
        }

        $wrapper->setAttribute(self::STASH_ADOPTED, '1');
        $wrapper->setAttribute(self::STASH_ALIGN, $align ?? '');
        $wrapper->setAttribute(self::STASH_CAPTION, self::cleanCaption($caption) ?? '');

        return true;
    }

    /**
     * Is this element the CAPTION of an image wrapper, i.e. text that the editor stores as an
     * attribute of the image and never as text? True for a `<figcaption>` / `.asignua-rte-caption`
     * whose parent is a `<figure>` or a `span.asignua-rte-figure` that {@see self::adopt()} would
     * accept (exactly one image plus this caption). A caption-looking element anywhere else is
     * ordinary content. Reads only, changes nothing.
     */
    public static function isWrapperCaption(DOMElement $node): bool
    {
        if (strtolower($node->nodeName) !== 'figcaption' && !self::hasClass($node, self::CAPTION_CLASS)) {
            return false;
        }

        $parent = $node->parentNode;

        if (!$parent instanceof DOMElement) {
            return false;
        }

        $isWrapperTag = strtolower($parent->nodeName) === 'figure'
            || (strtolower($parent->nodeName) === 'span' && self::hasClass($parent, self::FIGURE_CLASS));

        if (!$isWrapperTag) {
            return false;
        }

        $found = self::singleImageOf($parent);

        return $found !== null && $found[1] !== null && $node->textContent === $found[1];
    }

    /**
     * The one image of a wrapper and its caption text (null when it has none), or null when the
     * element is not a single-image wrapper: exactly one `<img>` (with a src or a data-id) and,
     * besides it, only blank text and ONE `<figcaption>` / caption span.
     *
     * @return array{0: DOMElement, 1: string|null}|null
     */
    private static function singleImageOf(DOMElement $wrapper): ?array
    {
        $images = $wrapper->getElementsByTagName('img');

        if ($images->length !== 1) {
            return null;
        }

        /** @var DOMElement $image */
        $image = $images->item(0);

        if (!$image->hasAttribute('src') && !$image->hasAttribute('data-id')) {
            return null;
        }

        $caption = null;

        foreach ($wrapper->childNodes as $child) {
            if (self::isBlankText($child) || $child === $image) {
                continue;
            }

            if ($child instanceof DOMElement && $caption === null && (strtolower($child->nodeName) === 'figcaption' || self::hasClass($child, self::CAPTION_CLASS))) {
                $caption = $child->textContent;

                continue;
            }

            return null;
        }

        return [$image, $caption];
    }

    /**
     * From the wrapper's `asignua-rte-align-*` class, or its `data-align`.
     */
    private static function alignOf(DOMElement $wrapper): ?string
    {
        foreach (preg_split('/\s+/', trim($wrapper->getAttribute('class'))) ?: [] as $class) {
            if (str_starts_with($class, self::ALIGN_CLASS_PREFIX) && ($align = self::cleanAlign(substr($class, strlen(self::ALIGN_CLASS_PREFIX)))) !== null) {
                return $align;
            }
        }

        return self::cleanAlign($wrapper->getAttribute('data-align'));
    }

    private static function hasClass(DOMElement $node, string $class): bool
    {
        return in_array($class, preg_split('/\s+/', trim($node->getAttribute('class'))) ?: [], true);
    }

    private static function isBlankText(DOMNode $node): bool
    {
        return $node->nodeType === XML_TEXT_NODE && trim((string) $node->nodeValue) === '';
    }
}
