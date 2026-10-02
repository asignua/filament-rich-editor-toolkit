<?php

declare(strict_types=1);

namespace Asignua\RichEditorToolkit;

use Filament\Forms\Components\RichEditor;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

class RichEditorToolkitServiceProvider extends PackageServiceProvider
{
    public static string $name = 'filament-rich-editor-toolkit';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(static::$name)
            ->hasConfigFile('rich-editor-toolkit');
    }

    public function packageRegistered(): void
    {
        // Filament sanitizes every rendered rich text (RichContentRenderer::toHtml()) and its
        // default config drops `<iframe>` and every attribute but class/style/data-color/...
        // The extender runs when the scoped config is first resolved, after boot().
        $this->app->extend(
            HtmlSanitizerConfig::class,
            static fn (HtmlSanitizerConfig $config): HtmlSanitizerConfig => RichEditorToolkit::applySanitizerAllowances($config),
        );
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

        // The namespace is `rich-editor-toolkit::` (without the `filament-` prefix of the
        // package name), which is why hasTranslations() is not used.
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'rich-editor-toolkit');

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../resources/lang' => $this->app->langPath('vendor/rich-editor-toolkit')], 'filament-rich-editor-toolkit-translations');
        }

        // Published by `php artisan filament:assets`; never printed on its own. The editor
        // imports the JS modules by URL, and the stylesheet is linked by StickyToolbarPlugin.
        FilamentAsset::register([
            ...Assets::scripts(),
            Css::make(StickyToolbarPlugin::STYLESHEET, __DIR__.'/../resources/dist/filament-rich-editor-toolkit.css')->loadedOnRequest(),
        ], Assets::PACKAGE);
    }
}
