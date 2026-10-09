<?php

declare(strict_types=1);

namespace Asignua\RichEditorToolkit\Plugins;

use Asignua\RichEditorToolkit\Assets;
use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor\Plugins\Contracts\RichContentPlugin;
use Filament\Forms\Components\RichEditor\RichEditorTool;
use Tiptap\Core\Extension;

/**
 * A character / word counter under the editor ("123 / 500 characters"), counted on PLAIN TEXT.
 *
 * Filament's `maxLength()` validated the raw HTML string in earlier 5.x releases (tags and
 * attributes ate the budget, filamentphp/filament#17028), has no counter, and no word limit; the
 * counter and the matching rule count what the reader sees.
 * Characters are Unicode code points with spaces, paragraph and line breaks free; words are
 * runs of non-whitespace; images, embeds, custom blocks and mentions add nothing. One
 * definition, two implementations: `resources/js/src/character-count.js` here and
 * {@see \Asignua\RichEditorToolkit\Support\PlainText} for the server.
 *
 * The counter only DISPLAYS: it never blocks typing and the browser is not trusted. The limit
 * is enforced by {@see \Asignua\RichEditorToolkit\Rules\RichTextLength}. The usual way to get
 * both is the macro:
 *
 *     RichEditor::make('body')->textLength(max: 500, maxWords: 80)
 *
 * Used alone it is a counter without a limit when no max is given. It turns amber from
 * `warnAt()` (default 90% of a limit) and red above it, and announces "limit exceeded" to
 * screen readers once when it flips (the visible number is not a live region: it changes on
 * every key).
 *
 * No toolbar button, no server half: do NOT add it to the front-end renderer.
 */
class CharacterCountPlugin implements RichContentPlugin
{
    protected ?int $maxCharacters = null;

    protected ?int $maxWords = null;

    protected bool $showWords = false;

    protected ?float $warnAt = null;

    public static function make(): static
    {
        return app(static::class);
    }

    public function maxCharacters(?int $max): static
    {
        $this->maxCharacters = $max;

        return $this;
    }

    public function maxWords(?int $max): static
    {
        $this->maxWords = $max;

        return $this;
    }

    /**
     * Show the word count next to the character count (always shown when a word limit is set).
     */
    public function showWords(bool $show = true): static
    {
        $this->showWords = $show;

        return $this;
    }

    /**
     * The share of a limit (0 to 1) from which the counter turns amber. Defaults to the
     * `character_count.warn_at` config, which defaults to 0.9.
     */
    public function warnAt(?float $share): static
    {
        $this->warnAt = $share;

        return $this;
    }

    public function getWarnAt(): float
    {
        $share = $this->warnAt ?? config('rich-editor-toolkit.character_count.warn_at', 0.9);

        return is_numeric($share) && $share > 0 && $share <= 1 ? (float) $share : 0.9;
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

        $params = [
            'css' => [Assets::styleHref()],
            'warn' => [(string) $this->getWarnAt()],
            't-chars' => [__($prefix.'characters_count', ['count' => ':count'])],
            't-chars-max' => [__($prefix.'characters_count_max', ['count' => ':count', 'max' => ':max'])],
            't-words' => [__($prefix.'words_count', ['count' => ':count'])],
            't-words-max' => [__($prefix.'words_count_max', ['count' => ':count', 'max' => ':max'])],
            't-over-chars' => [__($prefix.'characters_over')],
            't-over-words' => [__($prefix.'words_over')],
        ];

        if ($this->maxCharacters !== null) {
            $params['max'] = [(string) $this->maxCharacters];
        }

        if ($this->maxWords !== null) {
            $params['max-words'] = [(string) $this->maxWords];
        }

        if ($this->showWords || $this->maxWords !== null) {
            $params['words'] = ['1'];
        }

        return [Assets::url('character-count', $params)];
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
