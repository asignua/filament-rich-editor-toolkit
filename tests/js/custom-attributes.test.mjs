// Suite for the pure parts of resources/js/src/custom-attributes.js and embed.js.
// The TipTap factories need Filament's `window.FilamentRichEditor`; they are exercised with a
// minimal fake of the core so we can assert what the extension DECLARES (types, attributes,
// shadowed listItem content) without a browser.

import { test } from 'node:test'
import assert from 'node:assert/strict'
import { JSDOM } from 'jsdom'

import factory, {
    DEFAULT_TYPES,
    blankToNull,
    buildAttributes,
    filterClass,
    filterStyle,
    hasBlockChild,
} from '../../resources/js/src/custom-attributes.js'
import embedFactory, { canonicalSrc, isAllowedSrc, parseVideo, renderIframe } from '../../resources/js/src/embed.js'
import { readList } from '../../resources/js/src/config.js'

const dom = new JSDOM('')
const html = (source) => new dom.window.DOMParser().parseFromString(source, 'text/html').body

test('modules import under Node without window', () => {
    assert.equal(typeof factory, 'function')
    assert.equal(typeof embedFactory, 'function')
})

test('filterClass drops a class only on the tag whose extension owns it', () => {
    assert.equal(filterClass('color lead my-class  other', 'span'), 'lead my-class other')
    assert.equal(filterClass('color lead grid-layout x', 'div'), 'color x')
    assert.equal(filterClass('lead', 'p'), 'lead', 'Bootstrap p.lead keeps its class')
    assert.equal(filterClass('color', 'span'), null)
    assert.equal(filterClass('', 'span'), null)
    assert.equal(filterClass(null, 'span'), null)
})

test('filterStyle drops owned declarations only on the owning tag', () => {
    assert.equal(filterStyle('text-align: center; margin: 0; --cols: 3', 'p'), 'margin: 0; --cols: 3')
    assert.equal(filterStyle('text-align: center; margin: 0; --cols: 3', 'div'), 'text-align: center; margin: 0')
    assert.equal(filterStyle('WIDTH: 10px', 'img'), null)
    assert.equal(filterStyle('width: 30%; text-align: center', 'td'), 'width: 30%; text-align: center')
    assert.equal(filterStyle('width:100%;height:400px', 'iframe'), 'width:100%; height:400px')
    assert.equal(filterStyle('color: red;', 'p'), 'color: red')
    assert.equal(filterStyle('', 'p'), null)
})

test('buildAttributes reads the tag of the element it parses', () => {
    const attributes = buildAttributes(['class', 'style'])
    const td = html('<table><tbody><tr><td class="lead" style="text-align:center">x</td></tr></tbody></table>').querySelector('td')
    const p = html('<p class="lead" style="text-align:center">x</p>').firstChild

    assert.equal(attributes.style.parseHTML(td), 'text-align:center')
    assert.equal(attributes.style.parseHTML(p), null)
    assert.equal(attributes.class.parseHTML(td), 'lead')
})

test('blankToNull trims', () => {
    assert.equal(blankToNull('  x '), 'x')
    assert.equal(blankToNull('   '), null)
})

test('buildAttributes parses and renders every configured name', () => {
    const attributes = buildAttributes(['class', 'data-track'])
    const element = html('<span class="a color" data-track=" go ">x</span>').firstChild

    assert.equal(attributes.class.parseHTML(element), 'a')
    assert.equal(attributes['data-track'].parseHTML(element), 'go')
    assert.deepEqual(attributes['data-track'].renderHTML({ 'data-track': 'go' }), { 'data-track': 'go' })
    assert.deepEqual(attributes['data-track'].renderHTML({ 'data-track': null }), {})
})

test('hasBlockChild tells the two div kinds apart', () => {
    assert.equal(hasBlockChild(html('<div><p>x</p></div>').firstChild), true)
    assert.equal(hasBlockChild(html('<div><img src="a"> <span>x</span></div>').firstChild), false)
})

test('readList reads repeated query parameters and survives garbage', () => {
    assert.deepEqual(readList('https://x.test/a.js?v=1&attr=a&attr=b&attr=', 'attr'), ['a', 'b'])
    assert.deepEqual(readList(undefined, 'attr'), [])
    assert.deepEqual(readList('not a url', 'attr'), [])
})

const fakeCore = () => {
    const make = (kind) => ({ create: (config) => ({ kind, ...config }) })

    return { Extension: make('extension'), Mark: make('mark'), Node: make('node'), mergeAttributes: (...a) => Object.assign({}, ...a) }
}

