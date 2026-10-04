<?php

declare(strict_types=1);

namespace Asignua\RichEditorToolkit\Plugins;

use Asignua\RichEditorToolkit\Assets;
use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor\Plugins\Contracts\RichContentPlugin;
use Filament\Forms\Components\RichEditor\RichEditorTool;
use Illuminate\Support\HtmlString;
use Tiptap\Core\Extension;

/**
 * Cleans a paste from Word / Google Docs at the moment of Ctrl+V: the structure stays, while
 * colours, font sizes, backgrounds and every class/id/style they carry disappear.
 *
 * Plus the toolbar button `cleanFormat` ("Clear formatting", {@see self::getEditorTools()}):
 * the same cleanup on the SELECTED fragment of content that is already there, keeping what
 * should survive — headings, lists, links (relative, `#anchor` and `tel:` too; only script
 * schemes go), custom blocks, embeds, images (also ones inserted by URL) and code blocks. With no selection
 * the button is a deliberate no-op. There is no dialog, so {@see self::getEditorActions()} is
 * empty. All the work is in `resources/js/src/paste-clean.js`: the paste goes through the
 * `transformPastedHTML` hook, the button through the TipTap command `asignuaCleanFormat`.
 *
 * Not to be confused with Filament's stock `clearFormatting`: that one runs
 * `clearNodes().unsetAllMarks()` and wipes headings, lists and bold too — the opposite of
 * "keep the structure, drop the junk".
 *
 * NOTE: there is NO server half. Unlike {@see CustomAttributesPlugin} this plugin must NOT be
 * added to the front-end renderer: the cleanup happens exactly once, at paste time, and the
 * database already holds clean HTML. A second pass at render time would strip the class/style
 * that CustomAttributesPlugin exists to keep, on every page.
 *
 * The two are in direct tension — one preserves attributes, the other strips them. What keeps
 * them apart is a single thing: the `data-pm-slice` cutoff inside the JS module, which leaves a
 * paste from INSIDE an editor alone.
 *
 * The button must also be listed in the editor's toolbar:
 * `->toolbarButtons([[..., 'cleanFormat']])`. An unknown button name makes Filament throw.
 */
class PasteCleanPlugin implements RichContentPlugin
{
    /** @var list<string>|null */
    protected ?array $keepLinkPrefixes = null;

    public static function make(): static
    {
        return app(static::class);
    }

    /**
     * Link href prefixes the "Clear formatting" button keeps whatever their scheme. The button
     * already keeps relative links (e.g. `/internal-link/`), so this is needed only for a
     * prefix it would otherwise refuse. Defaults to the `paste_clean.keep_link_prefixes`
     * config, which defaults to none.
     *
     * @param list<string> $prefixes
     */
    public function keepLinkPrefixes(array $prefixes): static
    {
        $this->keepLinkPrefixes = $prefixes;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getKeepLinkPrefixes(): array
    {
        $prefixes = $this->keepLinkPrefixes ?? (array) config('rich-editor-toolkit.paste_clean.keep_link_prefixes', []);

        return array_values(array_filter($prefixes, static fn (mixed $prefix): bool => is_string($prefix) && $prefix !== ''));
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
        return [Assets::url('paste-clean', ['keep' => $this->getKeepLinkPrefixes()])];
    }

    /**
     * @return array<RichEditorTool>
     */
    public function getEditorTools(): array
    {
        return [
            RichEditorTool::make('cleanFormat')
                ->label(__('rich-editor-toolkit::rich-editor-toolkit.clear_formatting'))
                // An eraser — "wipe the styling"; deliberately unlike the link icons next to it.
                ->icon(new HtmlString('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 21-4.3-4.3c-1-1-1-2.5 0-3.4l9.6-9.6c1-1 2.5-1 3.4 0l5.6 5.6c1 1 1 2.5 0 3.4L13 21"/><path d="M22 21H7"/><path d="m5 11 9 9"/></svg>'))
                // `?.` like all the stock code: before TipTap initialises, editor === undefined.
                ->jsHandler('$getEditor()?.chain().focus().asignuaCleanFormat().run()'),
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
