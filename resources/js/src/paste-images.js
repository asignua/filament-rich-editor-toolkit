// Pictures pasted from Word and Google Docs: upload them instead of dropping them.
//
// WHY THEY ARE LOST. What the clipboard carries is not a picture. Word puts `<img
// src="file:///C:/.../clip_image001.png">` into text/html (a path the browser may not read) and
// the bytes into text/rtf. Google Docs puts `<img src="https://lh7-rt.googleusercontent.com/...">`
// that a browser cannot fetch from script (no CORS headers). paste-clean.js therefore removes both.
//
// WHAT THIS MODULE DOES. It turns the recoverable ones into `data:image/*` sources BEFORE Filament
// looks at the paste, and Filament does the rest: its own paste handler (extension-local-files.js,
// `localFiles`) already uploads every `data:image/` <img> of a paste through the field's file
// attachment pipeline (type and size validation, Livewire upload, attachment record, `data-id`).
// Nothing here stores a picture or base64 in the content.
//
//   Word          the bytes come from text/rtf: every `{\pict ... \pngblip|\jpegblip <hex>}` group
//                 (see extractRtfPictures) is matched BY ORDER to the `file:` images of the HTML.
//                 If the counts differ nothing is matched (a wrong picture is worse than none).
//   Google Docs   the browser asks the SERVER to download the picture: the editor action
//                 `asignuaFetchRemoteImage` of PasteCleanPlugin (SSRF-guarded there), mounted
//                 through Livewire without a modal, whose return value is a `data:` URL.
//
// HANDLER ORDER, the part that is easy to get wrong. ProseMirror runs `transformPastedHTML` (the
// paste-clean cleanup) first, on the way to building a slice, and only THEN offers the paste to
// the `handlePaste` props of the plugins in order. Filament's `localFiles` handlePaste ignores that
// slice and re-reads `event.clipboardData` itself, synchronously. So the clipboard has to be
// changed before `localFiles` runs, but fetching and decoding is asynchronous. Therefore this
// extension has priority 1000 (TipTap puts the plugins of higher-priority extensions first, and
// `localFiles` has the default 100) and its handlePaste:
//
//   1. does nothing (returns false) for a paste with nothing to recover, and for the paste it
//      dispatches itself, so everything else behaves exactly as before;
//   2. otherwise snapshots the clipboard synchronously (it is unreadable after the handler
//      returns), returns true to stop the default handling, converts asynchronously, and
//   3. dispatches a NEW `paste` event on the editor whose `clipboardData` is a small stand-in
//      carrying the rewritten HTML. Filament's handler and ProseMirror then see an ordinary paste of
//      `data:` pictures. A plain Event with an own `clipboardData` property is used, not
//      `new ClipboardEvent()`, because the ClipboardEvent constructor ignores `clipboardData` in
//      some browsers.
//
// It runs only when the field takes file attachments (the `localFiles` extension exists: it is the
// source of the Livewire handle, the field key, accepted types and the size limit). Pictures
// the module cannot recover are removed and counted; the count is shown with Filament's own
// file-validation message once the upload has finished.
//
// IMPORTANT: `window` and `import.meta` are read ONLY inside the default-export factory.

import { RECOVERED_ATTRIBUTE, newToken, recoveredTokens } from './paste-registry.js'
import { paramsOf } from './ui-style.js'

export const FILE_SCHEME = /^file:/i
const DATA_IMAGE = /^data:image\/(png|jpe?g|gif|webp);base64,[a-z0-9+/=\s]+$/i

// --- RTF -----------------------------------------------------------------

const PNG = [0x89, 0x50, 0x4e, 0x47]
const JPEG = [0xff, 0xd8, 0xff]

const startsWith = (bytes, signature) => signature.every((byte, index) => bytes[index] === byte)

const HEX_VALUE = (() => {
    const table = new Int8Array(128).fill(-1)

    for (let index = 0; index < 10; index++) {
        table[48 + index] = index
    }

    for (let index = 0; index < 6; index++) {
        table[65 + index] = 10 + index
        table[97 + index] = 10 + index
    }

    return table
})()

/**
 * Hex text to bytes. Whitespace is allowed anywhere (RTF wraps its hex), anything else is not.
 * Returns null for an odd number of digits or a stray character.
 *
 * @param {string} text
 * @returns {Uint8Array|null}
 */
