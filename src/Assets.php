<?php

declare(strict_types=1);

namespace Asignua\RichEditorToolkit;

use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;

/**
 * The compiled editor modules in `resources/dist`.
 *
 * They are registered with `FilamentAsset` as `loadedOnRequest()`: Filament publishes them with
 * `php artisan filament:assets` but never prints a `<script>` for them. The editor imports them
 * dynamically by URL (RichContentPlugin::getTipTapJsExtensions()), so a page without a rich
 * editor pays nothing.
 */
final class Assets
{
    public const string PACKAGE = 'asignua/filament-rich-editor-toolkit';

    public const array MODULES = ['paste-clean', 'custom-attributes', 'embed'];

    /**
     * @return list<Js>
     */
    public static function scripts(): array
    {
        return array_map(
            static fn (string $name): Js => Js::make($name, __DIR__.'/../resources/dist/'.$name.'.js')->loadedOnRequest(),
            self::MODULES,
        );
    }

    /**
     * The URL of a module, with the settings the module reads from its own query string.
     *
     * @param array<string, list<string>> $params repeated query parameters, e.g. ['attr' => ['data-track']]
     */
    public static function url(string $module, array $params = []): string
    {
        $url = FilamentAsset::getScriptSrc($module, self::PACKAGE);

        $query = [];

        foreach ($params as $name => $values) {
            foreach ($values as $value) {
                $query[] = rawurlencode($name).'='.rawurlencode($value);
            }
        }

        return $query === [] ? $url : $url.(str_contains($url, '?') ? '&' : '?').implode('&', $query);
    }
}