test('the factory declares the global attributes and the structural nodes', () => {
    globalThis.window = { FilamentRichEditor: { tiptap: { core: fakeCore() } } }

    try {
        const extension = factory()
        const [global] = extension.addGlobalAttributes()

        assert.deepEqual(Object.keys(global.attributes), ['class', 'id', 'style'])
        assert.deepEqual(global.types, DEFAULT_TYPES)

        const names = extension.addExtensions().map((e) => e.name)
        assert.deepEqual(names, ['customSpan', 'customDiv', 'customDivInline', 'listItem'])

        const listItem = extension.addExtensions().find((e) => e.name === 'listItem')
        assert.equal(listItem.priority, 50)
        assert.equal(listItem.content, '(paragraph|customDiv|customDivInline) block*')
    } finally {
        delete globalThis.window
    }
})

test('isAllowedSrc: https only, exact host, optional path prefix', () => {
    const hosts = ['www.youtube-nocookie.com/embed/', 'player.vimeo.com', 'maps.example.test/embed']

    assert.equal(isAllowedSrc('https://www.youtube-nocookie.com/embed/abc', hosts), true)
    assert.equal(isAllowedSrc('https://www.youtube-nocookie.com/watch?v=abc', hosts), false)
    assert.equal(isAllowedSrc('http://player.vimeo.com/video/1', hosts), false)
    assert.equal(isAllowedSrc('https://player.vimeo.com.evil.test/video/1', hosts), false)
    assert.equal(isAllowedSrc('https://evil.test@player.vimeo.com/video/1', hosts), false)
    assert.equal(isAllowedSrc('//player.vimeo.com/video/1', hosts), false)
    assert.equal(isAllowedSrc('javascript:alert(1)', hosts), false)
    assert.equal(isAllowedSrc('', hosts), false)
    assert.equal(isAllowedSrc('https://player.vimeo.com/video/1', []), false)
})

test('isAllowedSrc: the entry host is case-insensitive, its path is not (PHP parity)', () => {
    const hosts = ['Docs.Google.com/forms/d/e/1FAIpQLSfX/viewform']

    assert.equal(isAllowedSrc('https://docs.google.com/forms/d/e/1FAIpQLSfX/viewform?embedded=true', hosts), true)
    assert.equal(isAllowedSrc('https://docs.google.com/forms/d/e/1faipqlsfx/viewform', hosts), false)
})

test('isAllowedSrc: refuses traversal and matches the prefix on a segment boundary (PHP parity)', () => {
    const hosts = ['www.google.com/maps/embed']

    assert.equal(isAllowedSrc('https://www.google.com/maps/embed?pb=1', hosts), true)
    assert.equal(isAllowedSrc('https://www.google.com/maps/embed/v1/place', hosts), true)
    assert.equal(isAllowedSrc('HTTPS://www.google.com/maps/embed', hosts), true)
    assert.equal(isAllowedSrc('https://www.google.com/maps/embedded', hosts), false)
    assert.equal(isAllowedSrc('https://www.google.com/maps/embed/../../url?q=https://evil.test', hosts), false)
    assert.equal(isAllowedSrc('https://www.google.com/maps/embed/%2e%2e/%2E%2E/url', hosts), false)
    assert.equal(isAllowedSrc('https://www.google.com/maps/embed/..%2f..%2furl', hosts), false)
    assert.equal(isAllowedSrc('https://www.google.com/maps/embed/./x', hosts), false)
    assert.equal(isAllowedSrc('https://www.google.com/maps/embed\\..\\url', hosts), false)
})

test('the embed node rejects an iframe whose src is not allow-listed', () => {
    globalThis.window = { FilamentRichEditor: { tiptap: { core: { Node: { create: (c) => c } } } } }

    try {
        const node = embedFactory()
        const [rule] = node.parseHTML()

        // No `host` parameter in the (Node) module URL -> nothing is allowed.
        assert.equal(rule.getAttrs(html('<iframe src="https://player.vimeo.com/video/1"></iframe>').firstChild), false)
        // And nothing is rendered for it either: a node built from JSON is checked again.
        assert.deepEqual(node.renderHTML({ HTMLAttributes: { src: 'https://a.test' } }), [
            'div',
            { 'data-asignua-embed-blocked': '' },
        ])
    } finally {
        delete globalThis.window
    }
})

test('hasBlockChild treats an iframe as a block child (a responsive embed wrapper)', () => {
    assert.equal(hasBlockChild(html('<div class="wrap"><iframe src="https://a.test"></iframe></div>').firstChild), true)
})

