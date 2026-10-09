// Suite for resources/js/src/paste-images.js: the RTF picture extractor, the order-based matching of
// Word's pictures to its <img> tags, the recovery of Google Docs pictures through an injected fetch,
// and the paste handler itself against a real jsdom element (the re-dispatched paste event).

import { test } from 'node:test'
import assert from 'node:assert/strict'
import { JSDOM } from 'jsdom'

import factory, {
    bytesToDataUrl,
    createPasteHandler,
    extractRtfPictures,
    findImages,
    fitsLimits,
    hexToBytes,
    hostAllowed,
    matchPictures,
    mimeOf,
    recoverImages,
    settingsFromParams,
} from '../../resources/js/src/paste-images.js'

const dom = new JSDOM('<!doctype html><form><div id="editor"></div></form>')
const window = dom.window
const parseHtml = (html) => new window.DOMParser().parseFromString(html, 'text/html')

// --- fixtures ------------------------------------------------------------

const PNG_HEX = '89504e470d0a1a0a0000000d4948445200000001000000010806000000'
const PNG_HEX_B = '89504e470d0a1a0a0000000d49484452000000020000000208060000aa'
const JPEG_HEX = 'ffd8ffe000104a46494600010100000100010000ffd9'
const EMF_HEX = '0100000058000000000000000000000000000000'

const wrapHex = (hex) => hex.replace(/(.{64})/g, '$1\r\n')

// What Word puts on the clipboard for an inline picture: the PNG in \shppict (inside a \*
// destination) and an EMF fallback in \nonshppict. The property groups carry hex-looking text
// that is NOT image data.
const wordPicture = (hex, { blip = 'pngblip' } = {}) =>
    `{\\field{\\*\\fldinst INCLUDEPICTURE}{\\fldrslt ` +
    `{\\*\\shppict{\\pict{\\*\\picprop\\shplid1025{\\sp{\\sn shapeType}{\\sv 75}}{\\sp{\\sn fLayoutInCell}{\\sv 1}}}` +
    `\\picscalex100\\picscaley100\\piccropl0\\piccropr0\\piccropt0\\piccropb0\\picw1000\\pich800\\picwgoal600\\pichgoal480\\${blip}\\bliptag123456789` +
    `{\\*\\blipuid 0000000000000000deadbeefdeadbeef}\r\n${wrapHex(hex)}}}` +
    `{\\nonshppict{\\pict\\picscalex100\\picscaley100\\picw1000\\pich800\\picwgoal600\\pichgoal480\\emfblip\\bliptag123456789\r\n${wrapHex(EMF_HEX)}}}` +
    `}}`

const rtfDocument = (...body) =>
    `{\\rtf1\\ansi\\ansicpg1252\\deff0{\\fonttbl{\\f0\\fswiss Calibri;}}{\\colortbl;\\red0\\green0\\blue0;}\\pard\\plain\\f0 Before ${body.join(' text between ')} After\\par}`

const word = (count, extra = '') =>
    `<html><body><p class=MsoNormal>Before ${Array.from({ length: count }, (u, index) => `<img width=300 height=200 src="file:///C:/Users/me/AppData/Local/Temp/msohtmlclip1/01/clip_image00${index + 1}.png" v:shapes="Picture_x0020_${index + 1}">`).join(' between ')} After</p>${extra}</body></html>`

// --- hexToBytes / mimeOf -----------------------------------------------------

test('hexToBytes: digits, any case, wrapped lines', () => {
    assert.deepEqual(Array.from(hexToBytes('89 50\r\n4E47\tff')), [0x89, 0x50, 0x4e, 0x47, 0xff])
    assert.deepEqual(Array.from(hexToBytes('')), [])
})

test('hexToBytes: an odd digit count or a stray character is refused', () => {
    assert.equal(hexToBytes('8950f'), null)
    assert.equal(hexToBytes('89zz'), null)
    assert.equal(hexToBytes('89\u00e950'), null)
})

test('mimeOf: only a PNG/JPEG that really starts like one, under the matching blip word', () => {
    assert.equal(mimeOf(hexToBytes(PNG_HEX), 'pngblip'), 'image/png')
    assert.equal(mimeOf(hexToBytes(JPEG_HEX), 'jpegblip'), 'image/jpeg')
    assert.equal(mimeOf(hexToBytes(JPEG_HEX), 'pngblip'), null, 'the word and the bytes must agree')
    assert.equal(mimeOf(hexToBytes(EMF_HEX), 'emfblip'), null)
    assert.equal(mimeOf(hexToBytes(PNG_HEX), null), null)
})

