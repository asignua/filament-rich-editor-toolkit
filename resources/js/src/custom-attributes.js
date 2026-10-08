// Mirror of the PHP extension Asignua\RichEditorToolkit\Extensions\CustomAttributes: keeps
// `class`, `id`, `style` (and any further allow-listed attributes such as `data-track`) in the
// editor schema. The PHP and JS sides must agree on the node types and the owned classes/styles,
// otherwise an attribute survives a save and disappears the next time the form is opened (or
// the other way round).
//
// No build step is needed for Filament's own TipTap: it exports the core on
// `window.FilamentRichEditor.tiptap.core`, which is read inside the factory only.
//
// Configuration (node types, extra attributes) arrives in the module URL, see ./config.js.

import { readList } from './config.js'

export const DEFAULT_TYPES = [
    'paragraph',
    'heading',
    'blockquote',
    'bulletList',
    'orderedList',
    'listItem',
    'codeBlock',
    'horizontalRule',
    'table',
    'tableRow',
    'tableHeader',
    'tableCell',
    'image',
    'iframe',
    'lead',
    'details',
    'detailsSummary',
    'detailsContent',
    'grid',
    'gridColumn',
    'customDiv',
    'customDivInline',
    'link',
    'textColor',
    'small',
    'customSpan',
]

// Classes that another Filament extension writes itself, with the tags that extension exists
// for: `textColor` adds `color` to a span, `lead` adds `lead` to a div, `grid` adds
// `grid-layout` to a div. HTML merging JOINS classes instead of replacing them, so without this
// filter the output would be `class="color color"`. On any other tag the class is the author's.
export const OWNED_CLASSES = {
    color: ['span'],
    lead: ['div'],
    'grid-layout': ['div'],
}

// Same for declarations: `text-align` belongs to TextAlign (paragraph and headings only),
// `--color`/`--dark-color` to textColor, `--cols` to grid, `height`/`width` to image. A
// hand-written `text-align: center` on a <p> is not lost: TextAlign picks it up. On a <td> or a
// <div> nobody owns it, so it stays in `style`.
export const OWNED_STYLES = {
    'text-align': ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'],
    '--color': ['span'],
    '--dark-color': ['span'],
    '--cols': ['div'],
    height: ['img'],
    width: ['img'],
}

const isOwned = (table, key, tag) => (Object.hasOwn(table, key) ? table[key] : []).includes(tag)

export const filterClass = (value, tag = '') => {
    const classes = (value || '')
        .split(/\s+/)
        .filter((name) => name !== '' && !isOwned(OWNED_CLASSES, name, tag))

    return classes.length ? classes.join(' ') : null
}

export const filterStyle = (value, tag = '') => {
    const declarations = (value || '')
        .split(';')
        .map((declaration) => declaration.trim())
        .filter((declaration) => {
            if (declaration === '') {
                return false
            }

            return !isOwned(OWNED_STYLES, declaration.split(':')[0].trim().toLowerCase(), tag)
        })

    return declarations.length ? declarations.join('; ') : null
}

export const blankToNull = (value) => {
    const trimmed = (value || '').trim()

    return trimmed === '' ? null : trimmed
}

const parseValue = (name, element) => {
    const value = element.getAttribute(name)
    const tag = element.tagName.toLowerCase()

    if (name === 'class') {
        return filterClass(value, tag)
    }

    if (name === 'style') {
        return filterStyle(value, tag)
    }

    return blankToNull(value)
}

export const BLOCK_TAGS =
    'address,article,aside,blockquote,details,div,dl,fieldset,figcaption,figure,footer,' +
    'form,h1,h2,h3,h4,h5,h6,header,hr,iframe,li,main,nav,ol,p,pre,section,table,ul'

export const hasBlockChild = (element) => element.querySelector(BLOCK_TAGS) !== null

/**
 * Builds the global attribute definitions for the given attribute names.
 *
 * @param {string[]} names
 */
export const buildAttributes = (names) =>
    Object.fromEntries(
        names.map((name) => [
            name,
            {
                default: null,
                parseHTML: (element) => parseValue(name, element),
                renderHTML: (attributes) => (attributes[name] ? { [name]: attributes[name] } : {}),
            },
        ]),
    )

