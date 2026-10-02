<?php

declare(strict_types=1);

namespace Asignua\RichEditorToolkit\Plugins;

use Asignua\RichEditorToolkit\Assets;
use Asignua\RichEditorToolkit\Extensions\CustomAttributes;
use Asignua\RichEditorToolkit\RichEditorToolkit;
use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor\Plugins\Contracts\RichContentPlugin;
use Filament\Forms\Components\RichEditor\RichEditorTool;
use Tiptap\Core\Extension;

/**
 * Preserves `class`, `id`, `style` and any further allow-listed attributes (`data-*`, `aria-*`,
 * ...) on rich-content nodes: a PHP schema plus a mirror JS module for the browser schema.
 *
 * It MUST be present in the editor AND in the front-end renderer
 * ({@see RichEditorToolkit::renderer()}): `RichContentRenderer` parses the stored HTML with the
 * same tiptap-php, so without the plugin there the attributes lie in the database and vanish
 * on the page.
 *
 * Which attributes: `class`, `id`, `style` always; the rest from the `attributes` config or
 * {@see self::attributes()}. The list is explicit because Symfony's sanitizer cannot allow
 * `data-*` as a pattern.
 */
class CustomAttributesPlugin implements RichContentPlugin
{
    /** @var list<string>|null */
    protected ?array $attributes = null;

    /** @var list<string>|null */
    protected ?array $types = null;

    public static function make(): static
    {
        return app(static::class);
    }

    /**
     * Attribute names (beyond class/id/style) for THIS plugin instance, replacing the config
     * list. They exist only on this instance: a front-end request never builds the form, so
     * pass the same list to the renderer, `RichEditorToolkit::renderer($html, attributes: [...])`,
     * or the values are saved and then dropped on the page. For a list shared by every editor
     * use the `attributes` config or {@see RichEditorToolkit::allowAttributes()} instead.
     *
     * @param list<string> $names
     */
    public function attributes(array $names): static
    {
        $this->attributes = CustomAttributes::sanitizeNames($names);

        return $this;
    }

    /**
     * Extra node/mark types that should receive the attributes, replacing the config list.
     * Per instance like {@see self::attributes()}: pass the same list to
     * `RichEditorToolkit::renderer($html, types: [...])`.
     *
     * @param list<string> $types
     */
    public function types(array $types): static
    {
        $this->types = $types;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getAttributeNames(): array
    {
        return $this->attributes ?? RichEditorToolkit::attributeNames();
    }

    /**
     * @return list<string>
     */
    public function getTypes(): array
    {
        return $this->types ?? RichEditorToolkit::extraTypes();
    }

    /**
     * @return array<Extension>
     */
    public function getTipTapPhpExtensions(): array
    {
        return [
            new CustomAttributes($this->getAttributeNames(), $this->getTypes()),
        ];
    }

    /**
     * @return array<string>
     */
    public function getTipTapJsExtensions(): array
    {
        return [
            Assets::url('custom-attributes', [
                'attr' => $this->getAttributeNames(),
                'type' => $this->getTypes(),
            ]),
        ];
    }

    /**
     * @return array<RichEditorTool>
     */
    public function getEditorTools(): array
    {
        return [];
    }

    /**
     * @return array<Action>
     */
    public function getEditorActions(): array
    {
        return [];
    }
}
