<?php

declare(strict_types=1);

namespace Asignua\RichEditorToolkit;

use Asignua\RichEditorToolkit\Extensions\CustomAttributes;
use Asignua\RichEditorToolkit\Plugins\CustomAttributesPlugin;
use Asignua\RichEditorToolkit\Plugins\EmbedPlugin;
use Asignua\RichEditorToolkit\Support\EmbedSrcSanitizer;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * The front-end half of the toolkit.
 *
 * `RichContentRenderer` parses the stored HTML with the same tiptap-php schema the editor
 * saves with, and then runs the result through Symfony's HTML sanitizer. Both steps drop what
 * they do not know, so the plugins that preserve attributes and embeds have to take part in
 * the rendering too, not only in the form:
 *
 *     {!! RichEditorToolkit::renderer($post->body)->toHtml() !!}
 *
 * The sanitizer allowances are NOT added to Filament's shared sanitizer: that one also guards
 * `TextColumn::html()`, `TextEntry::html()`, notification bodies and the Markdown editor, none
 * of which has a tiptap schema to filter iframes by host. The toolkit sanitizes with its own
 * instance, built from a copy of Filament's config ({@see self::sanitizer()}).
 *
 * Only the plugins that have a server half are added here. {@see Plugins\PasteCleanPlugin}
 * has none on purpose: it cleans once, at paste time, and a second pass at render time would
 * strip the very `class`/`style` that CustomAttributesPlugin exists to keep.
 */
final class RichEditorToolkit
{
    /**
     * Attribute names registered with {@see self::allowAttributes()} (boot-time, like config).
     *
     * @var list<string>
     */
    private static array $attributes = [];

    /**
     * A renderer with the toolkit's server-side plugins attached; its `toHtml()` uses the
     * toolkit's sanitizer.
     *
     * `$attributes` / `$types` mirror {@see CustomAttributesPlugin::attributes()} /
     * {@see CustomAttributesPlugin::types()}: pass the SAME lists you gave the form plugin,
     * otherwise the per-field attributes are saved and then dropped on the page. Null means
     * the config (plus {@see self::allowAttributes()}).
     *
     * @param array<mixed>|string|null $content
     * @param list<string>|null        $attributes
     * @param list<string>|null        $types
     */
    public static function renderer(array|string|null $content, ?array $attributes = null, ?array $types = null): ToolkitRenderer
    {
        return ToolkitRenderer::make($content)
            ->plugins(self::plugins($attributes, $types));
    }

    /**
     * The plugins to attach to a `RichContentRenderer` yourself, when you build it elsewhere
     * (custom blocks, mentions, ...). Render that renderer with {@see self::toHtml()}: its own
     * `toHtml()` uses Filament's shared sanitizer, which knows nothing about the toolkit.
     *
     * @param list<string>|null $attributes
     * @param list<string>|null $types
     *
     * @return list<CustomAttributesPlugin|EmbedPlugin>
     */
    public static function plugins(?array $attributes = null, ?array $types = null): array
    {
        $customAttributes = CustomAttributesPlugin::make();

        if ($attributes !== null) {
            $customAttributes->attributes($attributes);
        }

        if ($types !== null) {
            $customAttributes->types($types);
        }

        return [
            $customAttributes,
            EmbedPlugin::make(),
        ];
    }

    /**
     * Renders any `RichContentRenderer` through the toolkit's sanitizer. The allowances follow
     * the renderer's plugins: the attribute names of its {@see CustomAttributesPlugin}s, and
     * `<iframe>` only when an {@see EmbedPlugin} is attached.
     */
    public static function toHtml(RichContentRenderer $renderer): string
    {
        $attributes = [];
        $embeds = false;

        foreach ($renderer->getPlugins() as $plugin) {
            if ($plugin instanceof CustomAttributesPlugin) {
                $attributes = [...$attributes, ...$plugin->getAttributeNames()];
            }

            if ($plugin instanceof EmbedPlugin) {
                $embeds = true;
            }
        }

        return self::sanitizer($attributes, $embeds)->sanitize($renderer->toUnsafeHtml());
    }

