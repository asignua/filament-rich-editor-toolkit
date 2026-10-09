// Pure helpers of the source (HTML) view: the pretty printer and the "did the editor's schema
// change what the user typed" detector. No `window`, no `import.meta`: a DOM parser is passed in
// (`parseHtml(html) -> Document`, i.e. `new DOMParser().parseFromString(html, 'text/html')`), so
// the module loads and is tested under Node + jsdom.
//
// Nothing here ever assigns HTML to a live element: strings go into a detached document through
// the parser, and the output of the printer is a string that ends up as a textarea's `value`.

const VOID = new Set(['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'])

// Elements that start on their own line. Everything else is inline and is printed as it came.
// `iframe`/`video`/`audio` are blocks because this editor stores them as block nodes.
const BLOCK = new Set([
    'address', 'article', 'aside', 'audio', 'blockquote', 'body', 'caption', 'colgroup', 'dd', 'details', 'div', 'dl',
    'dt', 'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hgroup',
    'hr', 'iframe', 'li', 'main', 'menu', 'nav', 'ol', 'p', 'pre', 'script', 'section', 'style', 'summary', 'table',
    'tbody', 'td', 'template', 'tfoot', 'th', 'thead', 'tr', 'ul', 'video',
])

const RAW_TEXT = new Set(['script', 'style'])

const ELEMENT = 1
const TEXT = 3
const COMMENT = 8

const escapeText = (text) => text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/ /g, '&nbsp;')
const escapeAttr = (text) => text.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/ /g, '&nbsp;')

const nameOf = (node) => node.localName.toLowerCase()

const openTag = (node) => {
    let html = `<${nameOf(node)}`

    for (const attribute of Array.from(node.attributes)) {
        html += ` ${attribute.name}="${escapeAttr(attribute.value)}"`
    }

    return `${html}>`
}

/** An element and everything under it, exactly as it came (the HTML serialisation algorithm, in short). */
const serialize = (node) => {
    if (node.nodeType === TEXT) {
        const parent = node.parentNode

        return parent && parent.nodeType === ELEMENT && RAW_TEXT.has(nameOf(parent)) ? node.data : escapeText(node.data)
    }

    if (node.nodeType === COMMENT) {
        return `<!--${node.data}-->`
    }

    if (node.nodeType !== ELEMENT) {
        return ''
    }

    const name = nameOf(node)

    if (VOID.has(name)) {
        return openTag(node)
    }

    // A parser drops one newline right after <pre>, so the printer must write one back.
    const lead = name === 'pre' && node.firstChild && node.firstChild.nodeType === TEXT && node.firstChild.data.startsWith('\n') ? '\n' : ''

    return `${openTag(node)}${lead}${Array.from(node.childNodes).map(serialize).join('')}</${name}>`
}

const isBlock = (node) => node.nodeType === ELEMENT && BLOCK.has(nameOf(node))

const hasBlockChild = (node) => Array.from(node.childNodes).some(isBlock)

/**
 * Pretty-print HTML: every block element on its own line, children of a block that holds blocks
 * indented, a block that holds only inline content kept on one line. `<pre>` is written exactly
 * as parsed (no re-indentation inside it); void elements get no closing tag.
 *
 * Only whitespace between blocks is changed, and that is not significant to a browser or to the
 * editor's parser.
 *
 * @param {string} html
 * @param {{indent?: number, parseHtml: (html: string) => Document}} options
 */
export const prettyHtml = (html, { indent = 2, parseHtml }) => {
    const unit = ' '.repeat(Math.min(8, Math.max(0, Number.isInteger(indent) ? indent : 2)))
    const lines = []

    const put = (depth, text) => {
        lines.push(unit.repeat(depth) + text)
    }

    const children = (parent, depth) => {
        let run = []

        const flush = () => {
            if (run.length === 0) {
                return
            }

            // Whitespace around an inline run that sits next to blocks is not significant.
            const text = run.map(serialize).join('').replace(/^[ \t\r\n\f]+|[ \t\r\n\f]+$/g, '')

            if (text !== '') {
                put(depth, text)
            }

            run = []
        }

        for (const node of Array.from(parent.childNodes)) {
            if (!isBlock(node)) {
                run.push(node)

                continue
            }

            flush()
            block(node, depth)
        }

        flush()
    }

    const block = (node, depth) => {
        const name = nameOf(node)

        if (VOID.has(name) || name === 'pre' || RAW_TEXT.has(name) || !hasBlockChild(node)) {
            put(depth, serialize(node))

            return
        }

        put(depth, openTag(node))
        children(node, depth + 1)
        put(depth, `</${name}>`)
    }

    children(parseHtml(`<!doctype html><body>${html}`).body, 0)

    return lines.join('\n')
}

// --- "did the schema change what was typed" -------------------------------------------------

const ALIASES = { b: 'strong', i: 'em', strike: 's', del: 's' }

// Wrappers an editor schema adds or removes without changing the content: a lone <p> inside a
// list item, table cell or quote is the same content as the bare text.
const P_HOSTS = new Set(['li', 'td', 'th', 'blockquote'])

const WS = /[ \t\r\n\f]+/g
const isSpace = (node) => node.text !== undefined && /^[ \t\r\n\f]*$/.test(node.text)

const build = (domNode) => {
    const out = []

    for (const child of Array.from(domNode.childNodes)) {
        if (child.nodeType === TEXT) {
            out.push({ text: child.data })
        } else if (child.nodeType === ELEMENT) {
            const raw = nameOf(child)
            const attrs = {}

            for (const attribute of Array.from(child.attributes)) {
                attrs[attribute.name.toLowerCase()] = attribute.value
            }

            out.push({ name: ALIASES[raw] || raw, attrs, kids: build(child) })
        }
        // Comments are not content.
    }

    return out
}

