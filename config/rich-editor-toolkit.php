<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Custom attributes
    |--------------------------------------------------------------------------
    |
    | Attributes preserved on rich-content nodes by CustomAttributesPlugin. The list is
    | EXPLICIT on purpose: Symfony's HTML sanitizer, which Filament runs over every rendered
    | rich text, has no wildcard for attribute names (`data-*` cannot be allowed), so each name
    | must be spelled out. `class`, `id` and `style` are always preserved.
    |
    | Names that are never accepted, whatever you list: `on*` handlers, URL attributes
    | (`href`, `src`, `srcset`, `srcdoc`, `action`, `formaction`, `ping`, `poster`, ...), `is`,
    | any name with `:`, and framework directives (`x-*`, `hx-*`, `v-*`, `ng-*`, `wire:*`): the
    | panel runs Alpine over the editor. That list is a backstop, not a whitelist: allow only
    | what you need (`data-*`, `aria-*`, `role`, `title`, `lang`, `dir`).
    |
    | `types` adds node/mark types to the built-in list (paragraph, heading, list, table,
    | image, link, ...). A type missing from the list loses the attributes silently.
    |
    */

    'attributes' => [
        // 'data-track', 'data-aos', 'aria-label', 'role',
    ],

    'types' => [
        // 'myCustomNode',
    ],

    /*
    |--------------------------------------------------------------------------
    | Embeds
    |--------------------------------------------------------------------------
    |
    | EmbedPlugin accepts YouTube and Vimeo links out of the box (rewritten to the privacy
    | friendly youtube-nocookie.com / player.vimeo.com embed URLs). `hosts` allow further
    | https iframe sources: `host` or `host/path/prefix`. A prefix matches on a segment
    | boundary (`maps/embed` never accepts `/maps/embedded`), and a path with dot segments,
    | `%2e`, `%2f` or a backslash is refused.
    |
    | `sandbox` is forced onto every embed on render, whatever the stored HTML says. Set it to
    | null to emit no sandbox attribute (not recommended).
    |
    */

    'embed' => [
        'hosts' => [
            // 'www.google.com/maps/embed',
        ],
        'sandbox' => 'allow-scripts allow-same-origin allow-presentation allow-popups allow-popups-to-escape-sandbox',
        'allow' => 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share',
        'referrer_policy' => 'strict-origin-when-cross-origin',
        'lazy' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Paste cleanup
    |--------------------------------------------------------------------------
    |
    | The "Clear formatting" button keeps every link already in the content (relative
    | paths, `#anchors` and `tel:` included) except the script schemes `javascript:`,
    | `vbscript:`, `data:` and `file:`. Href prefixes listed here are kept even then;
    | a relative sentinel such as `/internal-link/` no longer needs to be listed.
    |
    */

    'paste_clean' => [
        'keep_link_prefixes' => [],

        // Strict paste: reduce a paste to what the field's toolbar offers (no table button ->
        // tables become paragraphs, no heading button -> paragraphs, no bold button -> bold is
        // unwrapped, ...). A per-field `PasteCleanPlugin::make()->strict()` overrides this.
        'strict' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Character counter
    |--------------------------------------------------------------------------
    |
    | `warn_at`: the share of a limit (0 to 1] from which CharacterCountPlugin's counter turns
    | amber; above the limit it turns red. A per-field `->warnAt()` overrides it.
    |
    */

    'character_count' => [
        'warn_at' => 0.9,
    ],

    /*
    |--------------------------------------------------------------------------
    | Fullscreen
    |--------------------------------------------------------------------------
    |
    | `z_index`: the z-index of the fullscreen overlay. null keeps the stylesheet's 35, which is
    | above Filament's topbar and sidebar (30) and below its modals (40) so that dialogs opened
    | from the fullscreen toolbar stay on top. A per-field `->zIndex()` overrides it.
    |
    */

    'fullscreen' => [
        'z_index' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Source (HTML) view
    |--------------------------------------------------------------------------
    |
    | `indent`: spaces per level in the HTML shown by SourceCodePlugin (0 to 8). A per-field
    | `->indent()` overrides it.
    |
    */

    'source_code' => [
        'indent' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Sticky toolbar
    |--------------------------------------------------------------------------
    |
    | Default offset of the sticky toolbar (a CSS length). The panel's own sticky topbar is
    | handled automatically; set a value only for a custom layout.
    |
    */

    'sticky_toolbar' => [
        'offset' => null,
    ],

];
