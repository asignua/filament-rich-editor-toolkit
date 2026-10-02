<?php

declare(strict_types=1);

namespace Asignua\RichEditorToolkit;

use Asignua\RichEditorToolkit\Extensions\CustomAttributes;
use Asignua\RichEditorToolkit\Plugins\CustomAttributesPlugin;
use Asignua\RichEditorToolkit\Plugins\EmbedPlugin;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
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
 * Only the plugins that have a server half are added here. {@see Plugins\PasteCleanPlugin}
 * has none on purpose: it cleans once, at paste time, and a second pass at render time would
 * strip the very `class`/`style` that CustomAttributesPlugin exists to keep.
 */
final class RichEditorToolkit
{
    /** Whether the sanitizer must let `<iframe>` through (switched on by {@see EmbedPlugin}). */
    private static bool $embeds = false;

    /** @var list<string> */
    private static array $attributes = [];

    /**
     * A renderer with the toolkit's server-side plugins attached.
     *
     * @param array<mixed>|string|null $content
     */
    public static function renderer(array|string|null $content): RichContentRenderer
    {
        return RichContentRenderer::make($content)
            ->plugins(self::plugins());
    }

    /**
     * The plugins to attach to a `RichContentRenderer` yourself, when you build it elsewhere
     * (custom blocks, mentions, ...).
     *
     * @return list<CustomAttributesPlugin|EmbedPlugin>
     */
    public static function plugins(): array
    {
        return [
            CustomAttributesPlugin::make(),
            EmbedPlugin::make(),
        ];
    }

    /**
     * Allows further attribute names through the sanitizer (in addition to the `attributes`
     * config). Call from a service provider's `boot()`.
     *
     * @param list<string> $names
     */
    public static function allowAttributes(array $names): void
    {
        self::$attributes = array_values(array_unique([...self::$attributes, ...CustomAttributes::sanitizeNames($names)]));

        self::refreshSanitizer();
    }

    /**
     * Lets `<iframe>` through the sanitizer. Done automatically when {@see EmbedPlugin} is
     * instantiated; the renderer then keeps only iframes the allow-list accepts.
     */
    public static function allowEmbeds(): void
    {
        if (self::$embeds) {
            return;
        }

        self::$embeds = true;

        self::refreshSanitizer();
    }

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
     * Applied to Filament's sanitizer config by the service provider.
     *
     * Symfony's API subtracts: `allowAttribute($a, $elements)` adds the attribute to the listed
     * elements and REMOVES it from every other allowed one, and `allowElement()` resets the
     * attributes of an element already allowed. So the element goes first, and an attribute
     * that other elements already carry (`src`, `width`, ...) is re-applied to the UNION of
     * its current elements and `iframe`; a naive call would silently strip `src` from `<img>`.
     */
    public static function applySanitizerAllowances(HtmlSanitizerConfig $config): HtmlSanitizerConfig
    {
        if (self::$embeds) {
            $config = $config->allowElement('iframe');

            foreach (['src', 'width', 'height', 'title', 'allow', 'allowfullscreen', 'sandbox', 'loading', 'referrerpolicy'] as $attribute) {
                $elements = ['iframe'];

                foreach ($config->getAllowedElements() as $element => $attributes) {
                    if (isset($attributes[$attribute])) {
                        $elements[] = $element;
                    }
                }

                $config = $config->allowAttribute($attribute, array_values(array_unique($elements)));
            }
        }

        foreach (self::attributeNames() as $attribute) {
            $config = $config->allowAttribute($attribute, '*');
        }

        return $config;
    }

    /**
     * Forgets the sanitizer if it was already built in this request/scope, so the new
     * allowances are applied on its next resolution (it is a scoped binding).
     */
    private static function refreshSanitizer(): void
    {
        $app = app();

        foreach ([HtmlSanitizerInterface::class, HtmlSanitizerConfig::class] as $abstract) {
            if ($app->resolved($abstract)) {
                $app->forgetInstance($abstract);
            }
        }
    }

    /**
     * @internal for the test suite
     */
    public static function reset(): void
    {
        self::$embeds = false;
        self::$attributes = [];
    }
}
