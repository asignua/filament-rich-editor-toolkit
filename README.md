# Filament Rich Editor Toolkit

[![Stand With Ukraine](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/badges/StandWithUkraine.svg)](https://stand-with-ukraine.pp.ua)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/asignua/filament-rich-editor-toolkit.svg?style=flat-square)](https://packagist.org/packages/asignua/filament-rich-editor-toolkit)
[![Tests](https://img.shields.io/github/actions/workflow/status/asignua/filament-rich-editor-toolkit/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/asignua/filament-rich-editor-toolkit/actions/workflows/tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/asignua/filament-rich-editor-toolkit.svg?style=flat-square)](https://packagist.org/packages/asignua/filament-rich-editor-toolkit)
[![License](https://img.shields.io/packagist/l/asignua/filament-rich-editor-toolkit.svg?style=flat-square)](https://github.com/asignua/filament-rich-editor-toolkit/blob/main/LICENSE.md)
[![Plumb score](https://plumbphp.dev/badges/asignua/filament-rich-editor-toolkit/composite.svg)](https://plumbphp.dev/asignua/filament-rich-editor-toolkit)

<img class="filament-hidden" src="https://raw.githubusercontent.com/asignua/filament-rich-editor-toolkit/v1.0.0/art/cover.jpg" alt="Filament Rich Editor Toolkit">

Five free plugins for the [Filament](https://filamentphp.com) 5 `RichEditor` (TipTap) that fix the problems people keep
reporting in Filament's GitHub Help:

| Plugin | Fixes |
| --- | --- |
| `PasteCleanPlugin` | Pasting from Word / Google Docs drags in colours, fonts, `mso-*` junk and `<span style>` soup. |
| `CustomAttributesPlugin` | `class`, `id`, `style` and `data-*` written in the source view are silently stripped, in the browser **and** on the server. |
| `EmbedPlugin` | `<iframe>` / video embeds are removed by the schema and the sanitizer. |
| `ImageUrlPlugin` | No way to insert an image that lives on another site. |
| `StickyToolbarPlugin` | The toolbar scrolls away on long content ([#17391](https://github.com/filamentphp/filament/discussions/17391), [#16919](https://github.com/filamentphp/filament/discussions/16919), [#11685](https://github.com/filamentphp/filament/discussions/11685)). |

Each plugin works alone; take only what you need.

- [Screenshots](#screenshots)
- [Requirements](#requirements)
- [Installation](#installation)
- [Usage](#usage)
- [Rendering on the front end](#rendering-on-the-front-end)
- [Configuration](#configuration)
- [Gotchas](#gotchas)
- [Translations](#translations)
- [AI agents](#ai-agents)
- [Testing](#testing)

## Screenshots

![Word HTML pasted and cleaned, structure kept](https://raw.githubusercontent.com/asignua/filament-rich-editor-toolkit/v1.0.0/art/paste-clean.jpg)

![The Embed dialog accepting a YouTube link](https://raw.githubusercontent.com/asignua/filament-rich-editor-toolkit/v1.0.0/art/embed.jpg)

![The toolbar stays visible while scrolling a long document](https://raw.githubusercontent.com/asignua/filament-rich-editor-toolkit/v1.0.0/art/sticky-toolbar.jpg)

## Requirements

- PHP 8.3+
- Filament 5
- Laravel 12 or 13

## Installation

```bash
composer require asignua/filament-rich-editor-toolkit
php artisan filament:assets
```

`filament:assets` publishes the compiled editor modules. They are `loadedOnRequest()`: no `<script>` is printed on a page
without a rich editor, the editor imports them by URL.

Optionally publish the config and the translations:

```bash
php artisan vendor:publish --tag=filament-rich-editor-toolkit-config
php artisan vendor:publish --tag=filament-rich-editor-toolkit-translations
```

## Usage

```php
use Asignua\RichEditorToolkit\Plugins\CustomAttributesPlugin;
use Asignua\RichEditorToolkit\Plugins\EmbedPlugin;
use Asignua\RichEditorToolkit\Plugins\ImageUrlPlugin;
use Asignua\RichEditorToolkit\Plugins\PasteCleanPlugin;
use Filament\Forms\Components\RichEditor;

RichEditor::make('body')
    ->plugins([
        PasteCleanPlugin::make(),
        CustomAttributesPlugin::make(),
        EmbedPlugin::make(),
        ImageUrlPlugin::make(),
    ])
    ->toolbarButtons([
        ['bold', 'italic', 'link', 'cleanFormat'],
        ['h2', 'h3', 'bulletList', 'orderedList'],
        ['embed', 'imageUrl'],
    ]);
```

A toolbar button is shown only when it is listed in `toolbarButtons()` (`cleanFormat`, `embed`, `imageUrl`); an unknown
name makes Filament throw, so list only the plugins you installed.

### PasteCleanPlugin

On Ctrl+V the structure stays (paragraphs, headings, lists, tables, links, bold/italic/underline) and colours, font
sizes, backgrounds and every `class`/`id`/`style` they carry disappear. A paste from **inside** an editor is never
touched (the `data-pm-slice` cutoff), so it composes with `CustomAttributesPlugin`.

The `cleanFormat` button runs the same cleanup on the **selected** fragment of existing content. Unlike Filament's
`clearFormatting` it keeps headings and lists (that one runs `clearNodes().unsetAllMarks()`). Custom blocks survive; to
keep your own sentinel links, list their prefixes:

```php
PasteCleanPlugin::make()->keepLinkPrefixes(['/internal-link/'])
```

Images pasted from Word and Google Docs are removed (Filament uploads only `data:` images from the clipboard); add them
through the upload button.

### CustomAttributesPlugin

Keeps `class`, `id`, `style` and further attributes you list, on paragraphs, headings, lists, tables, images, links,
details, grid, spans and generic `<div>` containers. It also stops legacy markup from collapsing: `<div class="row"><img>
<span>…</span></div>` keeps its direct children, and `<li><div>…</div></li>` stays a list item.

```php
// config/rich-editor-toolkit.php
'attributes' => ['data-track', 'aria-label', 'role'],
'types' => ['myCustomNode'],
```

The list is explicit because Symfony's HTML sanitizer, which Filament runs over every rendered rich text, cannot allow
`data-*` as a pattern. `on*`, `href`, `src`, `srcdoc`, `action` and `formaction` are never accepted.

### EmbedPlugin

The "Embed" button takes a YouTube / Vimeo link (including `youtu.be`, Shorts, timecodes) or an https address from your
allow-list, and inserts a bare `<iframe>`. A video link is **rebuilt** from provider and id
(`youtube-nocookie.com`, no autoplay), never copied. Pasted or hand-written iframes pass the same check at once in the
browser and when the HTML is parsed on the server; an iframe from any other host does not exist as a node and is dropped.

```php
'embed' => [
    'hosts' => ['www.google.com/maps/embed'],   // host or host/path/prefix, https only
    'sandbox' => 'allow-scripts allow-same-origin allow-presentation allow-popups',
],
```

`sandbox`, `allow`, `referrerpolicy` and `loading="lazy"` are forced onto every embed on render, whatever the stored HTML
says.

### ImageUrlPlugin

Inserts an `<img>` that is loaded from the other site, not copied. Only absolute http(s) URLs are accepted;
`ImageUrlPlugin::make()->hosts(['cdn.example.com'])` restricts the sources.

### StickyToolbarPlugin

A panel plugin, CSS only:

```php
use Asignua\RichEditorToolkit\StickyToolbarPlugin;

$panel->plugin(StickyToolbarPlugin::make()->offset('4rem'));
```

Filament 5 has `RichEditor::stickyToolbar()` per field; this makes it the default for every editor and fixes the case
where it silently does nothing, because a section, tab or repeater item clips with `overflow: hidden` (which turns it
into a scroll container that `position: sticky` cannot escape). Those containers are switched to `overflow: clip`.
Run `php artisan filament:assets` so the stylesheet is published.

Opt a single field out with `RichEditor::make('body')->withoutStickyToolbar()` (it sets `data-toolkit-sticky="off"`, which the stylesheet respects, even over Filament's native `->stickyToolbar()`).

## Rendering on the front end

`RichContentRenderer` parses the stored HTML with the same tiptap-php schema and then sanitizes it, and both steps drop
what they do not know. Use the helper:

```blade
{!! \Asignua\RichEditorToolkit\RichEditorToolkit::renderer($post->body)->toHtml() !!}
```

or attach the plugins to a renderer you build yourself:

```php
RichContentRenderer::make($post->body)
    ->customBlocks([...])
    ->plugins(RichEditorToolkit::plugins());
```

This adds `CustomAttributesPlugin` and `EmbedPlugin` and extends Filament's sanitizer config (`class`/`style` are
already allowed by Filament; the toolkit adds your attribute allow-list and, once `EmbedPlugin` is in use, `<iframe>`
restricted to the allow-listed sources). **Do not add `PasteCleanPlugin` to the renderer**: the cleanup happens once, at
paste time.

## Configuration

See [`config/rich-editor-toolkit.php`](config/rich-editor-toolkit.php): `attributes`, `types`, `embed`, `paste_clean`,
`sticky_toolbar`.

## Gotchas

- **Both halves, always.** `CustomAttributesPlugin` and `EmbedPlugin` must be in the editor *and* in the renderer. Miss
  the renderer and the data sits in the database and vanishes on the page.
- **Publish the assets.** A missing `filament:assets` means a 404 for the module URL and a `console.error` from
  Filament's loader. Nothing else fails, the plugin is just absent.
- **Node types.** An attribute is kept only on the node types in the list (`Extensions\CustomAttributes::TYPES` plus the
  `types` config). The PHP and the JS lists are identical on purpose; a test pins it.
- **The sanitizer is global.** Allowances apply to every `RichContentRenderer` in the app, not only to the toolkit's.
  `<iframe>` is allowed only after `EmbedPlugin` is instantiated (or `RichEditorToolkit::allowEmbeds()` is called).
- **`toolbarButtons()`.** List `cleanFormat`, `embed`, `imageUrl` only when the matching plugin is registered.

## Translations

English, Ukrainian, German, Spanish, French, Italian, Dutch, Polish, Brazilian Portuguese and Turkish. The namespace is
`rich-editor-toolkit::`.

## AI agents

Laravel Boost guidelines ship in `resources/boost/guidelines/core.blade.php`.

## Testing

```bash
composer test          # PHP (Testbench)
composer analyse       # PHPStan level 8
vendor/bin/pint --test
npm install
npm run test:js        # node --test + jsdom
npm run build          # recompile resources/dist (commit the result)
```

## Changelog and license

[CHANGELOG](https://github.com/asignua/filament-rich-editor-toolkit/blob/main/CHANGELOG.md) ·
[MIT license](https://github.com/asignua/filament-rich-editor-toolkit/blob/main/LICENSE.md)
