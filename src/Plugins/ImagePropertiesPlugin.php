<?php

declare(strict_types=1);

namespace Asignua\RichEditorToolkit\Plugins;

use Asignua\RichEditorToolkit\Assets;
use Asignua\RichEditorToolkit\Extensions\ImageProperties;
use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\EditorCommand;
use Filament\Forms\Components\RichEditor\Plugins\Contracts\RichContentPlugin;
use Filament\Forms\Components\RichEditor\RichEditorTool;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Tiptap\Core\Extension;

/**
 * Alt text, a caption and an alignment for the editor's images: an `imageProperties` toolbar
 * button (enabled while an image is selected) that opens a dialog.
 *
 * What Filament already does is NOT repeated: the stock "Attach files" dialog has an alt field
 * (and `resizableImages()` the width/height). What it cannot do, and this plugin adds:
 *
 *  - an explicit EMPTY alt (`alt=""`: "decorative, skip me"), which stock Filament cannot store: its dialog
 *    turns an empty field into "no alt" and the server-side node drops an empty value, so a screen
 *    reader reads the file name instead;
 *  - a plain-text caption;
 *  - `left` / `center` / `right` alignment.
 *
 * It extends the existing `image` node (a shadow of it, in the browser and in tiptap-php) and
 * does not replace it: `data-id`, resizing, `ImageUrlPlugin`, `CustomAttributesPlugin` (the
 * `class`/`style` of the `<img>`) and the source view keep working. See
 * {@see ImageProperties} for the markup.
 *
 * This plugin has a server half: it MUST also be in the front-end renderer
 * ({@see \Asignua\RichEditorToolkit\RichEditorToolkit::renderer()} adds it), or the caption and
 * the alignment are saved and then dropped on the page.
 *
 * To offer the button on the image itself, add it to the floating toolbar of the `image` node:
 *
 *     RichEditor::make('body')->floatingToolbars(fn (RichEditor $component): array => [
 *         ...$component->getDefaultFloatingToolbars(),
 *         'image' => ['imageProperties'],
 *     ])
 */
class ImagePropertiesPlugin implements RichContentPlugin
{
    public const int CAPTION_MAX_LENGTH = 500;

    public static function make(): static
    {
        return app(static::class);
    }

    /**
     * @return array<Extension>
     */
    public function getTipTapPhpExtensions(): array
    {
        return [new ImageProperties];
    }

    /**
     * @return array<string>
     */
    public function getTipTapJsExtensions(): array
    {
        return [Assets::url('image-properties', ['css' => [Assets::styleHref()]])];
    }

    /**
     * @return array<RichEditorTool>
     */
    public function getEditorTools(): array
    {
        return [
            RichEditorTool::make('imageProperties')
                ->label(__('rich-editor-toolkit::rich-editor-toolkit.image_properties'))
                ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                // The same trick as Filament's own "Attach files": `editorSelection` is added by
                // action(), the current attributes are read from the selected image.
                ->action(arguments: '{ alt: $getEditor().getAttributes(\'image\')?.alt ?? null, decorative: $getEditor().getAttributes(\'image\')?.alt === \'\', caption: $getEditor().getAttributes(\'image\')?.caption ?? null, align: $getEditor().getAttributes(\'image\')?.align ?? null }')
                ->activeKey('image')
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
            Action::make('imageProperties')
                ->label(__($prefix.'image_properties'))
                ->modalHeading(__($prefix.'image_properties'))
                ->modalWidth(Width::Large)
                ->modalSubmitActionLabel(__($prefix.'apply'))
                ->fillForm(fn (array $arguments): array => [
                    'decorative' => (bool) ($arguments['decorative'] ?? false),
                    'alt' => ($arguments['decorative'] ?? false) ? null : ($arguments['alt'] ?? null),
                    'caption' => $arguments['caption'] ?? null,
                    'align' => ImageProperties::cleanAlign($arguments['align'] ?? null) ?? 'none',
                ])
                ->schema([
                    Toggle::make('decorative')
                        ->label(__($prefix.'image_properties_decorative'))
                        ->helperText(__($prefix.'image_properties_decorative_hint'))
                        ->live(),
                    TextInput::make('alt')
                        ->label(__($prefix.'image_properties_alt'))
                        ->helperText(__($prefix.'image_properties_alt_hint'))
                        ->maxLength(1000)
                        ->disabled(fn (Get $get): bool => (bool) $get('decorative')),
                    TextInput::make('caption')
                        ->label(__($prefix.'image_properties_caption'))
                        ->helperText(__($prefix.'image_properties_caption_hint'))
                        ->maxLength(self::CAPTION_MAX_LENGTH),
                    ToggleButtons::make('align')
                        ->label(__($prefix.'image_properties_align'))
                        ->options([
                            'none' => __($prefix.'align_none'),
                            'left' => __($prefix.'align_left'),
                            'center' => __($prefix.'align_center'),
                            'right' => __($prefix.'align_right'),
                        ])
                        ->icons([
                            'none' => Heroicon::OutlinedNoSymbol,
                            'left' => Heroicon::OutlinedBars3BottomLeft,
                            'center' => Heroicon::OutlinedBars3,
                            'right' => Heroicon::OutlinedBars3BottomRight,
                        ])
                        ->inline()
                        ->required(),
                ])
                ->action(function (array $arguments, array $data, RichEditor $component): void {
                    $selection = $arguments['editorSelection'] ?? null;

                    // As in Filament's own attach-files action: the selection can arrive as a text
                    // selection one position after the image although the image is selected, and
                    // the update would then miss it.
                    if (is_array($selection) && ($selection['type'] ?? null) !== 'node') {
                        $selection['type'] = 'node';
                        $selection['anchor'] = ((int) ($selection['anchor'] ?? 1)) - 1;

                        unset($selection['head']);
                    }

                    $component->runCommands(
                        [
                            EditorCommand::make('updateAttributes', arguments: ['image', self::attributesFrom($data)]),
                        ],
                        editorSelection: $selection,
                    );
                }),
        ];
    }

    /**
     * The node attributes for what the dialog sent. `alt` is '' for a decorative image, null
     * when nothing was typed (a MISSING alt: the editor can tell the two apart, a screen reader
     * cannot, so the README asks for one or the other).
     *
     * @param array<string, mixed> $data
     *
     * @return array{alt: string|null, caption: string|null, align: string|null}
     */
    public static function attributesFrom(array $data): array
    {
        $alt = trim((string) ($data['alt'] ?? ''));

        return [
            'alt' => ($data['decorative'] ?? false) ? '' : ($alt === '' ? null : $alt),
            'caption' => ImageProperties::cleanCaption($data['caption'] ?? null),
            'align' => ImageProperties::cleanAlign($data['align'] ?? null),
        ];
    }
}