export const hexToBytes = (text) => {
    const bytes = new Uint8Array(Math.ceil(text.length / 2))
    let count = 0
    let high = -1

    for (let index = 0; index < text.length; index++) {
        const code = text.charCodeAt(index)

        if (code === 32 || code === 10 || code === 13 || code === 9) {
            continue
        }

        const value = code < 128 ? HEX_VALUE[code] : -1

        if (value < 0) {
            return null
        }

        if (high < 0) {
            high = value
        } else {
            bytes[count++] = (high << 4) | value
            high = -1
        }
    }

    return high < 0 ? bytes.subarray(0, count) : null
}

/**
 * The bytes' MIME type by their first bytes, but only for the two formats Word stores raw
 * (`\pngblip`, `\jpegblip`); everything else (EMF, WMF, DIB, ...) is null.
 *
 * @param {Uint8Array} bytes
 * @param {string|null} blip the RTF control word that announced the format
 */
export const mimeOf = (bytes, blip) => {
    if (blip === 'pngblip' && startsWith(bytes, PNG)) {
        return 'image/png'
    }

    if (blip === 'jpegblip' && startsWith(bytes, JPEG)) {
        return 'image/jpeg'
    }

    return null
}

const IGNORED_DESTINATIONS = new Set(['nonshppict', 'shprslt', 'listpicture', 'pnpict'])
const BLIPS = new Set(['pngblip', 'jpegblip', 'emfblip', 'wmetafile', 'dibitmap', 'wbitmap', 'macpict', 'pmmetafile'])

/**
 * The pictures of an RTF document, in document order: one SLOT per picture, usable or not.
 *
 * A picture is a `\pict` group. Word writes each one twice, a PNG/JPEG in `\shppict` and an EMF
 * fallback in `\nonshppict`; the fallback (and the `\shprslt` fallback of a shape, bullets
 * `\listpicture`, `\pnpict`) is skipped, so a picture is counted once. A slot is
 * `{mime, bytes}` when the picture is a well-formed PNG or JPEG, and `{mime: null, bytes: null}`
 * when it exists but cannot be recovered (EMF/WMF only, binary `\bin` data, bad hex): keeping the
 * slot keeps the ORDER, so the picture after it is still matched to the right <img>.
 *
 * Tokenising, not regexes: a picture is megabytes of hex, and groups nest (`{\*\picprop ...}`,
 * `{\*\blipuid ...}` carry hex-looking text that is not image data).
 *
 * @param {string} rtf
 * @returns {{mime: string|null, bytes: Uint8Array|null}[]}
 */
export const extractRtfPictures = (rtf) => {
    const slots = []

    if (typeof rtf !== 'string' || rtf === '') {
        return slots
    }

    const stack = []
    let group = { ignored: false, pict: null }
    const text = /[^{}\\]+/y
    const word = /([a-zA-Z]+)(-?\d+)? ?/y
    let index = 0

    const finish = (pict) => {
        const bytes = pict.broken || pict.binary ? null : hexToBytes(pict.hex.join(''))
        const mime = bytes === null ? null : mimeOf(bytes, pict.blip)

        slots.push(mime === null ? { mime: null, bytes: null } : { mime, bytes })
    }

    while (index < rtf.length) {
        const char = rtf[index]

        if (char === '{') {
            stack.push(group)
            group = { ignored: group.ignored, pict: null }
            index++
        } else if (char === '}') {
            if (group.pict !== null) {
                finish(group.pict)
            }

            group = stack.pop() ?? { ignored: false, pict: null }
            index++
        } else if (char === '\\') {
            word.lastIndex = index + 1
            const match = word.exec(rtf)

            if (match === null) {
                // A control symbol: `\'hh` (an escaped byte) is three characters, the rest two.
                index += rtf[index + 1] === "'" ? 4 : 2

                continue
            }

            index = word.lastIndex
            const name = match[1]

            if (IGNORED_DESTINATIONS.has(name)) {
                group.ignored = true
            } else if (name === 'pict' && !group.ignored) {
                group.pict = { blip: null, hex: [], binary: false, broken: false }
            } else if (group.pict !== null && BLIPS.has(name)) {
                group.pict.blip = name
            } else if (group.pict !== null && name === 'bin') {
                // Raw bytes follow: not hex, and not worth tokenising. The picture is unusable.
                group.pict.binary = true
                index += Number.parseInt(match[2] ?? '0', 10) || 0
            }
        } else {
            text.lastIndex = index
            const run = text.exec(rtf)

            if (run === null) {
                index++

                continue
            }

            index = text.lastIndex

            // Only the text DIRECTLY in the \pict group is picture data.
            if (group.pict !== null) {
                group.pict.hex.push(run[0])
            }
        }
    }

    return slots
}