// --- extractRtfPictures ------------------------------------------------------

test('RTF: a Word picture is counted once (shppict), its EMF fallback (nonshppict) is skipped', () => {
    const slots = extractRtfPictures(rtfDocument(wordPicture(PNG_HEX)))

    assert.equal(slots.length, 1)
    assert.equal(slots[0].mime, 'image/png')
    assert.deepEqual(Array.from(slots[0].bytes), Array.from(hexToBytes(PNG_HEX)))
})

test('RTF: pictures come in document order, PNG and JPEG alike', () => {
    const slots = extractRtfPictures(rtfDocument(wordPicture(PNG_HEX), wordPicture(JPEG_HEX, { blip: 'jpegblip' }), wordPicture(PNG_HEX_B)))

    assert.deepEqual(
        slots.map((slot) => slot.mime),
        ['image/png', 'image/jpeg', 'image/png'],
    )
    assert.deepEqual(Array.from(slots[2].bytes), Array.from(hexToBytes(PNG_HEX_B)))
})

test('RTF: the hex-looking text of {\\*\\blipuid} and {\\*\\picprop} is not picture data', () => {
    const [slot] = extractRtfPictures(rtfDocument(wordPicture(PNG_HEX)))

    // blipuid is "0000000000000000deadbeefdeadbeef": had it leaked, the bytes would not start with PNG.
    assert.equal(slot.bytes[0], 0x89)
    assert.equal(slot.bytes.length, PNG_HEX.length / 2)
})

test('RTF: a picture that exists but is not PNG/JPEG keeps its SLOT (so the order holds) and has no bytes', () => {
    const emfOnly = `{\\pict\\emfblip\\picw100\\pich100\r\n${wrapHex(EMF_HEX)}}`
    const wmf = `{\\pict\\wmetafile8\\picw100\\pich100\r\n${wrapHex(EMF_HEX)}}`
    const slots = extractRtfPictures(rtfDocument(emfOnly, wordPicture(PNG_HEX), wmf))

    assert.deepEqual(
        slots.map((slot) => slot.mime),
        [null, 'image/png', null],
    )
    assert.equal(slots[0].bytes, null)
})

test('RTF: a \\shppict EMF is unusable even when its \\nonshppict twin were a PNG (the fallback group is never read)', () => {
    const rtf = rtfDocument(`{\\*\\shppict{\\pict\\emfblip\r\n${EMF_HEX}}}{\\nonshppict{\\pict\\pngblip\r\n${PNG_HEX}}}`)
    const slots = extractRtfPictures(rtf)

    assert.equal(slots.length, 1)
    assert.equal(slots[0].mime, null)
})

test('RTF: \\shprslt, list pictures and paragraph-number pictures are not body pictures', () => {
    const rtf = rtfDocument(
        `{\\shp{\\*\\shpinst{\\sp{\\sn pib}{\\sv {\\pict\\pngblip\r\n${PNG_HEX}}}}}{\\shprslt{\\pict\\pngblip\r\n${PNG_HEX_B}}}}`,
        `{\\*\\listpicture{\\pict\\pngblip\r\n${PNG_HEX_B}}}`,
        `{\\*\\pnpict{\\pict\\pngblip\r\n${PNG_HEX_B}}}`,
    )
    const slots = extractRtfPictures(rtf)

    assert.equal(slots.length, 1, 'only the shape picture itself')
    assert.deepEqual(Array.from(slots[0].bytes), Array.from(hexToBytes(PNG_HEX)))
})

test('RTF: \\bin binary data and corrupt hex give an unusable slot, never an exception', () => {
    const binary = `{\\pict\\pngblip\\bin4 ABCD}`
    const corrupt = `{\\pict\\pngblip\r\n89504e47zz}`
    const odd = `{\\pict\\pngblip\r\n89504e4}`
    const slots = extractRtfPictures(rtfDocument(binary, corrupt, odd))

    assert.deepEqual(
        slots.map((slot) => slot.mime),
        [null, null, null],
    )
})