export default () => {
    const { Extension, Mark, Node, mergeAttributes } = window.FilamentRichEditor.tiptap.core

    const types = readList(import.meta.url, 'type')
    const extra = readList(import.meta.url, 'attr')
    const names = [...new Set(['class', 'id', 'style', ...extra])]

    const CustomSpan = Mark.create({
        name: 'customSpan',
        priority: 50,
        parseHTML() {
            return [
                {
                    tag: 'span',
                    // Any span except someone else's: textColor owns span.color, and a span with
                    // data-type is a node (mention, merge tag). ProseMirror tries every MARK rule
                    // before the NODE rules of the same priority, so without this skip a copied
                    // merge tag would come back as plain text with a mark. A bare <span>
                    // with no attributes is kept too: in legacy markup it is often a CSS hook
                    // on its own (`.bg-primary span { … }`).
                    getAttrs: (element) =>
                        element.classList?.contains('color') || element.hasAttribute('data-type') ? false : {},
                },
            ]
        },
        renderHTML({ HTMLAttributes }) {
            return ['span', HTMLAttributes, 0]
        },
    })

    // Two div types instead of one. A ProseMirror node is either block-level ('block+') or
    // inline ('inline*'), never both, so a single type cannot cover both <div><p>…</p></div>
    // and <div><img> <span>…</span></div>. With only the block type, inline content was wrapped
    // in a <p>, the image and the text stopped being direct children of the div, and flex/grid
    // layouts from old sites broke silently.
    //
    // The split happens at PARSE time, by the presence of a block descendant. The rules are
    // mutually exclusive, so their order does not matter; what matters is that both rank below
    // div.lead and div[data-type=grid] (priority 50 against Filament's 100).
    const CustomDiv = Node.create({
        name: 'customDiv',
        priority: 50,
        group: 'block',
        content: 'block+',
        defining: true,
        parseHTML() {
            return [{ tag: 'div', getAttrs: (element) => (hasBlockChild(element) ? {} : false) }]
        },
        renderHTML({ HTMLAttributes }) {
            return ['div', HTMLAttributes, 0]
        },
    })

    const CustomDivInline = Node.create({
        name: 'customDivInline',
        priority: 50,
        group: 'block',
        content: 'inline*',
        defining: true,
        parseHTML() {
            return [{ tag: 'div', getAttrs: (element) => (hasBlockChild(element) ? false : {}) }]
        },
        renderHTML({ HTMLAttributes }) {
            return ['div', HTMLAttributes, 0]
        },
    })

    // A shadow of the stock `listItem` that changes ONLY `content`.
    //
    // The stock definition is `paragraph block*`, i.e. the first child must be a paragraph.
    // Because of that ProseMirror parsed legacy `<li><div class="…">…</div></li>` by closing
    // the <li> with an empty <p> and throwing the <div> to the top level: the list fell apart
    // into "an empty list + a separate block". Here the requirement is relaxed for exactly one
    // construction (see `content` below).
    //
    // Why a shadow and not `extendNodeSchema`: that hook cannot override `content`. In
    // getSchemaByResolvedExtensions its result is spread FIRST and the node's own fields
    // (content/marks/group/…) land on top, so they always win. The hook only works for NEW
    // fields (that is how tableRole and allowGapCursor are added).
    //
    // How the shadow works: resolveExtensions sorts by priority DESCENDING, does not remove
    // duplicate names (it only console.warn()s), and the schema is built with
    // Object.fromEntries, so the LAST one wins. The priority must therefore be below Filament's
    // 100: then the schema comes from our definition, while keyboard shortcuts, commands and
    // markdown serialisation come from the original, which stays in the list. That is why
    // parseHTML and renderHTML are duplicated here — a schema entry is taken whole from the
    // winner.
    //
    // Browser schema only: tiptap-php does not validate content expressions at all, so such
    // markup already survived on the front end.
    const ListItem = Node.create({
        name: 'listItem',
        priority: 50,
        // Not a plain 'block+': with it the default type for filling becomes the FIRST block
        // type of the schema, and an empty <li> could be filled with something other than a
        // paragraph. Listing paragraph first keeps the default as it was and adds exactly one
        // allowed construction — a <div> (either kind) as the first child.
        content: '(paragraph|customDiv|customDivInline) block*',
        defining: true,
        addOptions() {
            return {
                HTMLAttributes: {},
                bulletListTypeName: 'bulletList',
                orderedListTypeName: 'orderedList',
            }
        },
        parseHTML() {
            return [{ tag: 'li' }]
        },
        renderHTML({ HTMLAttributes }) {
            return ['li', mergeAttributes(this.options.HTMLAttributes, HTMLAttributes), 0]
        },
    })

    return Extension.create({
        name: 'asignuaCustomAttributes',
        addExtensions() {
            return [CustomSpan, CustomDiv, CustomDivInline, ListItem]
        },
        addGlobalAttributes() {
            return [
                {
                    types: [...new Set([...DEFAULT_TYPES, ...types])],
                    attributes: buildAttributes(names),
                },
            ]
        },
    })
}
