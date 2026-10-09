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
 * A "Source" toolbar button: the editing area is swapped for a plain textarea with the
 * document's HTML (pretty-printed) and back.
 *
 * List `sourceCode` in `->toolbarButtons([...])`. All the work is in
 * `resources/js/src/source-code.js` (the TipTap command `asignuaToggleSourceCode`) and
 * `source-html.js` (printer and difference detector). The edited HTML goes back into the editor
 * only through the editor's own schema (`setContent`), so markup the schema does not know is
 * dropped, and the editor says so in a non-blocking notice. There is no server half: do NOT add
 * the plugin to the front-end renderer, and the server sanitizer is unchanged.
 *
 * `class`, `id` and `style` (and any other attribute) survive a round trip only when
 * `CustomAttributesPlugin` is registered; the source view is how editors add them.
 */
class SourceCodePlugin implements RichContentPlugin
{
    protected ?int $indent = null;

    public static function make(): static
    {
        return app(static::class);
    }

    /**
     * Spaces per indentation level in the printed source (0 to 8). Defaults to the
     * `source_code.indent` config, then 2.
     */
    public function indent(?int $indent): static
    {
        $this->indent = $indent;

        return $this;
    }

    public function getIndent(): int
    {
        $indent = $this->indent ?? config('rich-editor-toolkit.source_code.indent', 2);

        return is_int($indent) && $indent >= 0 && $indent <= 8 ? $indent : 2;
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
        $prefix = 'rich-editor-toolkit::rich-editor-toolkit.';

        return [Assets::url('source-code', [
            'css' => [Assets::styleHref()],
            'indent' => [(string) $this->getIndent()],
            't-label' => [__($prefix.'source_code_label')],
            't-notice' => [__($prefix.'source_code_unsupported')],
        ])];
    }

    /**
     * @return array<RichEditorTool>
     */
    public function getEditorTools(): array
    {
        return [
            RichEditorTool::make('sourceCode')
                ->label(__('rich-editor-toolkit::rich-editor-toolkit.source_code'))
                ->icon(Heroicon::CodeBracket)
                // Filament renders aria-pressed and the active style from the active expression.
                ->toggle()
                ->activeJsExpression('$getEditor()?.storage.asignuaSourceCode?.active === true')
                ->jsHandler('$getEditor()?.commands.asignuaToggleSourceCode()'),
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
