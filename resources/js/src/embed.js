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

// --- video links ----------------------------------------------------------
//
// A port of Asignua\RichEditorToolkit\Support\VideoEmbed::parse()/embedUrl() and of
// EmbedIframe::canonicalSrc(): a recognised video link that is not in the built-in form
// (YouTube's own `www.youtube.com/embed/ID`, `watch?v=`, `youtu.be`, `vimeo.com/ID`) is rebuilt
// instead of dropped. Keep the patterns identical to the PHP ones.

const NOT_AN_ID = ['videoseries', 'live_stream']

const YOUTUBE =
    /^(?:https?:\/\/)?(?:www\.|m\.)?youtube(?:-nocookie)?\.com\/(?:watch\?(?:[^#]*&)?v=|embed\/|shorts\/|live\/|v\/)([A-Za-z0-9_-]{6,20})(?![A-Za-z0-9_-])/i
const YOUTUBE_SHORT = /^(?:https?:\/\/)?(?:www\.)?youtu\.be\/([A-Za-z0-9_-]{6,20})(?![A-Za-z0-9_-])/i
const VIMEO = /^(?:https?:\/\/)?(?:www\.)?vimeo\.com\/(?:video\/)?(\d{6,12})(?:\/([0-9a-f]{6,20}))?(?![A-Za-z0-9_-])/i
const VIMEO_PLAYER = /^(?:https?:\/\/)?player\.vimeo\.com\/video\/(\d{6,12})(?![A-Za-z0-9_-])/i

const queryOf = (url) => {
    const hash = url.indexOf('#')
    const query = url.indexOf('?')

    if (query === -1 || (hash !== -1 && hash < query)) {
        return new URLSearchParams('')
    }

    return new URLSearchParams(url.slice(query + 1, hash === -1 ? undefined : hash))
}

const parseStart = (url) => {
    const params = queryOf(url)
    const raw = params.get('start') || params.get('t') || ''

    if (/^\d+$/.test(raw)) {
        return Number(raw) > 0 ? Number(raw) : null
    }

    const match = raw.match(/^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/i)

    if (raw !== '' && match) {
        const seconds = Number(match[1] || 0) * 3600 + Number(match[2] || 0) * 60 + Number(match[3] || 0)

        return seconds > 0 ? seconds : null
    }

    return null
}

export const parseVideo = (input) => {
    const url = String(input || '').trim()

    if (url === '') {
        return null
    }

    const start = parseStart(url)

    for (const [pattern, provider] of [
        [YOUTUBE, 'youtube'],
        [YOUTUBE_SHORT, 'youtube'],
        [VIMEO, 'vimeo'],
        [VIMEO_PLAYER, 'vimeo'],
    ]) {
        const match = url.match(pattern)

        if (!match) {
            continue
        }

        if (NOT_AN_ID.includes(match[1].toLowerCase())) {
            return null
        }

        let hash = null

        if (provider === 'vimeo') {
            const queryHash = queryOf(url).get('h') || ''

            hash = match[2] ? match[2].toLowerCase() : /^[0-9a-f]{6,20}$/i.test(queryHash) ? queryHash.toLowerCase() : null
        }

        return { provider, id: match[1], start, hash }
    }

    return null
}

export const embedUrl = ({ provider, id, start, hash }) => {
    if (provider === 'vimeo') {
        const query = hash && /^[0-9a-f]{6,20}$/i.test(hash) ? `?h=${hash.toLowerCase()}` : ''
        const fragment = start ? `#t=${start}s` : ''

        return `https://player.vimeo.com/video/${id}${query}${fragment}`
    }

    return `https://www.youtube-nocookie.com/embed/${id}?rel=0${start ? `&start=${start}` : ''}`
}

/**
 * The `src` an iframe gets when it is parsed: a source that already passes the allow-list is
 * kept, a recognised video link is rebuilt, anything else is returned unchanged (and then
 * refused by isAllowedSrc).
 *
 * @param {string|null} src
 * @param {string[]} entries allow-list entries
 */
export const canonicalSrc = (src, entries) => {
    const raw = String(src || '').trim()

    if (raw === '' || isAllowedSrc(raw, entries)) {
        return raw
    }

    const video = parseVideo(raw)

    return video === null ? raw : embedUrl(video)
}

// --- rendering ---------------------------------------------------------------

/**
 * What the editor puts into the DOM for an iframe node.
 *
 * The node's attributes do not have to come from parseHTML: Filament hands the editor its state
 * as JSON, and with `RichEditor::json()` a posted document is stored as it is, so a node can
 * carry any `src` (`javascript:`, a foreign page). An iframe whose `src` fails the allow-list
 * is therefore replaced by an inert placeholder here too, and the configured `sandbox`, `allow`
 * and `referrerpolicy` override whatever the node carried, as they do on the server.
 *
 * @param {Record<string, unknown>} attributes
 * @param {string[]} hosts allow-list entries
 * @param {Record<string, string>} forced attributes that win over the node's own
 * @returns {Array}
 */
export const renderIframe = (attributes, hosts, forced = {}) => {
    if (!isAllowedSrc(attributes.src, hosts)) {
        return ['div', { 'data-asignua-embed-blocked': '' }]
    }

    const rendered = {}

    for (const [name, value] of Object.entries({ ...attributes, ...forced })) {
        if (value !== null && value !== undefined && value !== '') {
            rendered[name] = value
        }
    }

    return ['iframe', rendered]
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

    // The hardening the server forces on render (EmbedIframe::hardening()), passed in the URL.
    const forced = {}

    for (const name of ['sandbox', 'allow', 'referrerpolicy']) {
        const [value] = readList(import.meta.url, name)

        if (value !== undefined) {
            forced[name] = value
        }
    }

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

            // A recognised video link in another form is rebuilt, as on the server.
            attributes.src.parseHTML = (element) => canonicalSrc(element.getAttribute('src'), hosts) || null

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
                    getAttrs: (element) =>
                        isAllowedSrc(canonicalSrc(element.getAttribute('src'), hosts), hosts) ? {} : false,
                },
            ]
        },
        renderHTML({ HTMLAttributes }) {
            return renderIframe(HTMLAttributes, hosts, forced)
        },
    })
}
