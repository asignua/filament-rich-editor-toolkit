// Strict paste: reduce pasted HTML to what the field's toolbar offers
// (filamentphp/filament discussions #20484, #20048).
//
// Why the toolbar and not the schema: Filament always loads the WHOLE TipTap schema (tables,
// headings, lists, every mark), whatever `toolbarButtons()` says, so a field with only a Bold
// button still accepts a pasted table, `<h1>` and links. The toolbar is the only place where a
// developer says what the field is for.
//
// How the JS learns the toolbar: from the DOM, in `allowedFromToolbar()`. A RichContentPlugin
// cannot see the field (its methods get no editor), and a macro would not cover the global
// `paste_clean.strict` config. Every stock tool is rendered with an Alpine class binding that
// carries the TipTap node/mark name it toggles: `x-bind:class="{ 'fi-active': editorUpdatedAt
// && $getEditor()?.isActive('bold', []) }"`. That name is the very vocabulary needed here
// (`bold`, `heading` + level, `bulletList`, `table` ...), it does not depend on the translated
// label, and Alpine leaves the attribute in the DOM. A PHP test pins the rendered shape, so a
// Filament change that moves it fails the suite instead of silently turning strict off.
//
// Fail open: no toolbar (none rendered, no recognisable button) -> `null` -> nothing is
// restricted. "I cannot tell" must not mean "strip everything".
//
// Mapping, with the choices that are NOT obvious:
//   table                    no `table` button          -> cells become paragraphs
//   h1..h6                   no heading button at all   -> paragraph
//                            some, but not this level   -> the NEAREST allowed level; on a tie
//                                                          the deeper one (h2/h4 offered, h3
//                                                          pasted -> h4: demote, never promote)
//   bulletList / orderedList neither                    -> items become paragraphs
//                            only one of them           -> the other kind is converted to it
//   blockquote, codeBlock    no button                  -> paragraph(s)
//   horizontalRule           no button                  -> removed
//   bold italic underline strike subscript superscript code highlight link
//                            no button                  -> the element is unwrapped (text stays)
// Everything else (images, embeds, custom blocks, grid, details, mentions) has no
// toolbar-controlled counterpart here and is left alone.

const HEADING_TAG = /^h([1-6])$/

const MARKS = {
    strong: 'bold',
    b: 'bold',
    em: 'italic',
    i: 'italic',
    u: 'underline',
    s: 'strike',
    del: 'strike',
    strike: 'strike',
    sub: 'subscript',
    sup: 'superscript',
    code: 'code',
    mark: 'highlight',
    a: 'link',
}

// Tags that count as a block when a container is dissolved into paragraphs.
const BLOCKS = new Set(['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'blockquote', 'pre', 'table', 'div', 'hr'])

const tagOf = (el) => el.tagName.toLowerCase()

const isBlank = (el) => (el.textContent || '').replace(/[ \t\r\n\f]+/g, '') === '' && el.querySelector('img, iframe, hr') === null

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
            // An attribute name the DOM refuses - skip it.
        }
    }

    while (el.firstChild) {
        next.appendChild(el.firstChild)
    }

    el.replaceWith(next)

    return next
}

// Move the children of `source` into `target`, keeping blocks as they are and putting each run
// of inline content (text, marks, <br>, images) into a paragraph of its own: dissolving a
// container must neither glue "a" and a nested list together nor leave bare text between blocks.
const appendAsBlocks = (source, target) => {
    const doc = source.ownerDocument
    let run = null

    const flush = () => {
        if (run !== null && !isBlank(run)) {
            target.appendChild(run)
        }

        run = null
    }

    while (source.firstChild) {
        const child = source.firstChild

        if (child.nodeType === 1 && BLOCKS.has(tagOf(child))) {
            flush()
            target.appendChild(child)
        } else {
            run = run || doc.createElement('p')
            run.appendChild(child)
        }
    }

    flush()
}