// --- HTML ----------------------------------------------------------------

/**
 * Second-level public suffixes: a wildcard over one of them (`*.co.uk`) would allow-list every
 * site of the country. Best effort, not the Public Suffix List. The same list is
 * RemoteImageFetcher::PUBLIC_SUFFIXES; tests/fixtures/host-allow.json pins the two together.
 */
export const PUBLIC_SUFFIXES = [
    'ac.jp',
    'ac.uk',
    'amazonaws.com',
    'appspot.com',
    'azurewebsites.net',
    'blogspot.com',
    'cloudfront.net',
    'co.id',
    'co.il',
    'co.in',
    'co.jp',
    'co.kr',
    'co.nz',
    'co.th',
    'co.uk',
    'co.za',
    'com.ar',
    'com.au',
    'com.br',
    'com.cn',
    'com.co',
    'com.eg',
    'com.es',
    'com.hk',
    'com.mx',
    'com.my',
    'com.pe',
    'com.ph',
    'com.pk',
    'com.pl',
    'com.ru',
    'com.sa',
    'com.sg',
    'com.tr',
    'com.tw',
    'com.ua',
    'com.ve',
    'com.vn',
    'edu.au',
    'edu.cn',
    'edu.ua',
    'firebaseapp.com',
    'github.io',
    'go.jp',
    'gov.au',
    'gov.br',
    'gov.cn',
    'gov.ua',
    'gov.uk',
    'herokuapp.com',
    'in.ua',
    'kiev.ua',
    'kyiv.ua',
    'ltd.uk',
    'lviv.ua',
    'me.uk',
    'ne.jp',
    'net.au',
    'net.br',
    'net.cn',
    'net.in',
    'net.nz',
    'net.pl',
    'net.ru',
    'net.tr',
    'net.ua',
    'net.uk',
    'netlify.app',
    'or.jp',
    'or.kr',
    'org.au',
    'org.br',
    'org.cn',
    'org.in',
    'org.nz',
    'org.pl',
    'org.ru',
    'org.tr',
    'org.ua',
    'org.uk',
    'org.za',
    'pages.dev',
    'plc.uk',
    'sch.uk',
    'vercel.app',
    'web.app',
    'workers.dev',
]

/**
 * Whether `host` matches an allow-list entry: `*.example.com` = any subdomain (never the bare
 * domain; the wildcard needs two more labels, and they must not be a public suffix such as
 * `co.uk`), otherwise an exact, case-insensitive name.
 * The server applies the same rule (RemoteImageFetcher::hostAllowed()); it is the one that decides,
 * this only avoids sending requests that cannot succeed.
 *
 * @param {string} host
 * @param {string[]} patterns
 */
export const hostAllowed = (host, patterns) => {
    const name = String(host).toLowerCase()

    return patterns.some((raw) => {
        const pattern = String(raw).trim().toLowerCase()

        if (pattern.startsWith('*.')) {
            const suffix = pattern.slice(1)

            return (
                suffix.split('.').length > 2 &&
                !PUBLIC_SUFFIXES.includes(suffix.slice(1)) &&
                name.endsWith(suffix) &&
                name.length > suffix.length
            )
        }

        return pattern !== '' && name === pattern
    })
}

/**
 * The https URL of an image that the server may be asked for, or null.
 */
const remoteUrl = (src, hosts) => {
    try {
        const url = new URL(src)

        return url.protocol === 'https:' && url.username === '' && url.password === '' && hostAllowed(url.hostname, hosts)
            ? url.href
            : null
    } catch {
        return null
    }
}

/**
 * The images of a paste that this module can do something about.
 *
 * @param {Document} doc
 * @param {string[]} hosts
 * @returns {{files: Element[], remote: {element: Element, url: string}[]}}
 */
export const findImages = (doc, hosts) => {
    const files = []
    const remote = []

    for (const element of Array.from(doc.querySelectorAll('img[src]'))) {
        const src = (element.getAttribute('src') || '').trim()

        if (FILE_SCHEME.test(src)) {
            files.push(element)

            continue
        }

        const url = remoteUrl(src, hosts)

        if (url !== null) {
            remote.push({ element, url })
        }
    }

    return { files, remote }
}

