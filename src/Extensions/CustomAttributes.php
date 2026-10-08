<?php

declare(strict_types=1);

namespace Asignua\RichEditorToolkit\Extensions;

use DOMElement;
use Tiptap\Core\Extension;

/**
 * Keeps `class`, `id`, `style` (and any further allow-listed attribute) that an editor wrote
 * by hand in the source view — the behaviour of the old sites.
 *
 * Without this extension attributes disappear SILENTLY and twice: in the browser (the TipTap
 * schema keeps only declared attributes — that is why only `text-align` survived on a `<p>`,
 * it is an attribute of the node, not "an attribute that got through") and on the server,
 * where `RichEditorStateCast::set()` runs the HTML through tiptap-php once more.
 *
 * The sanitizer is a separate matter: Filament allows `class`/`style` on `*`, and `id` is in
 * Symfony's safe set. Names beyond those three need
 * {@see \Asignua\RichEditorToolkit\RichEditorToolkit::applySanitizerAllowances()}.
 *
 * The browser mirror is `resources/js/src/custom-attributes.js`. The node type lists and the
 * owned classes/styles must agree: a mismatch keeps the attribute on the server and loses it
 * the first time the editor is opened (or vice versa).
 */
class CustomAttributes extends Extension
{
    /** @var string */
    public static $name = 'asignuaCustomAttributes';

    /**
     * Nodes and marks that receive the attributes. A type missing here loses them silently.
     *
     * A name the node declares itself wins over the global one (tiptap-php and TipTap merge
     * the node's attributes last): `image` owns `id` (Filament's media key, `data-id`), so a
     * hand-written `id` on an `<img>` is not kept, while `class`/`style` are.
     *
     * @var list<string>
     */
    public const array TYPES = [
        'paragraph',
        'heading',
        'blockquote',
        'bulletList',
        'orderedList',
        'listItem',
        'codeBlock',
        'horizontalRule',
        'table',
        'tableRow',
        'tableHeader',
        'tableCell',
        'image',
        'iframe',
        'lead',
        'details',
        'detailsSummary',
        'detailsContent',
        'grid',
        'gridColumn',
        'customDiv',
        'customDivInline',
        'link',
        'textColor',
        'small',
        'customSpan',
    ];

    /**
     * Attribute names that are never accepted: script sinks, URL-bearing attributes and
     * navigation.
     *
     * @var list<string>
     */
    public const array FORBIDDEN = [
        'href', 'src', 'srcdoc', 'srcset', 'action', 'formaction', 'formtarget', 'xlink:href',
        'ping', 'background', 'poster', 'lowsrc', 'dynsrc', 'codebase', 'data', 'is', 'xmlns',
    ];

    /**
     * Prefixes that are never accepted: event handlers, and the directives of front-end
     * frameworks that turn an attribute into code. Filament's own panel runs Alpine over the
     * editor DOM (`x-data`, `x-html`, `x-on:click`...), and a front end may run htmx, Vue or
     * Angular: an allowed `x-init` would be stored XSS against every admin opening the record.
     * Names with `:` are refused as a whole for the same reason (`x-on:click`, `v-on:click`).
     *
     * @var list<string>
     */
    public const array FORBIDDEN_PREFIXES = ['on', 'x-', 'data-x-', 'hx-', 'data-hx-', 'v-', 'ng-', 'data-ng-', 'wire:', 'xmlns'];

    /**
     * Classes written by the extension that OWNS them, and the tags that extension exists for:
     * `TextColorExtension` adds `color` to a `span`, `LeadExtension` — `lead` to a `div`,
     * `GridExtension` — `grid-layout` to a `div`. `HTML::mergeAttributes()` JOINS classes
     * instead of overwriting, so without this filter the result would be `class="color color"`.
     *
     * The filter is per tag: on any other element the class is the author's (Bootstrap's
     * `<p class="lead">`) and nobody else would write it back.
     *
     * @var array<string, list<string>>
     */
    private const array OWNED_CLASSES = [
        'color' => ['span'],
        'lead' => ['div'],
        'grid-layout' => ['div'],
    ];

