// Cleanup for content pasted from Word / Google Docs / another editor: only the
// STRUCTURE stays (paragraphs, headings, lists, tables, links, bold/italic), while
// colours, font sizes, backgrounds and every class/id/style carrying them are dropped.
//
// Why this is needed at all: the rich editor may deliberately keep class/id/style
// in its content (custom attributes), so the TipTap schema no longer strips
// foreign junk on its own - it would settle in the database and leak onto the page.
//
// Images from Word / Google Docs are removed on purpose: Filament uploads only
// `data:` images itself, and what the clipboard carries is `file:///` paths,
// foreign hosts or data URIs, none of which would survive as proper uploaded
// files. Only images that already point at our own site AND carry a `data-id`
// (i.e. were uploaded through the editor) are kept.
//
// In-editor paste (HTML containing `data-pm-slice`) is NEVER touched: the
// class/id/style in it are what the editor itself put there. TipTap uses the
// same substring test.
//
// Hook: `transformPastedHTML`. It is a standard field of an extension config:
// ExtensionManager collects the hooks of ALL extensions into a chain and installs
// it as a direct editorProp in Editor.createView. Neither addProseMirrorPlugins
// nor pmState.Plugin is needed. It is called with exactly ONE argument: Filament
// calls f(cleanedHtml) without a view.
//
// The "clean format" command (`asignuaCleanFormat`) is the toolbar-button
// counterpart. Unlike TipTap's stock clearFormatting it keeps headings and lists
// (it only removes junk), and since the selection is content the editor already
// accepted, it keeps custom blocks, embeds, images, code blocks and links
// (relative, anchors and tel: included; only script schemes go).
//
// No build is needed: Filament exposes the TipTap core as window.FilamentRichEditor.
//
// Configuration comes from the query string of this module's own URL:
// `paste-clean.js?keep=/internal/&keep=/other/` -> href prefixes that the
// clean-format command keeps. Pasting never keeps any (the paste contract stays
// the strictest one).
//
// IMPORTANT: `window` and `import.meta` are read ONLY inside the default-export
// factory. Moving them to the top level would make the module unloadable under
// `node --test`, and the whole suite would silently stop covering anything (a
// dedicated test pins this).

// --- decision tables ----------------------------------------------------

// Removed TOGETHER WITH their contents. The body of a <style> is raw CSS:
// unwrapping it would drop "p.MsoNormal{margin:0}" into the document as a
// paragraph of text. That is the most visible symptom of pasting from Word.
const DROP_WITH_CONTENTS = new Set([
    'style', 'script', 'meta', 'link', 'title', 'base', 'head', 'noscript',
    'iframe', 'object', 'embed', 'applet', 'form', 'input', 'select',
    'textarea', 'button', 'svg', 'math', 'col', 'colgroup',
])

// Everything that stays. All other tags are UNWRAPPED (their text is preserved).
const KEEP = new Set([
    'p', 'br', 'hr', 'strong', 'em', 'u', 's', 'sub', 'sup',
    'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
    'a', 'ul', 'ol', 'li', 'blockquote',
    'table', 'thead', 'tbody', 'tr', 'td', 'th', 'img',
])

const RENAME = { b: 'strong', i: 'em', strike: 's', del: 's', ins: 'u' }

// Layout wrappers: either unwrapped or turned into <p> - see pass 5.
const BLOCK_WRAPPERS = new Set([
    'div', 'section', 'article', 'header', 'footer', 'main', 'aside',
    'center', 'figure', 'address', 'nav', 'fieldset',
])

// Tags that count as "block" for the unwrap-or-paragraph decision.
const BLOCK_TAGS =
    'address,article,aside,blockquote,details,div,dl,fieldset,figcaption,figure,footer,' +
    'form,h1,h2,h3,h4,h5,h6,header,hr,li,main,nav,ol,p,pre,section,table,ul'

const BOLD_WEIGHTS = new Set(['bold', 'bolder', '500', '600', '700', '800', '900'])

// Word list markers. `o` is a second-level bullet, not letter numbering; that is
// why the ordered-list check below requires a terminator (. ) or ]).
const BULLETS = new Set(['·', '•', '▪', '▫', '◦', '‣', '§', 'Ø', 'ü', 'q', 'v', 'o', '-', '–', '—', '*'])
const ORDERED = /^\(?\s*(\d{1,3}|[a-z]|[A-Z]|[ivxlcdm]+|[IVXLCDM]+)\s*[.)\]]/
const MSO_LIST = /mso-list\s*:/i
const MSO_LIST_IGNORE = /mso-list\s*:\s*ignore/i
const MSO_LIST_CLASS = /\bMso(List)?Paragraph/i