/**
 * Match the `file:` images of the HTML to the pictures of the RTF, by order. Only an exact
 * count match is trusted: with fewer or more pictures than images the order cannot be known, and a
 * wrong picture in the right place is worse than a missing one.
 *
 * @param {number} imageCount
 * @param {{mime: string|null, bytes: Uint8Array|null}[]} slots
 * @returns {({mime: string, bytes: Uint8Array}|null)[]}
 */
export const matchPictures = (imageCount, slots) =>
    Array.from({ length: imageCount }, (unused, index) =>
        slots.length === imageCount && slots[index].bytes !== null ? slots[index] : null,
    )

export const bytesToDataUrl = (bytes, mime) => {
    let binary = ''

    for (let offset = 0; offset < bytes.length; offset += 0x8000) {
        binary += String.fromCharCode.apply(null, bytes.subarray(offset, offset + 0x8000))
    }

    return `data:${mime};base64,${globalThis.btoa(binary)}`
}

/**
 * The size and type of a `data:` URL without decoding it.
 */
const describeDataUrl = (dataUrl) => {
    const match = /^data:([^;,]+);base64,(.*)$/s.exec(dataUrl)

    if (match === null) {
        return null
    }

    const payload = match[2].replace(/\s+/g, '')
    const padding = payload.endsWith('==') ? 2 : payload.endsWith('=') ? 1 : 0

    return { mime: match[1], size: Math.floor((payload.length * 3) / 4) - padding }
}

/**
 * The same test Filament's `localFiles` applies to a pasted file (extension-local-files.js,
 * validateFiles), so a picture is not uploaded just to be rejected, and is counted as failed.
 *
 * @param {string} dataUrl
 * @param {{acceptedTypes?: string[]|null, maxSize?: number|string|null}} limits `maxSize` in KB
 */
export const fitsLimits = (dataUrl, limits) => {
    const described = describeDataUrl(dataUrl)

    if (described === null || !DATA_IMAGE.test(dataUrl)) {
        return false
    }

    if (limits.acceptedTypes && !limits.acceptedTypes.includes(described.mime)) {
        return false
    }

    return !(limits.maxSize && described.size > Number(limits.maxSize) * 1024)
}

/**
 * Rewrites the HTML of a paste: every recoverable image gets a `data:` source, every other
 * `file:` / allow-listed remote image is removed.
 *
 * @param {string} html
 * @param {{
 *   rtf?: string,
 *   hosts?: string[],
 *   parseHtml: (html: string) => Document,
 *   fetchRemote?: (url: string) => Promise<string>,
 *   limits?: {acceptedTypes?: string[]|null, maxSize?: number|string|null},
 *   token?: string,
 * }} options
 * @returns {Promise<{html: string, converted: number, failed: number}|null>} null: nothing to do
 */
export const recoverImages = async (html, options) => {
    const hosts = options.hosts ?? []
    const limits = options.limits ?? {}
    const doc = options.parseHtml(html)
    const { files, remote } = findImages(doc, hosts)

    if (files.length === 0 && remote.length === 0) {
        return null
    }

    let converted = 0
    let failed = 0

    const settle = (element, dataUrl) => {
        if (typeof dataUrl === 'string' && fitsLimits(dataUrl, limits)) {
            element.setAttribute('src', dataUrl)

            // See paste-registry.js: lets the picture survive paste-clean.js once Filament has
            // replaced `src` with the temporary upload URL.
            if (options.token) {
                element.setAttribute(RECOVERED_ATTRIBUTE, options.token)
            }

            converted++
        } else {
            element.remove()
            failed++
        }
    }

    const pictures = matchPictures(files.length, extractRtfPictures(options.rtf ?? ''))

    files.forEach((element, index) => {
        const picture = pictures[index]

        settle(element, picture === null ? null : bytesToDataUrl(picture.bytes, picture.mime))
    })

    // One request per distinct URL (Google Docs repeats a picture used twice), one at a time:
    // Livewire serialises the requests of a component anyway.
    const fetched = new Map()

    for (const { element, url } of remote) {
        if (!fetched.has(url)) {
            let dataUrl = null

            try {
                dataUrl = options.fetchRemote ? await options.fetchRemote(url) : null
            } catch {
                // A failed picture is a counted, removed picture; the paste goes on.
            }

            fetched.set(url, dataUrl)
        }

        settle(element, fetched.get(url))
    }

    return { html: doc.documentElement.outerHTML, converted, failed }
}

// --- the paste handler -----------------------------------------------------

