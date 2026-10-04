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
import embedFactory, { isAllowedSrc } from '../../resources/js/src/embed.js'
import { readList } from '../../resources/js/src/config.js'

const dom = new JSDOM('')
const html = (source) => new dom.window.DOMParser().parseFromString(source, 'text/html').body

test('modules import under Node without window', () => {
    assert.equal(typeof factory, 'function')
    assert.equal(typeof embedFactory, 'function')
})

test('filterClass drops the classes owned by other extensions', () => {
    assert.equal(filterClass('color lead my-class  other'), 'my-class other')
    assert.equal(filterClass('color'), null)
    assert.equal(filterClass(''), null)
    assert.equal(filterClass(null), null)
})

test('filterStyle drops owned declarations and keeps the rest', () => {
    assert.equal(filterStyle('text-align: center; margin: 0; --cols: 3'), 'margin: 0')
    assert.equal(filterStyle('WIDTH: 10px'), null)
    assert.equal(filterStyle('color: red;'), 'color: red')
    assert.equal(filterStyle(''), null)
})

test('blankToNull trims', () => {
    assert.equal(blankToNull('  x '), 'x')
    assert.equal(blankToNull('   '), null)
})

test('buildAttributes parses and renders every configured name', () => {
    const attributes = buildAttributes(['class', 'data-track'])
    const element = html('<p class="a color" data-track=" go ">x</p>').firstChild

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
        assert.deepEqual(node.renderHTML({ HTMLAttributes: { src: 'https://a.test', title: '', width: null } }), [
            'iframe',
            { src: 'https://a.test' },
        ])
    } finally {
        delete globalThis.window
    }
})
