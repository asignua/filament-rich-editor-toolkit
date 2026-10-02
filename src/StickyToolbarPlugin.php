<?php

declare(strict_types=1);

namespace Asignua\RichEditorToolkit;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\Support\Facades\FilamentAsset;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\HtmlString;

/**
 * Keeps the RichEditor toolbar visible while you scroll through long content (filament
 * discussions #17391, #16919, #11685).
 *
 * Panel plugin, CSS only:
 *
 *     ->plugin(StickyToolbarPlugin::make()->offset('4rem'))
 *
 * Filament 5 has `RichEditor::stickyToolbar()` per field. This plugin makes it the default for
 * every editor in the panel without touching each field, and fixes the case where the native
 * option silently does nothing: an ancestor (section, tab, repeater item) clips with
 * `overflow: hidden`, which turns it into a scroll container that `position: sticky` cannot
 * escape. The stylesheet switches those containers to `overflow: clip`.
 */
class StickyToolbarPlugin implements Plugin
{
    public const string ID = 'filament-rich-editor-toolkit-sticky-toolbar';

    public const string STYLESHEET = 'filament-rich-editor-toolkit';

    protected string|Closure|null $offset = null;

    protected int $zIndex = 5;

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        /** @var static */
        return filament(static::ID);
    }

    public function getId(): string
    {
        return static::ID;
    }

    /**
     * The distance from the top of the viewport at which the toolbar sticks (a CSS length),
     * for a custom layout whose own header is taller than Filament's topbar.
     */
    public function offset(string|Closure|null $offset): static
    {
        $this->offset = $offset;

        return $this;
    }

    public function zIndex(int $zIndex): static
    {
        $this->zIndex = $zIndex;

        return $this;
    }

    public function getOffset(): ?string
    {
        $offset = value($this->offset) ?? config('rich-editor-toolkit.sticky_toolbar.offset');

        // A CSS length only: this is printed into a <style> element.
        return is_string($offset) && preg_match('/^[0-9a-z.()%+*\/ _,-]+$/i', $offset) === 1 ? $offset : null;
    }

    public function register(Panel $panel): void
    {
        $panel->renderHook(PanelsRenderHook::STYLES_AFTER, function (): HtmlString {
            $href = FilamentAsset::getStyleHref(self::STYLESHEET, Assets::PACKAGE);

            $variables = '--asignua-rte-sticky-z: '.$this->zIndex.';';

            if (($offset = $this->getOffset()) !== null) {
                $variables .= ' --asignua-rte-sticky-offset: '.$offset.';';
            }

            return new HtmlString(
                '<link rel="stylesheet" href="'.e($href).'" />'
                .'<style>:root { '.$variables.' }</style>',
            );
        });
    }

    public function boot(Panel $panel): void {}
}