/**
 * The name a real `DataTransfer.getData()` resolves a type to: it lower-cases the argument and
 * maps the legacy `text` to `text/plain` and `url` to `text/uri-list`. Filament's `localFiles`
 * asks for `getData('text')`.
 */
export const normalizeType = (type) => {
    const name = String(type).toLowerCase()

    return name === 'text' ? 'text/plain' : name === 'url' ? 'text/uri-list' : name
}

/**
 * The stand-in for `event.clipboardData` of the re-dispatched paste: what ProseMirror and
 * Filament read from it (`getData`, `types`, `files`, `items`), and nothing else. `getData`
 * resolves the legacy names like the real thing ({@link normalizeType}); without it
 * `getData('text')` is '' and Filament's `localFiles` takes the paste for a bare file paste.
 */
export const makeClipboard = (data, files) => {
    const store = {}

    for (const [type, value] of Object.entries(data)) {
        store[normalizeType(type)] = value
    }

    return {
        types: Object.keys(store),
        files,
        items: [],
        getData: (type) => store[normalizeType(type)] ?? '',
        setData: () => {},
    }
}

export const REDISPATCHED = Symbol('asignuaPasteImages')

// How long a recovery token stays valid at most (see paste-registry.js).
const TOKEN_LIFETIME = 90000

/**
 * @param {{
 *   getLocalFiles: () => ({options: object}|undefined),
 *   hosts: string[],
 *   action: string,
 *   message: string,
 *   parseHtml?: (html: string) => Document,
 * }} config
 * @returns {(view: object, event: object) => boolean} a ProseMirror `handlePaste`
 */
