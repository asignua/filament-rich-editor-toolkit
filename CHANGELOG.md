# Changelog

All notable changes to `asignua/filament-rich-editor-toolkit` are documented here.

## Unreleased

- Dependencies: jsdom 30, esbuild 0.28 (dev); the built assets are unchanged.
- Clean format: a selection that spans a custom block is also put back as an open slice, so its first and last paragraphs are no longer split. The open ends are clamped to the depth of the cleaned first/last node (a custom block is closed; a flattened wrapper such as a `<div>` no longer leaves an end deeper than the node), and this is covered by tests on a real `prosemirror-model` schema (`prosemirror-model` is now a dev dependency).
- `EmbedPlugin` (security): the editor no longer draws an `<iframe>` it did not vet. A node built from JSON (`RichEditor::json()` stores a posted document as is) with a `javascript:` or foreign `src` is replaced by an inert placeholder in the panel, and the configured `sandbox`, `allow` and `referrerpolicy` are forced in the editor as they are on render; before, such a node ran in the panel's origin for every admin who opened the record.
- `EmbedPlugin`: YouTube's own embed code (`www.youtube.com/embed/ID`), `watch?v=`, `youtu.be` and `vimeo.com/ID` iframes from the source view, a paste or migrated content are rebuilt to the built-in `youtube-nocookie.com` / `player.vimeo.com` form instead of being dropped (`EmbedIframe::canonicalSrc()`, mirrored in the browser). A playlist (`embed/videoseries`) or channel (`embed/live_stream`) URL is no longer taken for a video id.
- `CustomAttributesPlugin`: a `<div>` that wraps an `<iframe>` (the responsive embed wrapper) is a block container, so the iframe stays inside it instead of being lifted out and leaving an empty div.
- `CustomAttributesPlugin`: the classes and declarations another extension writes itself are dropped only on the tag it writes them on (`text-align` on `p`/`h1`-`h6`, `width`/`height` on `img`, `--color` on `span`, `--cols`/`grid-layout`/`lead` on `div`). A hand-written `text-align` or `width` on a `td`, `table`, `div` or `iframe`, and Bootstrap's `<p class="lead">`, are kept; before they were lost silently.
- `CustomAttributesPlugin`: the span mark no longer captures Filament's mention and merge-tag spans (`data-type`) when HTML is parsed in the browser; a copied merge tag came back as plain text with its `data-id` lost.
- `PasteCleanPlugin`: "Clear formatting" puts the result back as an open slice like a paste, so a few words picked out of a paragraph stay in it instead of splitting it into three; with a table cell selection it does nothing instead of wrecking the table.
- `PasteCleanPlugin`: "Clear formatting" keeps what the editor produced: mentions and merge tags, grid and columns, details, the lead paragraph, and a link's `target="_blank"` / `rel`.
- `PasteCleanPlugin`: a space inside a link `href` becomes `%20` instead of being removed (`Shared Documents/Plan 2026.docx` no longer turns into a different address); the script-scheme check still ignores spaces and control characters.
- `ImageUrlPlugin`: a valid address on a host outside `hosts()` gets its own message (`image_url_host_not_allowed`, all 10 locales) instead of "Enter a full http(s) address"; the button uses a photo icon, not the chain icon of the stock Link button.
- `StickyToolbarPlugin`: a per-field `RichEditor::stickyOffset()` wins over the plugin offset, and the plugin offset no longer applies inside a modal, where Filament's measured header height is used (the plugin offset stays ahead of Filament's default, so `offset()` works outside modals).

## v1.0.1 - 2026-10-05

- `PasteCleanPlugin`: the "Clear formatting" button no longer destroys nodes the editor itself produced. Embeds (`<iframe>` from `EmbedPlugin`), images (also `ImageUrlPlugin` images and images on another host) and code blocks in the selection are kept as they are; before, the iframe was dropped, the image removed by the clipboard image triage and a code block flattened into one paragraph.
- `PasteCleanPlugin`: the button keeps every link of existing content (relative paths, `#anchors`, `tel:`) and refuses only `javascript:`, `vbscript:`, `data:` and `file:`; `keepLinkPrefixes()` is no longer needed for relative sentinels. A paste now keeps `tel:` links as well; relative and `#` links in a paste are still turned into text.
- `EmbedPlugin`: a `host/path` allow-list entry keeps the case of its path (only the host is lowercased), so an entry with capitals in the path, such as a Google Forms id, matches again; before, it never matched and the embed was refused.
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