const dissolve = (el) => {
    const fragment = el.ownerDocument.createDocumentFragment()

    appendAsBlocks(el, fragment)
    el.replaceWith(fragment)
}

// Reverse document order: inner elements before the elements that contain them.
const descending = (root, selector) => Array.from(root.querySelectorAll(selector)).reverse()

/** Toolkit tools that only borrow another tool's active state (see {@see allowedFromToolbar}). */
const BORROW_ONLY_TOOLS = new Set(['linkOptions', 'imageProperties'])

/**
 * The set of tool keys the field offers, or null when the toolbar cannot be read.
 *
 * Keys are the TipTap names the buttons toggle, with the heading level folded in:
 * `bold`, `italic`, `link`, `table`, `bulletList`, `h2` ...
 *
 * @param {Element|null} root the field wrapper (`.fi-fo-rich-editor`)
 * @returns {Set<string>|null}
 */
export const allowedFromToolbar = (root) => {
    if (!root || typeof root.querySelectorAll !== 'function') {
        return null
    }

    const keys = new Set()

    for (const toolbar of Array.from(
        root.querySelectorAll('.fi-fo-rich-editor-toolbar, .fi-fo-rich-editor-floating-toolbar'),
    )) {
        for (const button of Array.from(toolbar.querySelectorAll('button'))) {
            const binding = button.getAttribute('x-bind:class') || ''
            const calls = binding.split('isActive(').slice(1)

            // The toolkit's OWN tools (`linkOptions`, `imageProperties`) merely borrow the active state
            // of a stock one (`activeKey('link')` / `activeKey('image')`, so that they light up inside a
            // link / on an image) and mount an action of a different name: they must not count as the
            // mark being offered, a field with only "Link options" has no Link button. Any OTHER tool
            // that mounts its own dialog counts as what its `isActive()` names: a third-party link
            // editor (the rich-links plugin: `isActive('link')` + `mountAction('richLink')`) is a real
            // way to create links, and paste must not strip them from its field. Buttons without a
            // mountAction (bold, lists, table, ...) run a command directly and are unaffected.
            // Filament 5.10 renders the call as `mountToolAction(name, ...)`, 5.9 as `$wire.mountAction(name, ...)`.
            const mounted = (button.getAttribute('x-on:click') || button.getAttribute('@click') || '').match(
                /mount(?:Tool)?Action\(\s*['"]([A-Za-z0-9_-]+)['"]/,
            )

            for (const call of calls) {
                const name = call.match(/^\s*["']([A-Za-z]+)["']/)

                if (!name) {
                    continue
                }

                if (mounted && BORROW_ONLY_TOOLS.has(mounted[1]) && mounted[1] !== name[1]) {
                    continue
                }

                if (name[1] === 'heading') {
                    // Js::from() writes the options as JSON.parse('{"level":2}'), the quotes
                    // as the six characters "; a plain object literal would use real quotes.
                    const level = call.slice(0, 160).match(/level(?:\\u0022|\\?["'])?\s*:\s*([1-6])/)

                    if (level) {
                        keys.add(`h${level[1]}`)
                    }
                } else {
                    keys.add(name[1])
                }
            }
        }
    }

    return keys.size === 0 ? null : keys
}

const nearestHeading = (level, allowed) => {
    let best = null

    for (let candidate = 1; candidate <= 6; candidate++) {
        if (!allowed.has(`h${candidate}`)) {
            continue
        }

        const distance = Math.abs(candidate - level)

        // `<=`: on a tie the later (deeper) level wins.
        if (best === null || distance <= best.distance) {
            best = { level: candidate, distance }
        }
    }

    return best === null ? null : best.level
}

const tableToParagraphs = (table) => {
    const fragment = table.ownerDocument.createDocumentFragment()

    // Nested tables were replaced first (reverse document order), so only real blocks remain.
    for (const cell of Array.from(table.querySelectorAll('td, th, caption'))) {
        appendAsBlocks(cell, fragment)
    }

    table.replaceWith(fragment)
}

const listToParagraphs = (list) => {
    const fragment = list.ownerDocument.createDocumentFragment()

    for (const item of Array.from(list.children)) {
        appendAsBlocks(item, fragment)
    }

    list.replaceWith(fragment)
}

const preToParagraph = (pre) => {
    const doc = pre.ownerDocument
    const paragraph = doc.createElement('p')
    const lines = (pre.textContent || '').replace(/\r\n?/g, '\n').replace(/\n+$/, '').split('\n')

    lines.forEach((line, index) => {
        if (index > 0) {
            paragraph.appendChild(doc.createElement('br'))
        }

        paragraph.appendChild(doc.createTextNode(line))
    })

    pre.replaceWith(paragraph)
}

/**
 * Remove from `html` everything the toolbar `allowed` does not offer. Pure: no editor, no
 * window. Returns the input unchanged (same string) when nothing needed to change.
 *
 * It also runs on a paste from inside an editor (`data-pm-slice`): the field's toolbar is the
 * rule, and two editors on one page may differ. When it does change such a paste it drops the
 * `data-pm-slice` marker, because the marker's open depths describe the structure it changed.
 *
 * @param {string} html
 * @param {Set<string>|null} allowed from allowedFromToolbar(); null = do not restrict
 * @param {{parseHtml?: (html: string) => Document}} options
 * @returns {string}
 */
export const restrictToToolbar = (html, allowed, options = {}) => {
    if (!(allowed instanceof Set) || typeof html !== 'string' || html.trim() === '') {
        return html
    }

    const parseHtml =
        options.parseHtml || ((source) => new globalThis.DOMParser().parseFromString(source, 'text/html'))
    const doc = parseHtml(html)
    const body = doc && doc.body

    if (!body) {
        return html
    }

    let changed = false

    if (!allowed.has('table')) {
        for (const table of descending(body, 'table')) {
            tableToParagraphs(table)
            changed = true
        }
    }

    const hasBullet = allowed.has('bulletList')
    const hasOrdered = allowed.has('orderedList')

    if (!hasBullet && !hasOrdered) {
        for (const list of descending(body, 'ul, ol')) {
            listToParagraphs(list)
            changed = true
        }
    } else if (!hasBullet) {
        for (const list of descending(body, 'ul')) {
            renameTo(list, 'ol')
            changed = true
        }
    } else if (!hasOrdered) {
        for (const list of descending(body, 'ol')) {
            renameTo(list, 'ul')
            changed = true
        }
    }

    for (const heading of descending(body, 'h1, h2, h3, h4, h5, h6')) {
        const level = Number(tagOf(heading).match(HEADING_TAG)[1])

        if (allowed.has(`h${level}`)) {
            continue
        }

        const target = nearestHeading(level, allowed)

        renameTo(heading, target === null ? 'p' : `h${target}`)
        changed = true
    }

    if (!allowed.has('blockquote')) {
        for (const quote of descending(body, 'blockquote')) {
            dissolve(quote)
            changed = true
        }
    }

    if (!allowed.has('codeBlock')) {
        for (const pre of descending(body, 'pre')) {
            preToParagraph(pre)
            changed = true
        }
    }

    if (!allowed.has('horizontalRule')) {
        for (const rule of Array.from(body.querySelectorAll('hr'))) {
            rule.remove()
            changed = true
        }
    }

    for (const el of descending(body, Object.keys(MARKS).join(','))) {
        const name = tagOf(el)

        // `<pre><code>` is the code BLOCK; it is the codeBlock button's business, not `code`'s.
        if (name === 'code' && el.parentElement && tagOf(el.parentElement) === 'pre') {
            continue
        }

        if (!allowed.has(MARKS[name])) {
            unwrap(el)
            changed = true
        }
    }

    if (!changed) {
        return html
    }

    for (const marked of Array.from(body.querySelectorAll('[data-pm-slice]'))) {
        marked.removeAttribute('data-pm-slice')
    }

    return body.innerHTML
}
