// TipTap node for <iframe> embeds (YouTube / Vimeo / allow-listed hosts).
//
// The stock editor schema has no iframe node, so a pasted or hand-written <iframe> is dropped.
// This node keeps it, but ONLY when its `src` passes the same allow-list the PHP side applies
// (see Asignua\RichEditorToolkit\Support\EmbedSource). The check is duplicated here so a
// foreign iframe is rejected the moment it is pasted, not silently at save time.
//
// Allow-list entries arrive in the module URL as repeated `host=` parameters, see ./config.js.
// An entry is `host` or `host/path/prefix`; https is mandatory.

import { readList } from './config.js'

// A prefix matches on a segment boundary only: `maps/embed` accepts `/maps/embed` and
// `/maps/embed/x`, never `/maps/embedded`.
const pathMatches = (path, entryPath) => {
    if (entryPath === '' || path === entryPath) {
        return true
    }

    return path.startsWith(entryPath.endsWith('/') ? entryPath : `${entryPath}/`)
}

/**
 * @param {string} src
 * @param {string[]} entries allow-list entries
 */
export const isAllowedSrc = (src, entries) => {
    const raw = String(src || '').trim()
    let url

    // `URL` normalises the path (`/embed/../../url` becomes `/url`), the PHP side compares it
    // raw. Refuse what a browser would rewrite (dot segments, encoded dots and slashes,
    // backslashes) so both sides give the same answer.
    if (/(^|\/)\.\.?(\/|$)|%2e|%2f|%5c|\\/i.test(raw.split(/[?#]/)[0].replace(/^[a-z]+:\/\//i, ''))) {
        return false
    }

    try {
        url = new URL(raw)
    } catch {
        return false
    }

    if (url.protocol !== 'https:' || url.username !== '' || url.password !== '') {
        return false
    }

    const host = url.hostname.toLowerCase()

    return entries.some((entry) => {
        const slash = entry.indexOf('/')
        const entryHost = (slash === -1 ? entry : entry.slice(0, slash)).toLowerCase()
        const entryPath = slash === -1 ? '' : entry.slice(slash)

        return host === entryHost && pathMatches(url.pathname, entryPath)
    })
}

const ATTRIBUTES = [
    'src',
    'width',
    'height',
    'allow',
    'title',
    'sandbox',
    'loading',
    'referrerpolicy',
    'frameborder',
]

export default () => {
    const { Node } = window.FilamentRichEditor.tiptap.core
    const hosts = readList(import.meta.url, 'host')

    return Node.create({
        name: 'iframe',
        group: 'block',
        atom: true,
        draggable: true,
        addAttributes() {
            const attributes = Object.fromEntries(
                ATTRIBUTES.map((name) => [
                    name,
                    { default: null, parseHTML: (element) => element.getAttribute(name) },
                ]),
            )

            attributes.allowfullscreen = {
                default: null,
                parseHTML: (element) => (element.hasAttribute('allowfullscreen') ? 'allowfullscreen' : null),
            }

            return attributes
        },
        parseHTML() {
            return [
                {
                    tag: 'iframe',
                    getAttrs: (element) => (isAllowedSrc(element.getAttribute('src'), hosts) ? {} : false),
                },
            ]
        },
        renderHTML({ HTMLAttributes }) {
            const rendered = {}

            for (const [name, value] of Object.entries(HTMLAttributes)) {
                if (value !== null && value !== undefined && value !== '') {
                    rendered[name] = value
                }
            }

            return ['iframe', rendered]
        },
    })
}