test('RTF: a wrong format word for the bytes is unusable (the bytes decide, not the label)', () => {
    const slots = extractRtfPictures(rtfDocument(`{\\pict\\jpegblip\r\n${PNG_HEX}}`))

    assert.equal(slots[0].mime, null)
})

test('RTF: escaped braces, backslashes and \\\'hh bytes in text do not confuse the scanner', () => {
    const rtf = String.raw`{\rtf1 a \{ not a group \} and \\ and \'e9 and \'7b{\pict\pngblip ` + PNG_HEX + String.raw`}}`
    const slots = extractRtfPictures(rtf)

    assert.equal(slots.length, 1)
    assert.equal(slots[0].mime, 'image/png')
})

test('RTF: nothing to find in empty, non-string or picture-free input', () => {
    assert.deepEqual(extractRtfPictures(''), [])
    assert.deepEqual(extractRtfPictures(null), [])
    assert.deepEqual(extractRtfPictures(rtfDocument('plain')), [])
    assert.deepEqual(extractRtfPictures('{\\rtf1 {\\pict\\pngblip 8950'), [], 'an unterminated group yields no slot')
})

test('RTF: a large picture is tokenised without trouble', () => {
    const big = '89504e47' + 'ab'.repeat(2_000_000)
    const [slot] = extractRtfPictures(rtfDocument(`{\\pict\\pngblip\r\n${wrapHex(big)}}`))

    assert.equal(slot.bytes.length, 2_000_004)
})

// --- matching ---------------------------------------------------------------

test('matchPictures: equal counts are matched by order', () => {
    const slots = extractRtfPictures(rtfDocument(wordPicture(PNG_HEX), wordPicture(PNG_HEX_B)))
    const matched = matchPictures(2, slots)

    assert.deepEqual(Array.from(matched[0].bytes), Array.from(hexToBytes(PNG_HEX)))
    assert.deepEqual(Array.from(matched[1].bytes), Array.from(hexToBytes(PNG_HEX_B)))
})

test('matchPictures: any count mismatch matches NOTHING', () => {
    const slots = extractRtfPictures(rtfDocument(wordPicture(PNG_HEX), wordPicture(PNG_HEX_B)))

    assert.deepEqual(matchPictures(3, slots), [null, null, null])
    assert.deepEqual(matchPictures(1, slots), [null])
    assert.deepEqual(matchPictures(2, []), [null, null])
})

test('matchPictures: an unusable slot leaves a hole but keeps its neighbours matched', () => {
    const slots = extractRtfPictures(rtfDocument(`{\\pict\\emfblip\r\n${EMF_HEX}}`, wordPicture(PNG_HEX_B)))
    const matched = matchPictures(2, slots)

    assert.equal(matched[0], null)
    assert.equal(matched[1].mime, 'image/png')
})

// --- hosts and images ----------------------------------------------------------

test('hostAllowed: wildcard = subdomains only; exact otherwise; case-insensitive', () => {
    const hosts = ['*.googleusercontent.com', 'img.example.com']

    assert.ok(hostAllowed('lh7-rt.googleusercontent.com', hosts))
    assert.ok(hostAllowed('A.B.GoogleUserContent.com', hosts))
    assert.ok(hostAllowed('img.example.com', hosts))
    assert.ok(!hostAllowed('googleusercontent.com', hosts), 'the bare domain is not a subdomain')
    assert.ok(!hostAllowed('evil-googleusercontent.com', hosts))
    assert.ok(!hostAllowed('googleusercontent.com.evil.test', hosts))
    assert.ok(!hostAllowed('example.com', hosts))
    assert.ok(!hostAllowed('x.com', ['*.com']), 'a wildcard over a bare TLD is refused')
    assert.ok(!hostAllowed('x.example.com', []))
})

test('findImages: file: images, and https images on an allowed host; nothing else', () => {
    const doc = parseHtml(
        '<p><img src="file:///C:/a.png"><img src="FILE:///C:/b.png">' +
            '<img src="https://lh7-rt.googleusercontent.com/docsz/a?key=1">' +
            '<img src="http://lh7-rt.googleusercontent.com/a"><img src="https://user:pw@lh7.googleusercontent.com/a">' +
            '<img src="https://other.test/a.png"><img src="/local.png"><img src="data:image/png;base64,AAAA"><img></p>',
    )
    const { files, remote } = findImages(doc, ['*.googleusercontent.com'])

    assert.equal(files.length, 2)
    assert.deepEqual(
        remote.map((entry) => entry.url),
        ['https://lh7-rt.googleusercontent.com/docsz/a?key=1'],
    )
})