test('renderIframe: a node built from JSON cannot show a javascript: or a foreign src', () => {
    const hosts = ['www.youtube-nocookie.com/embed/']
    const blocked = ['div', { 'data-asignua-embed-blocked': '' }]

    assert.deepEqual(renderIframe({ src: 'javascript:fetch(`/evil?c=`+document.cookie)' }, hosts), blocked)
    assert.deepEqual(renderIframe({ src: 'https://attacker.test/page' }, hosts), blocked)
    assert.deepEqual(renderIframe({ src: null }, hosts), blocked)
    assert.deepEqual(renderIframe({}, hosts), blocked)
})

test('renderIframe forces the configured hardening over the node attributes', () => {
    const [tag, attributes] = renderIframe(
        { src: 'https://www.youtube-nocookie.com/embed/abcdef', sandbox: 'allow-top-navigation', title: '', width: '560' },
        ['www.youtube-nocookie.com/embed/'],
        { sandbox: 'allow-scripts allow-presentation', referrerpolicy: 'no-referrer' },
    )

    assert.equal(tag, 'iframe')
    assert.deepEqual(attributes, {
        src: 'https://www.youtube-nocookie.com/embed/abcdef',
        sandbox: 'allow-scripts allow-presentation',
        width: '560',
        referrerpolicy: 'no-referrer',
    })
})

test('the embed factory reads the forced hardening from its module URL', () => {
    globalThis.window = { FilamentRichEditor: { tiptap: { core: { Node: { create: (c) => c } } } } }

    try {
        // import.meta.url is a plain file URL under Node: nothing forced, nothing allowed.
        const node = embedFactory()

        assert.deepEqual(node.renderHTML({ HTMLAttributes: { src: 'https://x.test' } })[0], 'div')
        assert.equal(node.addAttributes().src.parseHTML(html('<iframe src="https://evil.test"></iframe>').firstChild), 'https://evil.test')
    } finally {
        delete globalThis.window
    }
})

test('parseVideo and canonicalSrc rebuild YouTube and Vimeo links like the PHP VideoEmbed', () => {
    const hosts = ['www.youtube-nocookie.com/embed/', 'player.vimeo.com/video/']
    const cases = [
        ['https://www.youtube.com/embed/dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?rel=0'],
        ['https://youtube.com/embed/dQw4w9WgXcQ?start=30', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?rel=0&start=30'],
        ['https://youtu.be/dQw4w9WgXcQ?t=1h2m3s', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?rel=0&start=3723'],
        ['https://www.youtube.com/watch?feature=share&v=dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?rel=0'],
        ['https://vimeo.com/123456789/abcdef1234', 'https://player.vimeo.com/video/123456789?h=abcdef1234'],
        // Already built-in: untouched.
        ['https://player.vimeo.com/video/123456789?h=abcdef1234&dnt=1', 'https://player.vimeo.com/video/123456789?h=abcdef1234&dnt=1'],
        // Not recognised: returned as is (and then refused by isAllowedSrc).
        ['https://evil.test/https://youtube.com/embed/dQw4w9WgXcQ', 'https://evil.test/https://youtube.com/embed/dQw4w9WgXcQ'],
        ['javascript:alert(1)', 'javascript:alert(1)'],
    ]

    for (const [input, expected] of cases) {
        assert.equal(canonicalSrc(input, hosts), expected, input)
    }

    assert.equal(isAllowedSrc(canonicalSrc('https://www.youtube.com/embed/dQw4w9WgXcQ', hosts), hosts), true)
    assert.equal(parseVideo('https://youtu.be/dQw4w9WgXcQdQw4w9WgXcQ'), null)
})

test('a playlist or channel embed is not a video id', () => {
    assert.equal(parseVideo('https://www.youtube.com/embed/videoseries?list=PLabcdefghij'), null)
    assert.equal(parseVideo('https://www.youtube.com/embed/live_stream?channel=UCabc'), null)
})

test('the span mark skips node spans (mention, merge tag) and span.color', () => {
    globalThis.window = { FilamentRichEditor: { tiptap: { core: fakeCore() } } }

    try {
        const span = factory().addExtensions().find((e) => e.name === 'customSpan')
        const [rule] = span.parseHTML()
        const first = (source) => html(source).querySelector('span')

        assert.equal(rule.getAttrs(first('<p><span data-type="mergeTag" data-id="name">Name</span></p>')), false)
        assert.equal(rule.getAttrs(first('<p><span data-type="mention" data-id="1">Ann</span></p>')), false)
        assert.equal(rule.getAttrs(first('<p><span class="color">x</span></p>')), false)
        assert.deepEqual(rule.getAttrs(first('<p><span class="hook">x</span></p>')), {})
    } finally {
        delete globalThis.window
    }
})