export const createPasteHandler = (config) => (view, event) => {
    const clipboard = event.clipboardData

    if (event[REDISPATCHED] || !clipboard) {
        return false
    }

    const html = clipboard.getData('text/html') || ''
    const localFiles = config.getLocalFiles()
    const hasText = (clipboard.getData('text') || '').length > 0
    const files = clipboard.files?.length ? Array.from(clipboard.files) : []

    // Nothing to recover, a paste from inside an editor, a field without attachments, or a paste
    // of files (a screenshot): the default handling stays in charge.
    if (
        !localFiles ||
        html === '' ||
        html.includes('data-pm-slice') ||
        (files.length > 0 && !hasText) ||
        !/<img\b/i.test(html)
    ) {
        return false
    }

    const parseHtml = config.parseHtml ?? ((source) => new view.dom.ownerDocument.defaultView.DOMParser().parseFromString(source, 'text/html'))
    const probe = findImages(parseHtml(html), config.hosts)

    if (probe.files.length === 0 && probe.remote.length === 0) {
        return false
    }

    // From here on this handler owns the paste. Everything readable is read NOW.
    const snapshot = {}

    for (const type of Array.from(clipboard.types ?? [])) {
        if (type !== 'text/html' && type !== 'text/rtf' && type !== 'Files') {
            snapshot[type] = clipboard.getData(type)
        }
    }

    snapshot['text/plain'] ??= clipboard.getData('text/plain') || ''
    const rtf = clipboard.getData('text/rtf') || ''
    const options = localFiles.options
    const { doc, selection } = view.state
    const win = view.dom.ownerDocument.defaultView

    const announce = (detail) =>
        view.dom.dispatchEvent(
            new win.CustomEvent(detail.name, {
                bubbles: true,
                detail: { key: options.key, livewireId: options.get$WireUsing().id, ...detail.data },
            }),
        )

    const fetchRemote = async (url) => {
        const result = await options.get$WireUsing().mountAction(config.action, { url }, { schemaComponent: options.key })

        if (!result || typeof result.dataUrl !== 'string') {
            throw new Error(result?.error ?? 'no answer')
        }

        return result.dataUrl
    }

    // The form is captured NOW: when the editor is destroyed during the fetch (a tab switch, a
    // Livewire re-render) `view.dom` is detached and `closest('form')` would find nothing, and the
    // form would stay "processing" for ever.
    const form = view.dom.closest('form')
    const token = newToken()

    announce({ name: 'rich-editor-uploading-file' })
    form?.dispatchEvent(
        new win.CustomEvent('form-processing-started', { composed: true, cancelable: true, detail: { message: options.uploadingMessage } }),
    )

    const finish = () => {
        // Each step on its own: a Livewire component that is already gone makes `announce` throw,
        // and the form must be released regardless.
        try {
            announce({ name: 'rich-editor-uploaded-file' })
        } catch (error) {
            console.error('[rich-editor-toolkit] could not announce the end of the paste upload:', error)
        }

        form?.dispatchEvent(new win.CustomEvent('form-processing-finished', { composed: true, cancelable: true, detail: {} }))
    }

    ;(async () => {
        let outcome = null

        try {
            outcome = await recoverImages(html, {
                rtf,
                hosts: config.hosts,
                parseHtml,
                fetchRemote,
                token,
                limits: { acceptedTypes: options.acceptedTypes, maxSize: options.maxSize },
            })
        } catch (error) {
            console.error('[rich-editor-toolkit] pasted images could not be processed:', error)
        }

        // Whatever happens next, the spinner and the hold on the form end here and now, BEFORE the
        // paste is re-dispatched: Filament's handler starts its own right away, and its "uploading"
        // flag is a boolean, not a counter.
        try {
            if (view.isDestroyed) {
                return
            }

            // The user kept typing: leave the cursor where they put it. Otherwise the paste lands
            // where it was made.
            if (view.state.doc === doc) {
                view.dispatch(view.state.tr.setSelection(selection))
            }
        } catch (error) {
            console.error('[rich-editor-toolkit] could not restore the cursor of a pasted picture:', error)
        } finally {
            finish()
        }

        if (view.isDestroyed) {
            return
        }

        // The recovered pictures keep their stamp through Filament's upload; the token is valid
        // until Filament says it is done (or a minute and a half passed: the paste may have had
        // nothing left to upload, in which case that event never comes).
        const tokens = recoveredTokens()

        if (outcome !== null && outcome.converted > 0) {
            const forget = () => tokens.delete(token)

            tokens.add(token)
            view.dom.addEventListener('rich-editor-uploaded-file', forget, { once: true })
            const expiry = setTimeout(forget, TOKEN_LIFETIME)

            // Under Node (the tests) the timer must not keep the process alive.
            expiry?.unref?.()
        }

        const rewritten = new win.Event('paste', { bubbles: true, cancelable: true })

        rewritten[REDISPATCHED] = true
        // `files: []`: this handler already decided that the HTML wins (it only runs when there is
        // text, or no files). Word for Mac also puts a PNG of the selection on the clipboard; handed
        // on, it would make Filament upload that picture too.
        Object.defineProperty(rewritten, 'clipboardData', {
            value: makeClipboard({ ...snapshot, 'text/html': outcome === null ? html : outcome.html }, []),
        })

        // Filament's handler (or, with nothing left to upload, the plain paste) takes it from here.
        view.dom.dispatchEvent(rewritten)

        const failed = outcome === null ? probe.files.length + probe.remote.length : outcome.failed

        if (failed > 0) {
            const show = () =>
                announce({
                    name: 'rich-editor-file-validation-message',
                    data: { validationMessage: config.message.replace(':count', String(failed)) },
                })

            if (outcome !== null && outcome.converted > 0) {
                // Filament's upload is still running: its "uploading" state would hide the message
                // (and its start clears one). Say it when that upload is over.
                view.dom.addEventListener('rich-editor-uploaded-file', () => setTimeout(show, 0), { once: true })
            } else {
                show()
            }
        }
    })()

    return true
}

export const settingsFromParams = (params) => ({
    hosts: params.getAll('host').filter((host) => host !== ''),
    action: params.get('action') || 'asignuaFetchRemoteImage',
    message: params.get('msg') || 'Images that could not be imported: :count',
})

export default () => {
    const { Extension } = window.FilamentRichEditor.tiptap.core
    const { Plugin, PluginKey } = window.FilamentRichEditor.tiptap.pmState
    const settings = settingsFromParams(paramsOf(import.meta.url))

    return Extension.create({
        name: 'asignuaPasteImages',
        // Before Filament's `localFiles` (100): its handlePaste must see the rewritten clipboard.
        priority: 1000,
        addProseMirrorPlugins() {
            const editor = this.editor
            const handler = createPasteHandler({
                ...settings,
                getLocalFiles: () => editor.extensionManager.extensions.find((extension) => extension.name === 'localFiles'),
            })

            return [
                new Plugin({
                    key: new PluginKey('asignuaPasteImages'),
                    props: {
                        handlePaste(view, event) {
                            try {
                                return handler(view, event)
                            } catch (error) {
                                console.error('[rich-editor-toolkit] paste image handling failed:', error)

                                return false
                            }
                        },
                    },
                }),
            ]
        },
    })
}
