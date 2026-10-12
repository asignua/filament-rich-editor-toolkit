// Alt text, caption and alignment for the editor's images: the browser mirror of the PHP node
// Asignua\RichEditorToolkit\Extensions\ImageProperties.
//
// WHAT IT DOES TO THE SCHEMA. Filament's `image` node is TipTap's Image (inline, so an image
// lives inside a paragraph). This module adds a SHADOW of it, the way custom-attributes.js
// shadows `listItem`: the same name at a priority below Filament's 100. TipTap puts every
// extension's attributes of one name together and the schema takes the LAST definition of a name,
// so the shadow contributes the two new attributes (`caption`, `align`) and replaces the schema
// entry (parseHTML / renderHTML), while the commands (`setImage`) and the resizable node view
// still come from Filament's own node, which stays in the list. `console.warn` about a duplicate
// name is expected, the same as for `listItem`.
//
// MARKUP. An image with neither caption nor alignment is a bare <img> exactly as before. One with
// either is
//
//     <span class="asignua-rte-figure asignua-rte-align-center"><img ...><span class="asignua-rte-caption">Text</span></span>
//
// and not a <figure>: the image is an inline node and a <figure> inside the paragraph that holds it
// is invalid HTML. A <figure>/<figcaption> (source view, a paste) is READ and written back as the span.
//
// HOW A WRAPPER IS READ. ProseMirror hands the matched element to the attribute parsers of the
// node, so for a wrapper `src` would be read from the <span>. Instead of re-implementing every
// parser (Filament's `data-id`, width/height, the custom attributes of CustomAttributesPlugin), the
// wrapper is TURNED INTO the image in place: it gets the <img>'s attributes, plus what it said
// about itself (alignment, caption) under data-asignua-* names, and the `caption` / `align`
// parsers read those. The PHP side does exactly the same, so the two parse alike.
//
// THE EDITOR. The caption and the alignment are shown through a node decoration (a class and
// `data-asignua-caption`, drawn by the UI stylesheet with ::after): a decoration reaches the node's
// outermost element whether it comes from renderHTML (the wrapper span) or from Filament's
// resizable node view (its container), which ignores renderHTML.
//
// The browser mirror of ImageProperties.php: the class names, the alignments and "what is a
// wrapper" must agree (a PHP test pins the constants).
//
// IMPORTANT: `window` and `import.meta` are read ONLY inside the default-export factory.

import { ensureStylesheet, paramsOf } from './ui-style.js'

export const FIGURE_CLASS = 'asignua-rte-figure'
export const CAPTION_CLASS = 'asignua-rte-caption'
export const ALIGN_CLASS_PREFIX = 'asignua-rte-align-'
export const ALIGNMENTS = ['left', 'center', 'right']

export const STASH_ALIGN = 'data-asignua-align'
export const STASH_CAPTION = 'data-asignua-caption'
const STASH_ADOPTED = 'data-asignua-adopted'

export const cleanAlign = (value) => (typeof value === 'string' && ALIGNMENTS.includes(value) ? value : null)

/**
 * Plain text with the whitespace collapsed; null when nothing is left.
 */
export const cleanCaption = (value) => {
    if (typeof value !== 'string') {
        return null
    }

    const text = value.replace(/\s+/g, ' ').trim()

    return text === '' ? null : text
}

const classesOf = (element) => (element.getAttribute('class') || '').split(/\s+/).filter((name) => name !== '')

const hasClass = (element, name) => classesOf(element).includes(name)

/**
 * The alignment a wrapper declares: an `asignua-rte-align-*` class, else `data-align`.
 */
export const alignOf = (wrapper) => {
    for (const name of classesOf(wrapper)) {
        if (name.startsWith(ALIGN_CLASS_PREFIX) && cleanAlign(name.slice(ALIGN_CLASS_PREFIX.length)) !== null) {
            return name.slice(ALIGN_CLASS_PREFIX.length)
        }
    }

    return cleanAlign(wrapper.getAttribute('data-align'))
}

/**
 * Turns a wrapper (<figure> or our span) into the image it wraps, in place. Returns false, and
 * touches nothing, when it is not a single-image wrapper: exactly one <img> (with a src or a
 * data-id) and, besides it, only a <figcaption> or our caption span, so a <figure> around a table
 * or a quote is left to the generic handling.
 *
 * Idempotent: ProseMirror may ask the same rule twice.
 *
 * @param {Element} wrapper
 */
export const adopt = (wrapper) => {
    if (wrapper.hasAttribute(STASH_ADOPTED)) {
        return true
    }

    const images = wrapper.getElementsByTagName('img')

    if (images.length !== 1) {
        return false
    }

    const image = images[0]

    if (!image.hasAttribute('src') && !image.hasAttribute('data-id')) {
        return false
    }

    let caption = null

    for (const child of Array.from(wrapper.childNodes)) {
        if (child === image || (child.nodeType === 3 && (child.nodeValue || '').trim() === '')) {
            continue
        }

        if (
            child.nodeType === 1 &&
            caption === null &&
            (child.tagName.toLowerCase() === 'figcaption' || hasClass(child, CAPTION_CLASS))
        ) {
            caption = child.textContent

            continue
        }

        return false
    }

    const align = alignOf(wrapper)
    const attributes = Array.from(image.attributes).map((attribute) => [attribute.name, attribute.value])

    for (const name of Array.from(wrapper.attributes).map((attribute) => attribute.name)) {
        wrapper.removeAttribute(name)
    }

    while (wrapper.firstChild) {
        wrapper.removeChild(wrapper.firstChild)
    }

    for (const [name, value] of attributes) {
        wrapper.setAttribute(name, value)
    }

    wrapper.setAttribute(STASH_ADOPTED, '1')
    wrapper.setAttribute(STASH_ALIGN, align ?? '')
    wrapper.setAttribute(STASH_CAPTION, cleanCaption(caption) ?? '')

    return true
}

