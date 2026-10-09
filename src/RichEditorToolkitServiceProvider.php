<?php

declare(strict_types=1);

namespace Asignua\RichEditorToolkit;

use Asignua\RichEditorToolkit\Plugins\CharacterCountPlugin;
use Asignua\RichEditorToolkit\Rules\RichTextLength;
use Filament\Forms\Components\RichEditor;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class RichEditorToolkitServiceProvider extends PackageServiceProvider
{
    public static string $name = 'filament-rich-editor-toolkit';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(static::$name)
            ->hasConfigFile('rich-editor-toolkit');
    }

    public function packageBooted(): void
    {
        // Opt a single field out of StickyToolbarPlugin; the stylesheet respects the attribute.
        if (!RichEditor::hasMacro('withoutStickyToolbar')) {
            RichEditor::macro('withoutStickyToolbar', function (bool $condition = true): RichEditor {
                /** @phpstan-ignore-next-line variable.undefined, varTag.nativeType (Macroable rebinds $this) */
                return $condition ? $this->extraAttributes(['data-toolkit-sticky' => 'off'], merge: true) : $this;
            });
        }

        // `->textLength(max: 500)`: the plain-text length rule AND the counter in one call.
        // `plugins()` appends in Filament, so this composes with the plugins already set.
        if (!RichEditor::hasMacro('textLength')) {
            RichEditor::macro('textLength', function (?int $max = null, ?int $maxWords = null, ?int $min = null, bool $counter = true, ?bool $showWords = null): RichEditor {
                /** @phpstan-ignore-next-line variable.undefined (Macroable rebinds $this) */
                $editor = $this->rule(new RichTextLength(max: $max, maxWords: $maxWords, min: $min));

                if ($counter) {
                    $editor->plugins([
                        CharacterCountPlugin::make()
                            ->maxCharacters($max)
                            ->maxWords($maxWords)
                            ->showWords($showWords ?? $maxWords !== null),
                    ]);
                }

                return $editor;
            });
        }

        // The namespace is `rich-editor-toolkit::` (without the `filament-` prefix of the
        // package name), which is why hasTranslations() is not used.
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'rich-editor-toolkit');

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../resources/lang' => $this->app->langPath('vendor/rich-editor-toolkit')], 'filament-rich-editor-toolkit-translations');

            // Optional front-end styles of ImagePropertiesPlugin's alignment and caption classes.
            $this->publishes([__DIR__.'/../resources/css/image-properties.css' => public_path('vendor/filament-rich-editor-toolkit/image-properties.css')], 'filament-rich-editor-toolkit-front-css');
        }

        // Published by `php artisan filament:assets`; never printed on its own. The editor
        // imports the JS modules by URL; the sticky stylesheet is linked by StickyToolbarPlugin and the
        // UI stylesheet (fullscreen, counter) by the two modules that need it.
        FilamentAsset::register([
            ...Assets::scripts(),
            Css::make(StickyToolbarPlugin::STYLESHEET, __DIR__.'/../resources/dist/filament-rich-editor-toolkit.css')->loadedOnRequest(),
            Css::make(Assets::UI_STYLESHEET, __DIR__.'/../resources/dist/filament-rich-editor-toolkit-ui.css')->loadedOnRequest(),
        ], Assets::PACKAGE);
    }
}
