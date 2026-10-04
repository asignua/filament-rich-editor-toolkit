# Changelog

All notable changes to `asignua/filament-rich-editor-toolkit` are documented here.

## Unreleased

- `PasteCleanPlugin`: the "Clear formatting" button no longer destroys nodes the editor itself produced. Embeds (`<iframe>` from `EmbedPlugin`), images (also `ImageUrlPlugin` images and images on another host) and code blocks in the selection are kept as they are; before, the iframe was dropped, the image removed by the clipboard image triage and a code block flattened into one paragraph.
- `PasteCleanPlugin`: the button keeps every link of existing content (relative paths, `#anchors`, `tel:`) and refuses only `javascript:`, `vbscript:`, `data:` and `file:`; `keepLinkPrefixes()` is no longer needed for relative sentinels. A paste now keeps `tel:` links as well; relative and `#` links in a paste are still turned into text.
- Docs: a name a node declares itself wins over `CustomAttributesPlugin`'s, so `id` on an `<img>` (Filament's image node owns it as the media key) is not kept; `class`/`style` are. A test pins it.

## v1.0.0 - 2026-10-03

- `PasteCleanPlugin`: cleans a paste from Word / Google Docs (structure kept; colours, fonts, backgrounds, class/id/style dropped) and a "Clear formatting" toolbar button that keeps headings, lists, links and custom blocks. Configurable list of link prefixes the button must keep.
- `CustomAttributesPlugin`: preserves `class`, `id`, `style` and an explicit allow-list of further attributes (`data-*`, `aria-*`, ...) in the browser schema and in the tiptap-php schema; keeps generic `<div>` containers, bare `<span>` and `<li><div>` structures.
- `EmbedPlugin`: `<iframe>` node and an "Embed" button for YouTube, Vimeo and allow-listed https hosts; embeds are rebuilt from provider and id, forced to carry `sandbox`, `allow`, `referrerpolicy` and `loading="lazy"`.
- `ImageUrlPlugin`: "Image by URL" button, optional host restriction.
- `StickyToolbarPlugin`: panel plugin (CSS only) that keeps the toolbar visible on long content and un-breaks `position: sticky` inside clipping sections, tabs and repeaters.
- `RichEditorToolkit::renderer()` / `plugins()` / `toHtml()` / `sanitizer()` for the front end: attributes and embeds survive rendering through the toolkit's own sanitizer.
- Security hardening before the first release:
  - Filament's shared HTML sanitizer is no longer extended. The toolkit renders through its own sanitizer (a copy of Filament's config), so `TextColumn::html()`, `TextEntry::html()`, notifications and the Markdown editor never let `<iframe>` or the extra attributes through. A renderer you build yourself must be rendered with `RichEditorToolkit::toHtml($renderer)`; `RichEditorToolkit::allowEmbeds()` is a deprecated no-op.
  - The iframe `src` allow-list is checked when a node is rendered too (JSON content never passes through `parseHTML()`), and once more by the toolkit's sanitizer.
  - Embed path prefixes match on a segment boundary; paths with dot segments, `%2e`, `%2f` or backslashes are refused (PHP and JS agree, scheme is case-insensitive on both sides).
  - Attribute names: framework directives (`x-*`, `hx-*`, `v-*`, `ng-*`, `wire:*`), names with `:`, `is`, `srcset`, `ping`, `poster`, `background`, `formtarget`, `xmlns*` and similar are never accepted.
  - `CustomAttributesPlugin::attributes()` no longer registers its names globally; pass the same list to `RichEditorToolkit::renderer($html, attributes: [...], types: [...])`.
- Unlisted Vimeo videos keep their privacy hash (`?h=`).
- Sticky toolbar follows the panel's colour variables and leaves containers whose editors all opted out alone.
- Compiled ES modules in `resources/dist`, registered with `FilamentAsset` and loaded on request; node unit tests for the JS.
- Translations: English, Ukrainian, German, Spanish, French, Italian, Dutch, Polish, Brazilian Portuguese and Turkish.
- Laravel Boost guidelines.