    /**
     * A sanitizer built from Filament's config plus the toolkit's allowances. Never bound in
     * the container: Filament's shared sanitizer stays exactly as Filament configured it.
     *
     * @param list<string>|null $attributes null = {@see self::attributeNames()}
     */
    public static function sanitizer(?array $attributes = null, bool $embeds = true): HtmlSanitizerInterface
    {
        return new HtmlSanitizer(self::applySanitizerAllowances(app(HtmlSanitizerConfig::class), $attributes, $embeds));
    }

    /**
     * Allows further attribute names through the toolkit's sanitizer and into every
     * {@see CustomAttributesPlugin} that has no list of its own (in addition to the
     * `attributes` config). Call from a service provider's `boot()`.
     *
     * @param list<string> $names
     */
    public static function allowAttributes(array $names): void
    {
        self::$attributes = array_values(array_unique([...self::$attributes, ...CustomAttributes::sanitizeNames($names)]));
    }

    /**
     * @deprecated No-op. The toolkit's sanitizer allows `<iframe>` whenever an
     *             {@see EmbedPlugin} is attached to the renderer; Filament's shared sanitizer
     *             is never changed.
     */
    public static function allowEmbeds(): void {}

    /**
     * Every attribute name that is preserved: class/id/style, the config, the runtime ones.
     *
     * @return list<string>
     */
    public static function attributeNames(): array
    {
        /** @var array<mixed> $configured */
        $configured = (array) config('rich-editor-toolkit.attributes', []);

        return CustomAttributes::sanitizeNames([...$configured, ...self::$attributes]);
    }

    /**
     * Extra node/mark types from the config.
     *
     * @return list<string>
     */
    public static function extraTypes(): array
    {
        /** @var array<mixed> $types */
        $types = (array) config('rich-editor-toolkit.types', []);

        return array_values(array_filter($types, static fn (mixed $type): bool => is_string($type) && preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $type) === 1));
    }

    /**
     * Returns a COPY of the config with the toolkit's allowances (Symfony's config is
     * immutable: every `allow*()` returns a clone).
     *
     * Symfony's API subtracts: `allowAttribute($a, $elements)` adds the attribute to the listed
     * elements and REMOVES it from every other allowed one, and `allowElement()` resets the
     * attributes of an element already allowed. So the element goes first, and an attribute
     * that other elements already carry (`src`, `width`, ...) is re-applied to the UNION of
     * its current elements and `iframe`; a naive call would silently strip `src` from `<img>`.
     *
     * `allow` and `sandbox` must stay allowed: {@see Extensions\IframeNode} writes the forced
     * hardening values while rendering, i.e. BEFORE the sanitizer runs. The iframe `src` is
     * checked against the embed allow-list by {@see EmbedSrcSanitizer}.
     *
     * @param list<string>|null $attributes null = {@see self::attributeNames()}
     */
    public static function applySanitizerAllowances(HtmlSanitizerConfig $config, ?array $attributes = null, bool $embeds = true): HtmlSanitizerConfig
    {
        if ($embeds) {
            $config = $config->allowElement('iframe');

            foreach (['src', 'width', 'height', 'title', 'allow', 'allowfullscreen', 'sandbox', 'loading', 'referrerpolicy'] as $attribute) {
                $elements = ['iframe'];

                foreach ($config->getAllowedElements() as $element => $allowed) {
                    if (isset($allowed[$attribute])) {
                        $elements[] = $element;
                    }
                }

                $config = $config->allowAttribute($attribute, array_values(array_unique($elements)));
            }

            $config = $config->withAttributeSanitizer(new EmbedSrcSanitizer);
        }

        $names = $attributes === null ? self::attributeNames() : CustomAttributes::sanitizeNames($attributes);

        foreach ($names as $attribute) {
            $config = $config->allowAttribute($attribute, '*');
        }

        return $config;
    }

    /**
     * @internal for the test suite
     */
    public static function reset(): void
    {
        self::$attributes = [];
    }
}
