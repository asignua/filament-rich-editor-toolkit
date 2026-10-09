<?php

declare(strict_types=1);

namespace Asignua\RichEditorToolkit\Plugins;

use Asignua\RichEditorToolkit\Assets;
use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor\Plugins\Contracts\RichContentPlugin;
use Filament\Forms\Components\RichEditor\RichEditorTool;
use Filament\Support\Icons\Heroicon;
use Tiptap\Core\Extension;

/**
 * A "Fullscreen" toolbar button: the field becomes a full-viewport overlay and back. Esc leaves.
 *
 * List `fullscreen` in `->toolbarButtons([...])`. All the work is in
 * `resources/js/src/fullscreen.js` (the TipTap command `asignuaToggleFullscreen`) plus a small
 * stylesheet the module links itself, so the plugin needs no panel plugin and works in any
 * Livewire form. The button is a toggle: `aria-pressed` and the active style follow the state.
 *
 * The overlay is a fixed element at `z-index: 35`, above the panel's topbar and sidebar (30) and
 * BELOW its modals (40), so the link, embed and image dialogs opened from the fullscreen
 * toolbar still open on top. It is deliberately not in the browser's top layer, which would
 * hide those dialogs behind the editor. It fills the screen unless an ancestor has `transform`,
 * `filter` or `contain` (then it fills that ancestor). `overflow` on a section, tab or repeater
 * item does not clip it.
 *
 * No server half: do NOT add it to the front-end renderer.
 */
class FullscreenPlugin implements RichContentPlugin
{
    protected ?int $zIndex = null;

    public static function make(): static
    {
        return app(static::class);
    }

    /**
     * The overlay's z-index. Defaults to the `fullscreen.z_index` config, then 35 (the CSS).
     */
    public function zIndex(?int $zIndex): static
    {
        $this->zIndex = $zIndex;

        return $this;
    }

    public function getZIndex(): ?int
    {
        $z = $this->zIndex ?? config('rich-editor-toolkit.fullscreen.z_index');

        return is_int($z) && $z >= 0 ? $z : null;
    }

    /**
     * @return array<Extension>
     */
    public function getTipTapPhpExtensions(): array
    {
        return [];
    }

    /**
     * @return array<string>
     */
    public function getTipTapJsExtensions(): array
    {
        $params = ['css' => [Assets::styleHref()]];

        if (($z = $this->getZIndex()) !== null) {
            $params['z'] = [(string) $z];
        }

        return [Assets::url('fullscreen', $params)];
    }

    /**
     * @return array<RichEditorTool>
     */
    public function getEditorTools(): array
    {
        return [
            RichEditorTool::make('fullscreen')
                ->label(__('rich-editor-toolkit::rich-editor-toolkit.fullscreen'))
                ->icon(Heroicon::ArrowsPointingOut)
                // Filament renders aria-pressed and the active style from the active expression.
                ->toggle()
                ->activeJsExpression('$getEditor()?.storage.asignuaFullscreen?.active === true')
                // `?.` like all the stock code: before TipTap initialises, editor === undefined.
                ->jsHandler('$getEditor()?.commands.asignuaToggleFullscreen()'),
        ];
    }

    /**
     * @return array<Action>
     */
    public function getEditorActions(): array
    {
        return [];
    }
}
