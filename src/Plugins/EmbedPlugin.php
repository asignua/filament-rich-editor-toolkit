<?php

declare(strict_types=1);

namespace Asignua\RichEditorToolkit\Plugins;

use Asignua\RichEditorToolkit\Assets;
use Asignua\RichEditorToolkit\Extensions\IframeNode;
use Asignua\RichEditorToolkit\RichEditorToolkit;
use Asignua\RichEditorToolkit\Support\EmbedIframe;
use Asignua\RichEditorToolkit\Support\EmbedSource;
use Asignua\RichEditorToolkit\Support\VideoEmbed;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\EditorCommand;
use Filament\Forms\Components\RichEditor\Plugins\Contracts\RichContentPlugin;
use Filament\Forms\Components\RichEditor\RichEditorTool;
use Filament\Forms\Components\TextInput;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Tiptap\Core\Extension;

/**
 * An `<iframe>` node: keeps existing embeds (a migration from an old site, the source view)
 * and adds new ones with the "Embed" button — a YouTube / Vimeo link or any https URL from the
 * allow-list (`rich-editor-toolkit.embed.hosts`).
 *
 * What lands in the content is a bare `<iframe>` built from {@see EmbedIframe}, never the
 * string the user typed: a video link is parsed by {@see VideoEmbed} and rebuilt from provider
 * and id (youtube-nocookie.com, no autoplay). Every embed carries `sandbox`, `allow`,
 * `referrerpolicy` and `loading="lazy"`, forced again on render.
 *
 * Like {@see CustomAttributesPlugin}, this plugin has a server half and MUST be in the
 * front-end renderer too ({@see RichEditorToolkit::renderer()}): without it tiptap-php drops
 * the tag. The renderer keeps only iframes whose `src` passes {@see EmbedSource}, and only the
 * toolkit's sanitizer lets `<iframe>` through: Filament's shared one is left untouched.
 *
 * List `embed` in the editor's toolbar buttons to show the button.
 */
class EmbedPlugin implements RichContentPlugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    /**
     * @return array<Extension>
     */
    public function getTipTapPhpExtensions(): array
    {
        return [
            new IframeNode,
        ];
    }

    /**
     * @return array<string>
     */
    public function getTipTapJsExtensions(): array
    {
        return [
            Assets::url('embed', ['host' => EmbedSource::entries()]),
        ];
    }

    /**
     * @return array<RichEditorTool>
     */
    public function getEditorTools(): array
    {
        return [
            RichEditorTool::make('embed')
                ->label(__('rich-editor-toolkit::rich-editor-toolkit.embed'))
                ->icon(Heroicon::OutlinedVideoCamera)
                ->action(),
        ];
    }

    /**
     * @return array<Action>
     */
    public function getEditorActions(): array
    {
        return [
            Action::make('embed')
                ->label(__('rich-editor-toolkit::rich-editor-toolkit.embed'))
                ->modalWidth(Width::Medium)
                ->modalSubmitActionLabel(__('rich-editor-toolkit::rich-editor-toolkit.insert'))
                ->schema([
                    TextInput::make('url')
                        ->label(__('rich-editor-toolkit::rich-editor-toolkit.embed_url'))
                        ->helperText(__('rich-editor-toolkit::rich-editor-toolkit.embed_hint'))
                        ->required()
                        ->rules([
                            static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                                if (self::attributesFor((string) $value) === null) {
                                    $fail(__('rich-editor-toolkit::rich-editor-toolkit.embed_invalid'));
                                }
                            },
                        ]),
                ])
                ->action(function (array $data, RichEditor $component, array $arguments): void {
                    $attributes = self::attributesFor((string) $data['url']);

                    if ($attributes === null) {
                        return;
                    }

                    $component->runCommands(
                        [
                            EditorCommand::make('insertContent', arguments: [[
                                'type' => 'iframe',
                                'attrs' => $attributes,
                            ]]),
                        ],
                        editorSelection: $arguments['editorSelection'] ?? null,
                    );
                }),
        ];
    }

    /**
     * The iframe attributes for what the user typed, or null when it is neither a recognised
     * video link nor an allow-listed https URL.
     *
     * @return array<string, string>|null
     */
    public static function attributesFor(string $input): ?array
    {
        $input = trim($input);

        $video = VideoEmbed::parse($input);

        if ($video !== null) {
            return EmbedIframe::attributes($video['provider'], $video['id'], $video['start'], $video['hash']);
        }

        return EmbedSource::allows($input) ? EmbedIframe::genericAttributes($input) : null;
    }
}