test('settingsFromParams reads hosts, the action name and the message', () => {
    const params = new URLSearchParams('host=*.googleusercontent.com&host=&host=img.example.com&action=a&msg=Lost%3A%20%3Acount')

    assert.deepEqual(settingsFromParams(params), { hosts: ['*.googleusercontent.com', 'img.example.com'], action: 'a', message: 'Lost: :count' })
    assert.equal(settingsFromParams(new URLSearchParams()).action, 'asignuaFetchRemoteImage')
})

// --- limits -------------------------------------------------------------------------

test('fitsLimits mirrors Filament: accepted types are an allow-list, maxSize is in KB', () => {
    const png = bytesToDataUrl(hexToBytes(PNG_HEX), 'image/png')

    assert.ok(fitsLimits(png, {}))
    assert.ok(fitsLimits(png, { acceptedTypes: ['image/png'], maxSize: 1 }))
    assert.ok(!fitsLimits(png, { acceptedTypes: ['image/jpeg'] }))

    const bigBytes = new Uint8Array(3 * 1024)
    bigBytes.set([0x89, 0x50, 0x4e, 0x47])
    const big = bytesToDataUrl(bigBytes, 'image/png')

    assert.ok(!fitsLimits(big, { maxSize: 2 }))
    assert.ok(fitsLimits(big, { maxSize: 3 }))
    assert.ok(!fitsLimits('data:text/html;base64,PGI+', {}), 'only images')
    assert.ok(!fitsLimits('not a data url', {}))
})

test('bytesToDataUrl round-trips', () => {
    const bytes = hexToBytes(PNG_HEX)
    const url = bytesToDataUrl(bytes, 'image/png')

    assert.ok(url.startsWith('data:image/png;base64,'))
    assert.deepEqual(Array.from(Buffer.from(url.split(',')[1], 'base64')), Array.from(bytes))
})

// --- recoverImages -----------------------------------------------------------------------

test('Word: two file: images get the two pictures of the RTF, in order', async () => {
    const rtf = rtfDocument(wordPicture(PNG_HEX), wordPicture(PNG_HEX_B))
    const result = await recoverImages(word(2), { rtf, parseHtml })
    const sources = Array.from(parseHtml(result.html).querySelectorAll('img')).map((image) => image.getAttribute('src'))

    assert.equal(result.converted, 2)
    assert.equal(result.failed, 0)
    assert.equal(sources[0], bytesToDataUrl(hexToBytes(PNG_HEX), 'image/png'))
    assert.equal(sources[1], bytesToDataUrl(hexToBytes(PNG_HEX_B), 'image/png'))
    assert.ok(!result.html.includes('file:'))
})

test('Word: a count mismatch recovers nothing and removes every file: image', async () => {
    const rtf = rtfDocument(wordPicture(PNG_HEX))
    const result = await recoverImages(word(2), { rtf, parseHtml })

    assert.equal(result.converted, 0)
    assert.equal(result.failed, 2)
    assert.equal(parseHtml(result.html).querySelectorAll('img').length, 0)
})

test('Word: no RTF on the clipboard removes the images and says so', async () => {
    const result = await recoverImages(word(1), { parseHtml })

    assert.equal(result.converted, 0)
    assert.equal(result.failed, 1)
})

test('Word: a picture over the field limits is removed and counted, its neighbour survives', async () => {
    const bigHex = '89504e47' + '00'.repeat(4096)
    const rtf = rtfDocument(wordPicture(bigHex), wordPicture(PNG_HEX))
    const result = await recoverImages(word(2), { rtf, parseHtml, limits: { maxSize: 1, acceptedTypes: ['image/png'] } })

    assert.equal(result.converted, 1)
    assert.equal(result.failed, 1)
    assert.equal(parseHtml(result.html).querySelectorAll('img').length, 1)
})