const NBSP = ' '
const SHOW_TEXT = 4
const SHOW_COMMENT = 128

// Block tags that remain in the output. Whitespace-only text between them is the
// source document's formatting, not content.
const BLOCK_KEPT = new Set([
    'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'blockquote',
    'ul', 'ol', 'li', 'table', 'thead', 'tbody', 'tr', 'td', 'th',
])

const EMPTY_INLINE = new Set(['strong', 'em', 'u', 's', 'sub', 'sup', 'a'])
const EMPTY_BLOCK = new Set(['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'blockquote'])
const EMPTY_CONTAINER = new Set(['ul', 'ol', 'table', 'thead', 'tbody', 'tr'])
const VOIDISH = new Set(['br', 'img', 'hr'])

// --- small helpers ------------------------------------------------------

const tagOf = (el) => el.tagName.toLowerCase()

const unwrap = (el) => {
    const parent = el.parentNode

    if (!parent) {
        return
    }

    while (el.firstChild) {
        parent.insertBefore(el.firstChild, el)
    }

    parent.removeChild(el)
}

const renameTo = (el, name) => {
    const next = el.ownerDocument.createElement(name)

    for (const attribute of Array.from(el.attributes)) {
        try {
            next.setAttribute(attribute.name, attribute.value)
        } catch {
            // Invalid attribute name (Word emits those) - just skip it.
        }
    }

    while (el.firstChild) {
        next.appendChild(el.firstChild)
    }

    el.replaceWith(next)

    return next
}

const styleMap = (el) => {
    const map = {}

    for (const declaration of (el.getAttribute('style') || '').split(';')) {
        const colon = declaration.indexOf(':')

        if (colon < 0) {
            continue
        }

        map[declaration.slice(0, colon).trim().toLowerCase()] = declaration
            .slice(colon + 1)
            .trim()
            .toLowerCase()
    }

    return map
}

const hasBlockChild = (el) => el.querySelector(BLOCK_TAGS) !== null

const isBlank = (el) =>
    (el.textContent || '').split(NBSP).join(' ').trim() === '' && el.querySelector('img') === null

const collect = (root, whatToShow) => {
    const walker = root.ownerDocument.createTreeWalker(root, whatToShow)
    const nodes = []

    while (walker.nextNode()) {
        nodes.push(walker.currentNode)
    }

    return nodes
}

// Reverse document order = children before ancestors, so unwrapping and removal
// do not invalidate the rest of the snapshot.
const descending = (root) => Array.from(root.querySelectorAll('*')).reverse()

// --- pass 4: rescue semantics from inline styles ------------------------

const rescueInlineSemantics = (body) => {
    for (const el of Array.from(body.querySelectorAll('[style]'))) {
        if (!el.isConnected) {
            continue
        }

        const name = tagOf(el)
        const style = styleMap(el)
        const weight = style['font-weight']

        // Google Docs wraps the WHOLE fragment in <b style="font-weight:normal">.
        // Without this branch the entire pasted document would turn bold.
        if ((name === 'b' || name === 'strong') && (weight === 'normal' || weight === '400')) {
            unwrap(el)

            continue
        }

        if ((name === 'i' || name === 'em') && style['font-style'] === 'normal') {
            unwrap(el)

            continue
        }

        const decoration = `${style['text-decoration'] || ''} ${style['text-decoration-line'] || ''}`
        const wraps = []

        if (weight && BOLD_WEIGHTS.has(weight)) {
            wraps.push('strong')
        }

        if (style['font-style'] === 'italic' || style['font-style'] === 'oblique') {
            wraps.push('em')
        }

        if (decoration.includes('underline')) {
            wraps.push('u')
        }

        if (decoration.includes('line-through')) {
            wraps.push('s')
        }

        // The element already IS this tag - otherwise we would get <strong><strong>.
        const own = RENAME[name] || name
        const needed = wraps.filter((wrap) => wrap !== own)

        if (needed.length === 0) {
            continue
        }

        const doc = el.ownerDocument
        let outer = null
        let deepest = null

        for (const wrap of needed) {
            const node = doc.createElement(wrap)

            if (deepest) {
                deepest.appendChild(node)
            } else {
                outer = node
            }

            deepest = node
        }

        while (el.firstChild) {
            deepest.appendChild(el.firstChild)
        }

        el.appendChild(outer)
    }
}

// --- pass 6: rebuild Word lists (one level) -----------------------------

const ignoreSpanOf = (el) =>
    Array.from(el.querySelectorAll('span')).find((span) =>
        MSO_LIST_IGNORE.test(span.getAttribute('style') || ''),
    ) || null

const trimLeadingSpace = (el) => {
    while (el.firstChild && el.firstChild.nodeType === 3) {
        const trimmed = el.firstChild.nodeValue.replace(/^[\s ]+/, '')

        if (trimmed === '') {
            el.firstChild.remove()

            continue
        }

        el.firstChild.nodeValue = trimmed

        break
    }
}

/**
 * The list type for a paragraph, or null if it is not a list item. Mutates the
 * element (strips the fake marker) ONLY when sure - otherwise an ordinary
 * paragraph with the MsoListParagraph class would lose its first word.
 */
const listKindOf = (el) => {
    const name = tagOf(el)

    if (name !== 'p' && !/^h[1-6]$/.test(name)) {
        return null
    }

    const span = ignoreSpanOf(el)

    if (span) {
        const marker = (span.textContent || '').trim()

        span.remove()
        trimLeadingSpace(el)

        return ORDERED.test(marker) ? 'ol' : 'ul'
    }

    const looksLikeList =
        MSO_LIST.test(el.getAttribute('style') || '') ||
        MSO_LIST_CLASS.test(el.getAttribute('class') || '')

    if (!looksLikeList) {
        return null
    }

    // Fallback: Word does not always emit a span with mso-list:Ignore. Cut the
    // leading token only if it really looks like a marker.
    const text = (el.textContent || '').replace(/ /g, ' ')
    const match = text.match(/^\s*(\S{1,6})(?:\s+|$)/)
    const marker = match ? match[1] : ''

    if (marker === '' || !(ORDERED.test(marker) || BULLETS.has(marker))) {
        return null
    }

    for (const node of collect(el, SHOW_TEXT)) {
        const stripped = node.nodeValue.replace(/^[\s ]*\S{1,6}(?=[\s ]|$)[\s ]*/, '')

        if (stripped !== node.nodeValue) {
            node.nodeValue = stripped

            break
        }
    }

    trimLeadingSpace(el)

    return ORDERED.test(marker) ? 'ol' : 'ul'
}

const rebuildWordLists = (body, doc) => {
    const parents = [body, ...Array.from(body.querySelectorAll('*'))]

    for (const parent of parents) {
        if (!parent.isConnected) {
            continue
        }

        let run = []
        let kind = null

        const flush = () => {
            if (run.length === 0) {
                return
            }

            const list = doc.createElement(kind)

            parent.insertBefore(list, run[0])

            for (const paragraph of run) {
                const item = doc.createElement('li')

                while (paragraph.firstChild) {
                    item.appendChild(paragraph.firstChild)
                }

                list.appendChild(item)
                paragraph.remove()
            }

            run = []
            kind = null
        }

        for (const child of Array.from(parent.childNodes)) {
            // Whitespace-only text between tags does not break a run.
            if (child.nodeType === 3 && child.nodeValue.trim() === '') {
                continue
            }

            if (child.nodeType !== 1) {
                flush()

                continue
            }

            const childKind = listKindOf(child)

            if (childKind === null) {
                flush()

                continue
            }

            if (kind !== null && kind !== childKind) {
                flush()
            }

            kind = childKind
            run.push(child)
        }

        flush()
    }
}

// --- pass 7: image triage -----------------------------------------------

const isOwnSrc = (src, origin) => {
    if (!src) {
        return false
    }

    // Protocol-relative //host/... is foreign.
    if (src.startsWith('//')) {
        return false
    }

    if (src.startsWith('/')) {
        return true
    }

    if (!origin) {
        return false
    }

    return src.toLowerCase().startsWith(`${origin.toLowerCase()}/`)
}

// --- pass 8: attributes -------------------------------------------------

// Schemes that execute or smuggle a document. Checked after control characters
// are stripped (see stripAttributes).
const SCRIPT_SCHEME = /^(javascript|vbscript|data|file):/i

const stripAttributes = (el, keepLinkPrefixes, existingContent) => {
    const name = tagOf(el)
    const keep = {}

    if (name === 'a') {
        // Control characters inside the scheme are a classic way around the check.
        const href = (el.getAttribute('href') || '').replace(/[\x00-\x20]/g, '')

        if (/^(https?|mailto|tel):/i.test(href)) {
            keep.href = href
        } else if (existingContent && href !== '' && !SCRIPT_SCHEME.test(href)) {
            // "Clean format" button mode: the link is already in the document,
            // so a relative path, an in-page anchor or another scheme is the
            // author's, not clipboard junk (on paste they are Word's _Toc
            // anchors and local file paths). Only script schemes are refused;
            // the Link mark validates the rest.
            keep.href = href
        } else if (keepLinkPrefixes.some((prefix) => href.startsWith(prefix))) {
            // "Clean format" button mode: hrefs with a configured prefix (e.g. an
            // internal-link sentinel) are not clipboard junk - they are something
            // the editor deliberately stores.
            keep.href = href
        }
    } else if (name === 'td' || name === 'th') {
        for (const attribute of ['colspan', 'rowspan']) {
            const value = el.getAttribute(attribute) || ''

            if (/^\d{1,3}$/.test(value) && Number(value) > 1) {
                keep[attribute] = value
            }
        }
    } else if (name === 'img') {
        // alt is a deliberate deviation from "strip everything": without it this
        // would be a WCAG 1.1.1 regression.
        for (const attribute of ['src', 'data-id', 'alt']) {
            const value = el.getAttribute(attribute)

            if (value !== null) {
                keep[attribute] = value
            }
        }
    }

    for (const attribute of Array.from(el.attributes)) {
        el.removeAttribute(attribute.name)
    }

    for (const [attribute, value] of Object.entries(keep)) {
        el.setAttribute(attribute, value)
    }

    // A link without a surviving href is <a name="_Toc1"> or href="#_Toc1":
    // the text stays, the tag goes.
    if (name === 'a' && keep.href === undefined) {
        unwrap(el)
    }
}

const sweepElements = (body, doc, keepLinkPrefixes, existingContent) => {
    for (const el of descending(body)) {
        if (!el.isConnected) {
            continue
        }

        const node = RENAME[tagOf(el)] ? renameTo(el, RENAME[tagOf(el)]) : el
        const name = tagOf(node)

        if (name === 'caption') {
            // Unwrapping in place would leave inline text as a direct child of
            // <table>, from where ProseMirror hoists it unpredictably.
            const table = node.closest('table')
            const paragraph = doc.createElement('p')

            while (node.firstChild) {
                paragraph.appendChild(node.firstChild)
            }

            if (table && table.parentNode) {
                table.parentNode.insertBefore(paragraph, table)
            } else if (node.parentNode) {
                node.parentNode.insertBefore(paragraph, node)
            }

            node.remove()

            continue
        }

        if (!KEEP.has(name)) {
            unwrap(node)

            continue
        }

        stripAttributes(node, keepLinkPrefixes, existingContent)
    }
}

// --- custom blocks and editor nodes in button mode ------------------------
// "Clean format" on existing content must not destroy <div data-type=
// "customBlock">: the div would be unwrapped, and data-config/data-id - all that
// the block is - would be stripped. The block is replaced with a token paragraph
// (Private Use Area characters: they never occur in live text, and no pass
// rewrites text nodes except collapsing whitespace, which the token has none of);
// after all passes the token is expanded back into the saved element.
//
// With `existingContent` the same goes for nodes the editor schema itself
// produced: an embed <iframe> (DROP_WITH_CONTENTS on paste), an <img> inserted
// by URL or stored on another host (the image triage would remove it) and a
// <pre> code block (it would be unwrapped and its whitespace collapsed). They
// already passed the schema, so there is nothing to clean in them. An <img> is
// inline: its token is plain text in place, never a paragraph of its own.

const BLOCK_TOKEN_PREFIX = '\uE000asignua-custom-block-'
const BLOCK_TOKEN_SUFFIX = '\uE001'
const BLOCK_TOKEN = /\uE000asignua-custom-block-(\d+)\uE001/

const PROTECTED_BLOCKS = 'div[data-type="customBlock"]'
const PROTECTED_EDITOR_NODES = 'iframe, pre, img'

const extractCustomBlocks = (body, doc, existingContent) => {
    const saved = []
    const selector = existingContent ? `${PROTECTED_BLOCKS}, ${PROTECTED_EDITOR_NODES}` : PROTECTED_BLOCKS

    for (const el of Array.from(body.querySelectorAll(selector))) {
        // Already taken out together with an enclosing protected element.
        if (!el.isConnected) {
            continue
        }

        const block = tagOf(el) !== 'img'
        const token = doc.createTextNode(`${BLOCK_TOKEN_PREFIX}${saved.length}${BLOCK_TOKEN_SUFFIX}`)

        saved.push({ el, block })

        if (block) {
            const placeholder = doc.createElement('p')

            placeholder.appendChild(token)
            el.replaceWith(placeholder)
        } else {
            el.replaceWith(token)
        }
    }

    return saved
}

const restoreCustomBlocks = (body, saved) => {
    for (const node of collect(body, SHOW_TEXT)) {
        let text = node
        let match

        while (text && (match = text.nodeValue.match(BLOCK_TOKEN))) {
            const { el, block } = saved[Number(match[1])]
            const parent = text.parentNode

            // Normal case for a block: the placeholder paragraph survived
            // unchanged - the block takes its place.
            if (block && parent && tagOf(parent) === 'p' && parent.textContent.trim() === match[0]) {
                parent.replaceWith(el)

                break
            }

            // An inline token, or a block token inside a foreign node (the block
            // is not lost, though it may end up in an inline context): the token
            // is cut out of its text node and replaced in place.
            const tokenNode = text.splitText(match.index)
            const after = tokenNode.splitText(match[0].length)

            tokenNode.replaceWith(el)

            if (text.nodeValue === '') {
                text.remove()
            }

            if (after.nodeValue === '') {
                after.remove()
                text = null
            } else {
                text = after
            }
        }
    }
}

// --- pass 9: whitespace and emptiness -----------------------------------

const normalizeWhitespace = (body) => {
    for (const node of collect(body, SHOW_TEXT)) {
        node.nodeValue = node.nodeValue
            // Runs of nbsp are Word's indentation, not text. A single nbsp
            // between words (10&nbsp;UAH) is left untouched.
            .replace(/ {2,}/g, ' ')
            .replace(/[ \t\r\n]{2,}/g, ' ')
            .replace(/[\r\n]/g, ' ')
    }
}

const trimEdges = (el) => {
    while (
        el.firstChild &&
        el.firstChild.nodeType === 3 &&
        el.firstChild.nodeValue.split(NBSP).join(' ').trim() === ''
    ) {
        el.firstChild.remove()
    }

    while (
        el.lastChild &&
        el.lastChild.nodeType === 3 &&
        el.lastChild.nodeValue.split(NBSP).join(' ').trim() === ''
    ) {
        el.lastChild.remove()
    }
}

const dropEmpty = (body) => {
    for (let pass = 0; pass < 3; pass++) {
        let changed = false

        for (const el of descending(body)) {
            if (!el.isConnected) {
                continue
            }

            const name = tagOf(el)

            if (VOIDISH.has(name)) {
                continue
            }

            trimEdges(el)

            if (EMPTY_INLINE.has(name) && isBlank(el)) {
                // unwrap, not remove: in "a <strong> </strong>b" removing the
                // tag would glue the words together.
                unwrap(el)
                changed = true

                continue
            }

            if (EMPTY_BLOCK.has(name) && isBlank(el)) {
                el.remove()
                changed = true

                continue
            }

            if (EMPTY_CONTAINER.has(name) && el.children.length === 0) {
                el.remove()
                changed = true
            }
        }

        trimEdges(body)

        if (!changed) {
            break
        }
    }
}

/**
 * Whitespace text BETWEEN blocks is the source document's indentation. Keeping
 * it would break idempotency: the next run would collapse "\n   " to " ", and
 * every repeated cleaning would produce a new result.
 */
const dropInterBlockWhitespace = (body) => {
    for (const parent of [body, ...Array.from(body.querySelectorAll('*'))]) {
        if (!parent.isConnected) {
            continue
        }

        const hasBlockKid = Array.from(parent.children).some((child) => BLOCK_KEPT.has(tagOf(child)))

        if (!hasBlockKid) {
            continue
        }

        for (const child of Array.from(parent.childNodes)) {
            if (child.nodeType === 3 && child.nodeValue.split(NBSP).join(' ').trim() === '') {
                child.remove()
            }
        }
    }
}

// --- pass 10: structural sanity -----------------------------------------

const fixStructure = (body, doc) => {
    for (const list of Array.from(body.querySelectorAll('ul, ol'))) {
        for (const child of Array.from(list.children)) {
            if (tagOf(child) === 'li') {
                continue
            }

            const item = doc.createElement('li')

            list.insertBefore(item, child)
            item.appendChild(child)
        }
    }

    for (const nested of Array.from(body.querySelectorAll('p p'))) {
        unwrap(nested)
    }

    // The shadow listItem has content '(paragraph|customDiv|customDivInline) block*',
    // so a table as the first child would close the <li> early and float up on its own.
    for (const table of Array.from(body.querySelectorAll('li table'))) {
        const item = table.closest('li')
        const list = item && item.parentElement

        if (list && list.parentNode) {
            list.parentNode.insertBefore(table, list.nextSibling)
        }
    }
}

// --- public pure function -----------------------------------------------

/**
 * `keepLinkPrefixes` / `keepCustomBlocks` are the "clean format" button mode
 * (the asignuaCleanFormat command below): cleaning EXISTING editor content must
 * not destroy links with the configured href prefixes (e.g. internal-link
 * sentinels) or custom blocks. The defaults (`[]` / `false`) leave the paste
 * contract unchanged.
 *
 * @param {string} html raw HTML from the clipboard
 * `existingContent` says the HTML is the editor's own serialization, not a
 * clipboard: embeds, images, code blocks and every non-script link are kept.
 *
 * @param {{parseHtml?: (html: string) => Document, origin?: string, keepLinkPrefixes?: string[], keepCustomBlocks?: boolean, existingContent?: boolean}} options
 * @returns {string}
 */
export const cleanPastedHtml = (html, options = {}) => {
    if (typeof html !== 'string' || html.trim() === '') {
        return html
    }

    // In-editor ProseMirror paste. It MUST NOT be touched: the class/id/style in
    // it are what the editor deliberately keeps. TipTap itself uses the same
    // substring test.
    if (html.includes('data-pm-slice')) {
        return html
    }

    // Defaults are computed here, not at module top level: otherwise importing
    // under Node would fail on the missing DOMParser.
    const parseHtml =
        options.parseHtml || ((source) => new globalThis.DOMParser().parseFromString(source, 'text/html'))
    const origin =
        options.origin !== undefined
            ? options.origin
            : (globalThis.location && globalThis.location.origin) || ''
    const keepLinkPrefixes = Array.isArray(options.keepLinkPrefixes)
        ? options.keepLinkPrefixes.filter((prefix) => typeof prefix === 'string' && prefix !== '')
        : []

    const doc = parseHtml(html)
    const body = doc && doc.body

    if (!body) {
        return html
    }

    // Extraction BEFORE all passes: anything can sit inside a block's preview
    // (a video iframe etc.), and the very first pass would destroy it.
    const existingContent = options.existingContent === true
    const savedBlocks =
        options.keepCustomBlocks || existingContent ? extractCustomBlocks(body, doc, existingContent) : []

    for (const el of Array.from(body.querySelectorAll('*'))) {
        if (!el.isConnected) {
            continue
        }

        const name = tagOf(el)

        // Office namespace junk: o:p, w:sdt, v:shape, st1:place, m:oMath. Matched
        // by tag name, not by a CSS selector: escaping ':' in querySelectorAll
        // behaves differently across parsers.
        if (DROP_WITH_CONTENTS.has(name) || name.includes(':')) {
            el.remove()
        }
    }

    for (const comment of collect(body, SHOW_COMMENT)) {
        comment.remove()
    }

    rescueInlineSemantics(body)

    for (const el of descending(body)) {
        if (!el.isConnected || !BLOCK_WRAPPERS.has(tagOf(el))) {
            continue
        }

        if (hasBlockChild(el)) {
            unwrap(el)
        } else if (!isBlank(el)) {
            // Inline content IS a paragraph: plain unwrapping would merge it
            // with the neighbouring text. The same split as between CustomDiv
            // and CustomDivInline in the editor schema.
            renameTo(el, 'p')
        } else {
            el.remove()
        }
    }

    rebuildWordLists(body, doc)

    for (const image of Array.from(body.querySelectorAll('img'))) {
        const id = (image.getAttribute('data-id') || '').trim()

        if (id === '' || !isOwnSrc(image.getAttribute('src'), origin)) {
            image.remove()
        }
    }

    sweepElements(body, doc, keepLinkPrefixes, existingContent)
    normalizeWhitespace(body)
    dropEmpty(body)
    fixStructure(body, doc)
    dropInterBlockWhitespace(body)

    if (savedBlocks.length > 0) {
        restoreCustomBlocks(body, savedBlocks)
    }

    const out = body.innerHTML

    return out.trim() === '' ? '' : out
}

// --- "clean format" button command --------------------------------------

/**
 * Selection -> array of JSON nodes with the formatting cleaned.
 *
 * TOP-level custom blocks bypass HTML entirely: in the editor's node spec
 * `preview`/`label` have `rendered: false`, so DOMSerializer does not emit them
 * and after re-insertion the editor would show an empty box. The node JSON
 * (`toJSON()`) carries all attributes. A block nested in another block (li,
 * blockquote) goes through HTML and survives thanks to keepCustomBlocks - with
 * its config/id, but without the editor preview until reload; the front end loses
 * nothing (it renders from config).
 *
 * @param {object} state ProseMirror state
 * @param {object} DOMSerializer from pmModel (passed in by the factory - the module
 *   itself reads no editor globals, otherwise it would stop loading under node --test)
 * @param {object} PmDOMParser from pmModel, likewise from the factory
 * @param {string[]} keepLinkPrefixes href prefixes to keep
 * @returns {Array<object>}
 */
const cleanSelectionContent = (state, DOMSerializer, PmDOMParser, keepLinkPrefixes) => {
    const serializer = DOMSerializer.fromSchema(state.schema)
    const parser = PmDOMParser.fromSchema(state.schema)
    const content = []
    let buffer = []

    const flush = () => {
        if (buffer.length === 0) {
            return
        }

        const container = globalThis.document.createElement('div')

        for (const node of buffer) {
            container.appendChild(serializer.serializeNode(node))
        }

        buffer = []

        const cleaned = cleanPastedHtml(container.innerHTML, {
            keepLinkPrefixes,
            keepCustomBlocks: true,
            existingContent: true,
        })

        if (typeof cleaned !== 'string' || cleaned.trim() === '') {
            return
        }

        const dom = new globalThis.DOMParser().parseFromString(cleaned, 'text/html')

        parser.parse(dom.body).content.forEach((node) => content.push(node.toJSON()))
    }

    state.selection.content().content.forEach((node) => {
        if (node.type.name === 'customBlock') {
            flush()
            content.push(node.toJSON())
        } else {
            buffer.push(node)
        }
    })

    flush()

    return content
}

// --- Filament contract --------------------------------------------------

// Reads the `keep` query parameters of this module's URL. import.meta is touched
// only when the factory runs (see the header note); any failure means "no prefixes".
const prefixesFromModuleUrl = () => {
    try {
        return new URL(import.meta.url).searchParams.getAll('keep')
    } catch {
        return []
    }
}

export default () => {
    const { Extension } = window.FilamentRichEditor.tiptap.core
    const { DOMSerializer, DOMParser: PmDOMParser } = window.FilamentRichEditor.tiptap.pmModel
    const keepLinkPrefixes = prefixesFromModuleUrl()

    return Extension.create({
        name: 'asignuaPasteClean',
        transformPastedHTML(html) {
            try {
                return cleanPastedHtml(html)
            } catch (error) {
                // A throw here propagates through parseFromClipboard and KILLS
                // the paste entirely, without any message. A dirty paste is
                // better than a dead Ctrl+V.
                console.error('[rich-editor-toolkit] paste cleanup failed, pasting as is:', error)

                return html
            }
        },
        // The toolbar's cleanFormat button. With no selection it is a deliberate
        // no-op: the button must not clean "the whole document".
        addCommands() {
            return {
                asignuaCleanFormat:
                    () =>
                    ({ state, chain }) => {
                        if (state.selection.empty) {
                            return false
                        }

                        let content

                        try {
                            content = cleanSelectionContent(state, DOMSerializer, PmDOMParser, keepLinkPrefixes)
                        } catch (error) {
                            console.error('[rich-editor-toolkit] clean format failed, content unchanged:', error)

                            return false
                        }

                        if (content.length === 0) {
                            return chain().deleteSelection().run()
                        }

                        // insertContent replaces the current selection; one
                        // transaction = one Ctrl+Z step.
                        return chain().insertContent(content).run()
                    },
            }
        },
    })
}