/**
 * The ProseMirror parse rules of the shadow node. The first is TipTap's own (data: images are
 * skipped, as in Filament's configuration); the wrappers rank above the span mark of
 * CustomAttributesPlugin (50), which would otherwise take the span before any node rule.
 */
export const parseRules = () => [
    { tag: 'img[src]:not([src^="data:"])' },
    { tag: 'figure', priority: 60, getAttrs: (element) => (adopt(element) ? {} : false) },
    {
        tag: `span.${FIGURE_CLASS}`,
        priority: 60,
        getAttrs: (element) => (adopt(element) ? {} : false),
    },
]

/**
 * The attribute definitions the shadow adds. `rendered: false`: they must not appear as
 * `caption="..."` on the <img>.
 */
export const nodeAttributes = () => ({
    caption: {
        default: null,
        rendered: false,
        parseHTML: (element) => cleanCaption(element.getAttribute(STASH_CAPTION)),
    },
    align: {
        default: null,
        rendered: false,
        parseHTML: (element) => cleanAlign(element.getAttribute(STASH_ALIGN) || element.getAttribute('data-align')),
    },
})

export const wrapperClass = (align) => `${FIGURE_CLASS}${align ? ` ${ALIGN_CLASS_PREFIX}${align}` : ''}`

/**
 * The ProseMirror DOMOutputSpec of an image node.
 *
 * @param {{caption?: string|null, align?: string|null}} attrs the node's attributes
 * @param {Record<string, unknown>} imageAttributes the rendered HTML attributes of the <img>
 */
export const renderSpec = (attrs, imageAttributes) => {
    const caption = cleanCaption(attrs.caption)
    const align = cleanAlign(attrs.align)

    if (caption === null && align === null) {
        return ['img', imageAttributes]
    }

    return [
        'span',
        { class: wrapperClass(align) },
        ['img', imageAttributes],
        ...(caption === null ? [] : [['span', { class: CAPTION_CLASS }, caption]]),
    ]
}

/**
 * The attributes the decoration puts on the node's outermost DOM element, or null.
 */
export const decorationAttrs = (attrs) => {
    const caption = cleanCaption(attrs.caption)
    const align = cleanAlign(attrs.align)

    if (caption === null && align === null) {
        return null
    }

    return { class: wrapperClass(align), ...(caption === null ? {} : { [STASH_CAPTION]: caption }) }
}

/**
 * @param {object} doc a ProseMirror document
 * @param {{node: Function, create: Function}} Decoration / DecorationSet pair
 */
export const buildDecorations = (doc, { Decoration, DecorationSet }) => {
    const decorations = []

    doc.descendants((node, position) => {
        if (node.type.name !== 'image') {
            return
        }

        const attrs = decorationAttrs(node.attrs)

        if (attrs !== null) {
            decorations.push(Decoration.node(position, position + node.nodeSize, attrs))
        }
    })

    return DecorationSet.create(doc, decorations)
}

export default () => {
    const { Extension, Node, mergeAttributes } = window.FilamentRichEditor.tiptap.core
    const { Plugin, PluginKey } = window.FilamentRichEditor.tiptap.pmState
    const { Decoration, DecorationSet } = window.FilamentRichEditor.tiptap.pmView
    const params = paramsOf(import.meta.url)

    const ImageShadow = Node.create({
        name: 'image',
        // Below Filament's 100: the LAST definition of a name wins the schema entry.
        priority: 50,
        // As Filament configures its image (`Image.configure({ inline: true })`): inline, so an
        // image sits in a paragraph. Everything else of the schema entry is TipTap's default.
        inline: true,
        group: 'inline',
        draggable: true,
        addOptions() {
            return { HTMLAttributes: {} }
        },
        addAttributes() {
            return nodeAttributes()
        },
        parseHTML() {
            return parseRules()
        },
        renderHTML({ node, HTMLAttributes }) {
            return renderSpec(node.attrs, mergeAttributes(this.options.HTMLAttributes, HTMLAttributes))
        },
        onCreate() {
            ensureStylesheet(params.get('css'))
        },
        addProseMirrorPlugins() {
            return [
                new Plugin({
                    key: new PluginKey('asignuaImageProperties'),
                    state: {
                        init: (config, state) => buildDecorations(state.doc, { Decoration, DecorationSet }),
                        apply: (transaction, decorations, oldState, newState) =>
                            transaction.docChanged
                                ? buildDecorations(newState.doc, { Decoration, DecorationSet })
                                : decorations,
                    },
                    props: {
                        decorations(state) {
                            return this.getState(state)
                        },
                    },
                }),
            ]
        },
    })

    // Wrapped, never returned directly: Filament REPLACES a built-in extension when a custom module
    // returns one with the same name (its own `image` would be dropped, and with it `src`, `alt`,
    // `data-id`, the size and the resizable node view: every image would lose its picture). An
    // extension nested in addExtensions() is only appended, which is what the shadow needs (the
    // same way custom-attributes.js ships its `listItem`).
    return Extension.create({
        name: 'asignuaImageProperties',
        addExtensions() {
            return [ImageShadow]
        },
    })
}