const attrless = (el) => Object.keys(el.attrs).length === 0

const fix = (el) => {
    el.kids = el.kids.flatMap((kid) => (kid.name ? fix(kid) : [kid]))

    const meaningful = el.kids.filter((kid) => !isSpace(kid))

    // <pre><code>x</code></pre> (what the editor writes) is the same code block as <pre>x</pre>.
    if (el.name === 'pre' && meaningful.length === 1 && meaningful[0].name === 'code') {
        el.kids = meaningful[0].kids
    }

    // A bare <div>/<span> carries nothing: the schema unwraps them.
    if ((el.name === 'div' || el.name === 'span') && attrless(el)) {
        return el.kids
    }

    if (P_HOSTS.has(el.name) && meaningful.length === 1 && meaningful[0].name === 'p' && attrless(meaningful[0])) {
        el.kids = meaningful[0].kids
    }

    return [el]
}

const mergeText = (kids) => {
    const out = []

    for (const kid of kids) {
        const last = out[out.length - 1]

        if (kid.text !== undefined && last && last.text !== undefined) {
            last.text += kid.text
        } else if (kid.text !== undefined) {
            out.push({ text: kid.text })
        } else {
            out.push(kid)
        }
    }

    return out
}

/** Collapse whitespace (not in <pre>) and drop what a layout engine ignores around blocks. */
const tidy = (el, blockLevel) => {
    if (el.name === 'pre') {
        el.kids = mergeText(el.kids)

        return
    }

    let kids = mergeText(el.kids)

    for (const kid of kids) {
        if (kid.text !== undefined) {
            kid.text = kid.text.replace(WS, ' ')
        }
    }

    kids.forEach((kid, index) => {
        if (kid.text === undefined) {
            return
        }

        const before = kids[index - 1]
        const after = kids[index + 1]

        if (blockLevel && index === 0) {
            kid.text = kid.text.replace(/^ /, '')
        }

        if (blockLevel && index === kids.length - 1) {
            kid.text = kid.text.replace(/ $/, '')
        }

        if (before && before.name && BLOCK.has(before.name)) {
            kid.text = kid.text.replace(/^ /, '')
        }

        if (after && after.name && BLOCK.has(after.name)) {
            kid.text = kid.text.replace(/ $/, '')
        }
    })

    kids = kids.filter((kid) => kid.text === undefined || kid.text !== '')
    el.kids = kids

    for (const kid of kids) {
        if (kid.name) {
            tidy(kid, BLOCK.has(kid.name))
        }
    }
}

/** Canonical form of an HTML string: a tree of {name, attrs, kids} / {text}. */
export const canonicalize = (html, parseHtml) => {
    const root = { name: 'body', attrs: {}, kids: build(parseHtml(`<!doctype html><body>${html}`).body) }

    root.kids = root.kids.flatMap((kid) => (kid.name ? fix(kid) : [kid]))

    // Loose text and inline elements at the top level are what the schema wraps in <p>.
    const wrapped = []
    let run = []

    const flush = () => {
        if (run.length && !run.every(isSpace)) {
            wrapped.push({ name: 'p', attrs: {}, kids: run })
        }

        run = []
    }

    for (const kid of root.kids) {
        if (kid.name && BLOCK.has(kid.name)) {
            flush()
            wrapped.push(kid)
        } else {
            run.push(kid)
        }
    }

    flush()
    root.kids = wrapped
    tidy(root, true)

    // The empty trailing paragraph a schema needs to hold an empty document.
    while (root.kids.length && root.kids[root.kids.length - 1].name === 'p' && attrless(root.kids[root.kids.length - 1]) && root.kids[root.kids.length - 1].kids.length === 0) {
        root.kids.pop()
    }

    return root.kids
}

const styleOf = (value) =>
    value
        .toLowerCase()
        .replace(/\s*([:;])\s*/g, '$1')
        .replace(/;$/, '')

// Everything the user typed on an element must still be there; the editor may ADD attributes
// (a link gets rel/target) and that is not a loss.
const attributesKept = (typed, kept) =>
    Object.entries(typed).every(([name, value]) => {
        if (!(name in kept)) {
            return false
        }

        if (name === 'class') {
            const have = new Set(kept.class.split(WS).filter(Boolean))

            return value.split(WS).filter(Boolean).every((token) => have.has(token))
        }

        if (name === 'style') {
            return styleOf(value) === styleOf(kept.style)
        }

        return value === kept[name]
    })

const nodesKept = (typed, kept) => {
    if (typed.length !== kept.length) {
        return false
    }

    return typed.every((node, index) => {
        const other = kept[index]

        if (node.text !== undefined || other.text !== undefined) {
            return node.text === other.text
        }

        return node.name === other.name && attributesKept(node.attrs, other.attrs) && nodesKept(node.kids, other.kids)
    })
}

/**
 * True when `normalized` (what the editor holds after parsing `typed` through its schema) has
 * lost or changed something of `typed`: an element or attribute the schema does not know, text,
 * a different heading level or list type.
 *
 * "Not material", i.e. ignored: whitespace and indentation, attribute order, comments, `<b>` for
 * `<strong>` (and the other aliases), attributes the editor ADDED, a bare `<div>`/`<span>`, loose
 * top-level text wrapped in `<p>`, a lone `<p>` in a list item or cell, `<pre><code>` for `<pre>`.
 * Deliberately strict otherwise: a false notice is harmless, silent loss is not.
 *
 * @param {string} typed
 * @param {string} normalized
 * @param {(html: string) => Document} parseHtml
 */
export const materiallyDiffers = (typed, normalized, parseHtml) =>
    !nodesKept(canonicalize(typed, parseHtml), canonicalize(normalized, parseHtml))
