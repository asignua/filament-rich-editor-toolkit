<?php

declare(strict_types=1);

namespace Asignua\RichEditorToolkit\Plugins;

use Asignua\RichEditorToolkit\Support\LinkAttributes;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\EditorCommand;
use Filament\Forms\Components\RichEditor\Plugins\Contracts\RichContentPlugin;
use Filament\Forms\Components\RichEditor\RichEditorTool;
use Filament\Forms\Components\Toggle;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Tiptap\Core\Extension;

/**
 * `rel` and `target` of a link: a `linkOptions` toolbar button (enabled while the cursor is in a
 * link) that opens a dialog with "Open in a new tab" and the rel tokens `nofollow`, `sponsored`
 * and `ugc`.
 *
 * WHAT FILAMENT DOES. Its `link` dialog has a URL and an "Open in new tab" checkbox and writes
 * `setLink({href, target})`; `rel` is never written (the JS Link mark is configured with
 * `rel: null`, and the PHP mark with an empty `HTMLAttributes`). The mark does keep `target` and
 * `rel` attributes, the toolkit's and Filament's renderers pass them through unchanged, and the
 * sanitizer allows both on `<a>`. Missing, then, are a way to set `rel` and any control over what a
 * stored `rel`/`target` may contain (filamentphp/filament#16829, #19478, discussions #5705, #19028).
 *
 * WHY A SECOND BUTTON. The stock link action is built by a static factory
 * (`LinkAction::make()`) and has no seam to add a field; replacing it would break every other plugin
 * that talks to the `link` mark (`asignua/filament-rich-links` writes its marker into the same mark).
 * This plugin changes only the `target` and `rel` of the link under the cursor
 * (`updateAttributes('link', ...)`, after `extendMarkRange('link')` for a bare cursor), so it
 * composes: edit the address with the stock button or the rich-links one, the options with this one.
 * It adds no browser module: the TipTap mark already carries both attributes.
 *
 * SERVER HALF. This plugin MUST also be in the front-end renderer
 * ({@see \Asignua\RichEditorToolkit\RichEditorToolkit::renderer()} adds it): the renderer then
 * normalises every link of the output with {@see LinkAttributes} (whitelisted rel tokens only,
 * `target` only `_blank`, `noopener noreferrer` forced on a new tab) and adds `external_rel` to
 * links that leave the site. Without it the stored values are rendered as they are, as in stock
 * Filament.
 */
class LinkOptionsPlugin implements RichContentPlugin
{
    /** @var list<string>|null */
    protected ?array $externalRel = null;

    /** @var list<string>|null */
    protected ?array $internalHosts = null;

    public static function make(): static
    {
        return app(static::class);
    }