    /**
     * The same for declarations: `text-align` comes from `TextAlign` (paragraph and headings
     * only), `--color`/`--dark-color` from `TextColorExtension`, `--cols` from `GridExtension`,
     * `height`/`width` from `ImageExtension`. A hand-written `text-align: center` on a `<p>` is
     * not lost: `TextAlign` picks it up and renders it; on a `<td>` or a `<div>` nobody owns it,
     * so it stays in `style`.
     *
     * @var array<string, list<string>>
     */
    private const array OWNED_STYLES = [
        'text-align' => ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'],
        '--color' => ['span'],
        '--dark-color' => ['span'],
        '--cols' => ['div'],
        'height' => ['img'],
        'width' => ['img'],
    ];

    /**
     * @param list<string> $attributes extra attribute names beyond class/id/style
     * @param list<string> $types      extra node/mark types beyond {@see self::TYPES}
     */
    public function __construct(
        private readonly array $attributes = [],
        private readonly array $types = [],
    ) {
        parent::__construct();
    }

    /**
     * Normalises a configured list: valid attribute-name syntax, lower case, no script sinks,
     * no `on*` handlers or framework directives ({@see self::FORBIDDEN_PREFIXES}), no `:`,
     * no duplicates, `class`/`id`/`style` always first.
     *
     * A deny-list is not a proof: list only what you need (`data-*`, `aria-*`, `role`,
     * `title`, `lang`, `dir`), and remember that a `data-*` read by your own front-end script
     * is as dangerous as that script makes it.
     *
     * @param array<mixed> $names
     *
     * @return list<string>
     */
    public static function sanitizeNames(array $names): array
    {
        $clean = ['class', 'id', 'style'];

        foreach ($names as $name) {
            if (!is_string($name)) {
                continue;
            }

            $name = strtolower(trim($name));

            if (preg_match('/^[a-z][a-z0-9_.-]*$/', $name) !== 1
                || in_array($name, self::FORBIDDEN, true)
                || array_filter(self::FORBIDDEN_PREFIXES, static fn (string $prefix): bool => str_starts_with($name, $prefix)) !== []) {
                continue;
            }

            $clean[] = $name;
        }

        return array_values(array_unique($clean));
    }

    /**
     * @return list<CustomDiv|CustomDivInline|CustomSpan>
     */
    public function addExtensions(): array
    {
        return [
            new CustomSpan,
            new CustomDiv,
            new CustomDivInline,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function addGlobalAttributes(): array
    {
        $attributes = [];

        foreach (self::sanitizeNames($this->attributes) as $name) {
            $attributes[$name] = [
                'default' => null,
                'parseHTML' => static fn (DOMElement $node): ?string => self::parse($name, $node),
                'renderHTML' => static fn (mixed $attributes): ?array => self::render($attributes, $name),
            ];
        }

        return [
            [
                'types' => array_values(array_unique([...self::TYPES, ...$this->types])),
                'attributes' => $attributes,
            ],
        ];
    }

    private static function parse(string $name, DOMElement $node): ?string
    {
        $value = $node->getAttribute($name);
        $tag = strtolower($node->nodeName);

        return match ($name) {
            'class' => self::filterClass($value, $tag),
            'style' => self::filterStyle($value, $tag),
            default => self::blankToNull($value),
        };
    }

    private static function filterClass(string $value, string $tag): ?string
    {
        $classes = array_filter(
            preg_split('/\s+/', trim($value)) ?: [],
            static fn (string $class): bool => $class !== '' && !in_array($tag, self::OWNED_CLASSES[$class] ?? [], true),
        );

        return $classes === [] ? null : implode(' ', $classes);
    }

    private static function filterStyle(string $value, string $tag): ?string
    {
        $declarations = array_filter(
            array_map(trim(...), explode(';', $value)),
            static function (string $declaration) use ($tag): bool {
                if ($declaration === '') {
                    return false;
                }

                $property = strtolower(trim(strtok($declaration, ':') ?: ''));

                return !in_array($tag, self::OWNED_STYLES[$property] ?? [], true);
            },
        );

        return $declarations === [] ? null : implode('; ', $declarations);
    }

    private static function blankToNull(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * tiptap-php passes a stdClass of node attributes here (marks too), so the value is read
     * the way the rest of the extensions do it.
     *
     * @return array<string, string>|null
     */
    private static function render(mixed $attributes, string $name): ?array
    {
        $value = match (true) {
            is_object($attributes) => $attributes->{$name} ?? null,
            is_array($attributes) => $attributes[$name] ?? null,
            default => null,
        };

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return [$name => $value];
    }
}