test('Word: an unusable (EMF) picture in the middle does not shift the others', async () => {
    const rtf = rtfDocument(wordPicture(PNG_HEX), `{\\pict\\emfblip\r\n${EMF_HEX}}`, wordPicture(PNG_HEX_B))
    const result = await recoverImages(word(3), { rtf, parseHtml })
    const sources = Array.from(parseHtml(result.html).querySelectorAll('img')).map((image) => image.getAttribute('src'))

    assert.equal(result.converted, 2)
    assert.equal(result.failed, 1)
    assert.deepEqual(sources, [bytesToDataUrl(hexToBytes(PNG_HEX), 'image/png'), bytesToDataUrl(hexToBytes(PNG_HEX_B), 'image/png')])
})

test('Google Docs: allowed-host images are fetched (once per distinct URL) and replaced', async () => {
    const html =
        '<b id="docs-internal-guid-1"><img src="https://lh7-rt.googleusercontent.com/docsz/a"><img src="https://lh7-rt.googleusercontent.com/docsz/b"><img src="https://lh7-rt.googleusercontent.com/docsz/a"></b>'
    const calls = []
    const result = await recoverImages(html, {
        parseHtml,
        hosts: ['*.googleusercontent.com'],
        fetchRemote: async (url) => {
            calls.push(url)

            return url.endsWith('/a') ? bytesToDataUrl(hexToBytes(PNG_HEX), 'image/png') : bytesToDataUrl(hexToBytes(JPEG_HEX), 'image/jpeg')
        },
    })

    assert.deepEqual(calls, ['https://lh7-rt.googleusercontent.com/docsz/a', 'https://lh7-rt.googleusercontent.com/docsz/b'])
    assert.equal(result.converted, 3)
    assert.equal(result.failed, 0)
})

test('Google Docs: a fetch that throws, or answers with junk, removes that picture only', async () => {
    const html = '<p><img src="https://lh3.googleusercontent.com/a"><img src="https://lh3.googleusercontent.com/b"><img src="https://lh3.googleusercontent.com/c"></p>'
    const result = await recoverImages(html, {
        parseHtml,
        hosts: ['*.googleusercontent.com'],
        fetchRemote: async (url) => {
            if (url.endsWith('/a')) {
                throw new Error('refused')
            }

            return url.endsWith('/b') ? 'javascript:alert(1)' : bytesToDataUrl(hexToBytes(PNG_HEX), 'image/png')
        },
    })

    assert.equal(result.converted, 1)
    assert.equal(result.failed, 2)
})

test('recoverImages: nothing recoverable in the paste means null (the default handling stays in charge)', async () => {
    assert.equal(await recoverImages('<p>text <img src="https://other.test/a.png"></p>', { parseHtml, hosts: ['*.googleusercontent.com'] }), null)
    assert.equal(await recoverImages('<p>text only</p>', { parseHtml }), null)
})

// --- the paste handler -----------------------------------------------------------------------

const clipboardOf = (data, files = []) => ({
    types: Object.keys(data),
    files,
    getData: (type) => data[type] ?? (type === 'text' ? (data['text/plain'] ?? '') : ''),
})

const pasteEvent = (data, files) => {
    const event = new window.Event('paste', { bubbles: true, cancelable: true })

    Object.defineProperty(event, 'clipboardData', { value: clipboardOf(data, files) })

    return event
}

const setup = ({ localFiles = true, mount } = {}) => {
    const form = window.document.querySelector('form')
    const element = window.document.createElement('div')

    form.appendChild(element)

    const doc = {}
    const selection = { id: 'selection' }
    const dispatched = []
    const view = {
        dom: element,
        isDestroyed: false,
        state: { doc, selection, tr: { setSelection: (value) => ({ selectionSet: value }) } },
        dispatch: (transaction) => dispatched.push(transaction),
    }

    const events = []
    const received = []

    element.addEventListener('paste', (event) => received.push(event))

    for (const name of ['rich-editor-uploading-file', 'rich-editor-uploaded-file', 'rich-editor-file-validation-message']) {
        element.addEventListener(name, (event) => events.push([name, event.detail]))
    }

    const formEvents = []

    form.addEventListener('form-processing-started', () => formEvents.push('started'))
    form.addEventListener('form-processing-finished', () => formEvents.push('finished'))

    const calls = []
    const options = {
        key: 'data.body',
        acceptedTypes: ['image/png', 'image/jpeg'],
        maxSize: 5120,
        uploadingMessage: 'Uploading…',
        get$WireUsing: () => ({
            id: 'lw1',
            mountAction: async (...args) => {
                calls.push(args)

                return mount ? mount(...args) : { error: 'none' }
            },
        }),
    }

    const handler = createPasteHandler({
        hosts: ['*.googleusercontent.com'],
        action: 'asignuaFetchRemoteImage',
        message: 'Lost: :count',
        getLocalFiles: () => (localFiles ? { name: 'localFiles', options } : undefined),
        parseHtml,
    })

    return { view, handler, events, received, formEvents, calls, dispatched, doc, selection, element }
}

