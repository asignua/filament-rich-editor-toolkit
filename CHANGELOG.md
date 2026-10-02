# Changelog

All notable changes to `asignua/filament-rich-editor-toolkit` are documented here.

## v1.0.0 - unreleased

- `PasteCleanPlugin`: cleans a paste from Word / Google Docs (structure kept; colours, fonts, backgrounds, class/id/style dropped) and a "Clear formatting" toolbar button that keeps headings, lists, links and custom blocks. Configurable list of link prefixes the button must keep.
- `CustomAttributesPlugin`: preserves `class`, `id`, `style` and an explicit allow-list of further attributes (`data-*`, `aria-*`, ...) in the browser schema and in the tiptap-php schema; keeps generic `<div>` containers, bare `<span>` and `<li><div>` structures.
- `EmbedPlugin`: `<iframe>` node and an "Embed" button for YouTube, Vimeo and allow-listed https hosts; embeds are rebuilt from provider and id, forced to carry `sandbox`, `allow`, `referrerpolicy` and `loading="lazy"`.
- `ImageUrlPlugin`: "Image by URL" button, optional host restriction.
- `StickyToolbarPlugin`: panel plugin (CSS only) that keeps the toolbar visible on long content and un-breaks `position: sticky` inside clipping sections, tabs and repeaters.
- `RichEditorToolkit::renderer()` / `plugins()` / `applySanitizerAllowances()` for the front end: attributes and embeds survive `RichContentRenderer::toHtml()`.
- Compiled ES modules in `resources/dist`, registered with `FilamentAsset` and loaded on request; node unit tests for the JS.
- Translations: English, Ukrainian, German, Spanish, French, Italian, Dutch, Polish, Brazilian Portuguese and Turkish.
- Laravel Boost guidelines.
