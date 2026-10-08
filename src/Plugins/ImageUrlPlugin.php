<?php

declare(strict_types=1);

namespace Asignua\RichEditorToolkit\Plugins;

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
 * "Image by URL": an `<img>` pointing at another site, next to the upload button.
 *
 * The picture is loaded from where it lives, not copied. Nothing is uploaded or stored, which
 * is the cheap path for a partner's photo; uploading to a media library is a separate decision.
 *
 * The node has no `id`: both Filament's check against file-path substitution and media-library
 * image nodes look only at images that carry one.
 *
 * Only absolute http(s) URLs are accepted: a path or a protocol-relative URL would be resolved
 * against whatever host renders the content. {@see self::hosts()} limits what the DIALOG
 * accepts; it is not a content filter: an `<img>` typed in the source view, pasted, or posted
 * as JSON is never checked against it. `http://` is accepted and is mixed content on an https
 * site.
 *
 * List `imageUrl` in the editor's toolbar buttons to show the button. No server half: the
 * stock image node already renders it.
 */
class ImageUrlPlugin implements RichContentPlugin
{
    /** @var list<string>|null */
    protected ?array $hosts = null;

    public static function make(): static
    {
        return app(static::class);
    }

    /**
     * Allow only these hosts in the dialog (exact, case-insensitive). Default: any host. The
     * stored content is not filtered by it.
     *
     * @param list<string> $hosts
     */
    public function hosts(array $hosts): static
    {
        $this->hosts = array_map(static fn (string $host): string => strtolower($host), $hosts);

        return $this;
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
            RichEditorTool::make('imageUrl')
                ->label(__('rich-editor-toolkit::rich-editor-toolkit.image_url'))
                ->icon(Heroicon::OutlinedPhoto)
                ->action(),
        ];
    }

    /**
     * @return array<Action>
     */
    public function getEditorActions(): array
    {
        return [
            Action::make('imageUrl')
                ->label(__('rich-editor-toolkit::rich-editor-toolkit.image_url'))
                ->modalWidth(Width::Medium)
                ->modalSubmitActionLabel(__('rich-editor-toolkit::rich-editor-toolkit.insert'))
                ->schema([
                    TextInput::make('url')
                        ->label(__('rich-editor-toolkit::rich-editor-toolkit.image_url_url'))
                        ->helperText(__('rich-editor-toolkit::rich-editor-toolkit.image_url_hint'))
                        ->required()
                        ->rules([
                            fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                if (($message = $this->errorFor((string) $value)) !== null) {
                                    $fail($message);
                                }
                            },
                        ]),
                    TextInput::make('alt')
                        ->label(__('rich-editor-toolkit::rich-editor-toolkit.image_url_alt')),
                ])
                ->action(function (array $data, RichEditor $component, array $arguments): void {
                    $component->runCommands(
                        [
                            EditorCommand::make('insertContent', arguments: [[
                                'type' => 'image',
                                'attrs' => [
                                    'src' => trim((string) $data['url']),
                                    'alt' => filled($data['alt'] ?? null) ? trim((string) $data['alt']) : null,
                                ],
                            ]]),
                        ],
                        editorSelection: $arguments['editorSelection'] ?? null,
                    );
                }),
        ];
    }

    /**
     * The validation message for what was typed into the dialog, or null when it is accepted.
     * A valid address on another host gets its own message: "enter a full address" would be
     * false, and the editor would not learn that the site restricts hosts.
     */
    public function errorFor(string $value): ?string
    {
        if (!$this->isHttpUrl($value)) {
            return (string) __('rich-editor-toolkit::rich-editor-toolkit.image_url_invalid');
        }

        return $this->accepts($value) ? null : (string) __('rich-editor-toolkit::rich-editor-toolkit.image_url_host_not_allowed');
    }

    /**
     * A full http(s) address, whatever its host.
     */
    private function isHttpUrl(string $value): bool
    {
        $value = trim($value);

        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true);
    }

    public function accepts(string $value): bool
    {
        $value = trim($value);

        if (!$this->isHttpUrl($value)) {
            return false;
        }

        if ($this->hosts === null) {
            return true;
        }

        return in_array(strtolower((string) parse_url($value, PHP_URL_HOST)), $this->hosts, true);
    }
}