const settled = async (predicate) => {
    for (let attempt = 0; attempt < 200; attempt++) {
        if (predicate()) {
            return
        }

        await new Promise((resolve) => setTimeout(resolve, 5))
    }

    assert.fail('timed out')
}

test('handler: a Word paste is rewritten and re-dispatched as an ordinary paste of data: images', async () => {
    const context = setup()
    const event = pasteEvent({
        'text/plain': 'Before After',
        'text/html': word(1),
        'text/rtf': rtfDocument(wordPicture(PNG_HEX)),
    })

    assert.equal(context.handler(context.view, event), true, 'the default handling is stopped')
    assert.equal(context.received.length, 0, 'nothing is re-dispatched synchronously')

    await settled(() => context.received.length === 1)

    const rewritten = context.received[0]
    const html = rewritten.clipboardData.getData('text/html')

    assert.ok(html.includes(bytesToDataUrl(hexToBytes(PNG_HEX), 'image/png')))
    assert.ok(!html.includes('file:///'))
    assert.equal(rewritten.clipboardData.getData('text/plain'), 'Before After')
    assert.equal(rewritten.clipboardData.getData('text/rtf'), '', 'the RTF is not passed on')
    assert.equal(rewritten.bubbles, true)
    assert.deepEqual(context.dispatched, [{ selectionSet: context.selection }], 'the cursor is put back where the paste was made')
    assert.deepEqual(context.formEvents, ['started', 'finished'])
    assert.deepEqual(
        context.events.map(([name]) => name),
        ['rich-editor-uploading-file', 'rich-editor-uploaded-file'],
    )
    assert.equal(context.events[0][1].key, 'data.body')
    assert.equal(context.events[0][1].livewireId, 'lw1')
})

test('handler: the re-dispatched paste is left alone (no loop), even though it still has images', async () => {
    const context = setup()
    const first = pasteEvent({ 'text/html': word(1), 'text/rtf': rtfDocument(wordPicture(PNG_HEX)) })

    context.handler(context.view, first)
    await settled(() => context.received.length === 1)

    assert.equal(context.handler(context.view, context.received[0]), false)
})

test('handler: a failed picture is announced, after the upload that follows has finished', async () => {
    const context = setup()

    context.handler(context.view, pasteEvent({ 'text/plain': 'x', 'text/html': word(2), 'text/rtf': rtfDocument(wordPicture(PNG_HEX)) }))
    await settled(() => context.received.length === 1)

    // Count mismatch: nothing converted, so there is no upload to wait for and the message is immediate.
    const messages = context.events.filter(([name]) => name === 'rich-editor-file-validation-message')

    assert.equal(messages.length, 1)
    assert.equal(messages[0][1].validationMessage, 'Lost: 2')
    assert.ok(!context.received[0].clipboardData.getData('text/html').includes('<img'))
})

test('handler: when some pictures were converted, the message waits for Filament\'s uploaded event', async () => {
    const context = setup()
    const rtf = rtfDocument(wordPicture(PNG_HEX), `{\\pict\\emfblip\r\n${EMF_HEX}}`)

    context.handler(context.view, pasteEvent({ 'text/plain': 'x', 'text/html': word(2), 'text/rtf': rtf }))
    await settled(() => context.received.length === 1)

    const messages = () => context.events.filter(([name]) => name === 'rich-editor-file-validation-message')

    assert.equal(messages().length, 0, 'Filament is still uploading the converted picture')

    context.element.dispatchEvent(new window.CustomEvent('rich-editor-uploaded-file', { bubbles: true, detail: {} }))
    await settled(() => messages().length === 1)

    assert.equal(messages()[0][1].validationMessage, 'Lost: 1')
})