    /**
     * Rel tokens added at RENDER time to every absolute link that leaves the site (the stored
     * content is untouched), e.g. `['nofollow']`. Defaults to `link_options.external_rel`, which
     * defaults to none. Only tokens of the rel whitelist take effect.
     *
     * @param list<string> $tokens
     */
    public function externalRel(array $tokens): static
    {
        $this->externalRel = $tokens;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getExternalRel(): array
    {
        $tokens = $this->externalRel ?? (array) config('rich-editor-toolkit.link_options.external_rel', []);

        return array_values(array_filter($tokens, static fn (mixed $token): bool => is_string($token) && $token !== ''));
    }

    /**
     * Hosts that count as "the site itself" for {@see self::externalRel()}, besides the host of
     * `app.url` (a leading `www.` is ignored). Defaults to `link_options.internal_hosts`.
     *
     * @param list<string> $hosts
     */
    public function internalHosts(array $hosts): static
    {
        $this->internalHosts = $hosts;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getInternalHosts(): array
    {
        $hosts = $this->internalHosts ?? (array) config('rich-editor-toolkit.link_options.internal_hosts', []);
        $hosts = array_values(array_filter($hosts, static fn (mixed $host): bool => is_string($host) && $host !== ''));

        $own = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($own) && $own !== '' ? [...$hosts, $own] : $hosts;
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
        return [];
    }

    /**
     * @return array<RichEditorTool>
     */
    public function getEditorTools(): array
    {
        return [
            RichEditorTool::make('linkOptions')
                ->label(__('rich-editor-toolkit::rich-editor-toolkit.link_options'))
                ->icon(Heroicon::OutlinedCog6Tooth)
                // As Filament's own "link" button: `editorSelection` is added by action(), the
                // current attributes are read from the link under the cursor.
                ->action(arguments: '{ newTab: $getEditor().getAttributes(\'link\')?.target === \'_blank\', rel: $getEditor().getAttributes(\'link\')?.rel ?? null }')
                ->activeKey('link')
                ->disabledWhenNotActive(),
        ];
    }

    /**
     * @return array<Action>
     */
    public function getEditorActions(): array
    {
        $prefix = 'rich-editor-toolkit::rich-editor-toolkit.';

        return [
            Action::make('linkOptions')
                ->label(__($prefix.'link_options'))
                ->modalHeading(__($prefix.'link_options'))
                ->modalWidth(Width::Large)
                ->modalSubmitActionLabel(__($prefix.'apply'))
                ->fillForm(fn (array $arguments): array => [
                    'newTab' => (bool) ($arguments['newTab'] ?? false),
                    'rel' => self::editableTokens($arguments['rel'] ?? null),
                ])
                ->schema([
                    Toggle::make('newTab')
                        ->label(__($prefix.'link_options_new_tab'))
                        ->helperText(__($prefix.'link_options_new_tab_hint')),
                    CheckboxList::make('rel')
                        ->label(__($prefix.'link_options_rel'))
                        ->helperText(__($prefix.'link_options_rel_hint'))
                        ->options([
                            'nofollow' => __($prefix.'rel_nofollow'),
                            'sponsored' => __($prefix.'rel_sponsored'),
                            'ugc' => __($prefix.'rel_ugc'),
                        ])
                        ->descriptions([
                            'nofollow' => __($prefix.'rel_nofollow_hint'),
                            'sponsored' => __($prefix.'rel_sponsored_hint'),
                            'ugc' => __($prefix.'rel_ugc_hint'),
                        ]),
                ])
                ->action(function (array $arguments, array $data, RichEditor $component): void {
                    $selection = $arguments['editorSelection'] ?? null;

                    // As the stock link action: a bare cursor inside a link has no range to update,
                    // so the mark's whole range is selected first.
                    $isCursor = is_array($selection) && ($selection['head'] ?? null) === ($selection['anchor'] ?? null);

                    $component->runCommands(
                        [
                            ...($isCursor ? [EditorCommand::make('extendMarkRange', arguments: ['link'])] : []),
                            EditorCommand::make('updateAttributes', arguments: ['link', self::attributesFrom($data, $arguments['rel'] ?? null)]),
                        ],
                        editorSelection: $selection,
                    );
                }),
        ];
    }

    /**
     * The mark attributes for what the dialog sent. Tokens the dialog does not manage (anything
     * else the link already had) are kept if the rel whitelist allows them; `noopener noreferrer` follow the new-tab switch.
     *
     * @param array<string, mixed> $data
     *
     * @return array{target: string|null, rel: string|null}
     */
    public static function attributesFrom(array $data, mixed $existingRel = null): array
    {
        $newTab = (bool) ($data['newTab'] ?? false);
        $managed = [...LinkAttributes::EDITABLE, ...LinkAttributes::FOR_NEW_TAB];

        // `$existingRel` comes back from the browser (the dialog's argument), so it is not trusted: only
        // what the rel whitelist allows is stored, and `opener` never (it undoes the new-tab protection).
        $allowed = LinkAttributes::allowedTokens();

        $others = array_values(array_unique(array_filter(
            array_map(strtolower(...), preg_split('/\s+/', trim(is_string($existingRel) ? $existingRel : '')) ?: []),
            static fn (string $token): bool => $token !== ''
                && $token !== 'opener'
                && !in_array($token, $managed, true)
                && in_array($token, $allowed, true),
        )));

        $chosen = array_values(array_intersect(LinkAttributes::EDITABLE, array_map(strval(...), (array) ($data['rel'] ?? []))));

        $rel = array_values(array_unique([...$others, ...$chosen, ...($newTab ? LinkAttributes::FOR_NEW_TAB : [])]));

        return [
            'target' => $newTab ? '_blank' : null,
            'rel' => $rel === [] ? null : implode(' ', $rel),
        ];
    }

    /**
     * The dialog's checkboxes for a stored rel.
     *
     * @return list<string>
     */
    public static function editableTokens(mixed $rel): array
    {
        $tokens = array_map(strtolower(...), preg_split('/\s+/', trim(is_string($rel) ? $rel : '')) ?: []);

        return array_values(array_intersect(LinkAttributes::EDITABLE, $tokens));
    }
}