test('handler: a Google Docs picture is fetched through the editor action and substituted', async () => {
    const dataUrl = bytesToDataUrl(hexToBytes(PNG_HEX), 'image/png')
    const context = setup({ mount: async () => ({ dataUrl }) })

    context.handler(context.view, pasteEvent({ 'text/plain': 'x', 'text/html': '<b><img src="https://lh7-rt.googleusercontent.com/docsz/a"></b>' }))
    await settled(() => context.received.length === 1)

    assert.deepEqual(context.calls, [['asignuaFetchRemoteImage', { url: 'https://lh7-rt.googleusercontent.com/docsz/a' }, { schemaComponent: 'data.body' }]])
    assert.ok(context.received[0].clipboardData.getData('text/html').includes(dataUrl))
})

test('handler: an action that answers with an error costs that picture, not the paste', async () => {
    const context = setup({ mount: async () => ({ error: 'private' }) })

    context.handler(context.view, pasteEvent({ 'text/plain': 'keep me', 'text/html': '<p>keep me <img src="https://lh7.googleusercontent.com/a"></p>' }))
    await settled(() => context.received.length === 1)

    const html = context.received[0].clipboardData.getData('text/html')

    assert.ok(html.includes('keep me'))
    assert.ok(!html.includes('<img'))
})

test('handler: stays out of the way when there is nothing to recover', () => {
    const context = setup()
    const run = (data, files) => context.handler(context.view, pasteEvent(data, files))

    assert.equal(run({ 'text/html': '<p>just text</p>' }), false)
    assert.equal(run({ 'text/html': '<p><img src="https://other.test/a.png"></p>' }), false, 'a foreign host is not ours to fetch')
    assert.equal(run({ 'text/html': '<div data-pm-slice="1 1 []"><img src="file:///a.png"></div>' }), false, 'a paste from inside an editor')
    assert.equal(run({ 'text/plain': '', 'text/html': '<img src="file:///a.png">' }, [{ name: 'shot.png' }]), false, 'a pasted file with no text')
    assert.equal(run({}), false)
})

test('handler: a field without file attachments (no localFiles extension) is never touched', () => {
    const context = setup({ localFiles: false })

    assert.equal(context.handler(context.view, pasteEvent({ 'text/plain': 'x', 'text/html': word(1), 'text/rtf': rtfDocument(wordPicture(PNG_HEX)) })), false)
})

test('handler: a destroyed editor gets no late paste', async () => {
    const context = setup({ mount: async () => ({ error: 'x' }) })

    context.handler(context.view, pasteEvent({ 'text/plain': 'x', 'text/html': '<img src="https://lh7.googleusercontent.com/a">' }))
    context.view.isDestroyed = true
    await new Promise((resolve) => setTimeout(resolve, 30))

    assert.equal(context.received.length, 0)
})

test('handler: if the user has typed meanwhile, the cursor is not forced back', async () => {
    const context = setup()

    context.handler(context.view, pasteEvent({ 'text/plain': 'x', 'text/html': word(1), 'text/rtf': rtfDocument(wordPicture(PNG_HEX)) }))
    context.view.state = { ...context.view.state, doc: {} }
    await settled(() => context.received.length === 1)

    assert.deepEqual(context.dispatched, [])
})

// --- the module itself ----------------------------------------------------------------------------

test('the module imports under Node without window (window/import.meta are read inside the factory)', () => {
    assert.equal(typeof factory, 'function')
})

test('the factory builds an extension of priority 1000 whose plugin wires the handler', () => {
    const created = []
    const previousWindow = globalThis.window

    globalThis.window = {
        FilamentRichEditor: {
            tiptap: {
                core: { Extension: { create: (config) => created.push(config) && config } },
                pmState: {
                    Plugin: class {
                        constructor(spec) {
                            this.spec = spec
                        }
                    },
                    PluginKey: class {},
                },
            },
        },
    }

    try {
        const extension = factory()
        const [plugin] = extension.addProseMirrorPlugins.call({ editor: { extensionManager: { extensions: [] } } })

        assert.equal(extension.name, 'asignuaPasteImages')
        assert.equal(extension.priority, 1000)
        assert.equal(typeof plugin.spec.props.handlePaste, 'function')
        assert.equal(plugin.spec.props.handlePaste({ dom: {} }, { clipboardData: clipboardOf({ 'text/html': '<img src="file:///a.png">' }) }), false, 'no localFiles: untouched')
    } finally {
        globalThis.window = previousWindow
    }
})
