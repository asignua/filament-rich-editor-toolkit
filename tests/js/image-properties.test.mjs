// Suite for resources/js/src/image-properties.js on a REAL prosemirror-model schema. TipTap's own
// plumbing (the way it hands each attribute parser the matched element and merges the rule's
// attrs) is reproduced in `rulesFor()`; the node is built from the module's own parse rules,
// attribute parsers and renderSpec.

import { test } from 'node:test'
import assert from 'node:assert/strict'
import { JSDOM } from 'jsdom'
import { DOMParser as PmDOMParser, DOMSerializer, Schema } from 'prosemirror-model'

import factory, {
    ALIGNMENTS,
    ALIGN_CLASS_PREFIX,
    CAPTION_CLASS,
    FIGURE_CLASS,
    adopt,
    alignOf,
    buildDecorations,
    cleanAlign,
    cleanCaption,
    decorationAttrs,
    nodeAttributes,
    parseRules,
    renderSpec,
} from '../../resources/js/src/image-properties.js'
import { materiallyDiffers } from '../../resources/js/src/source-html.js'
import { buildAttributes } from '../../resources/js/src/custom-attributes.js'

const dom = new JSDOM('')
const window = dom.window

// What TipTap's Image and Filament's extension declare, parse side. `alt` has no special handling:
// TipTap reads getAttribute('alt'), so an empty alt stays '' and a missing one is null.
const stockAttributes = {
    src: { parseHTML: (el) => el.getAttribute('src') },
    alt: { parseHTML: (el) => el.getAttribute('alt') },
    title: { parseHTML: (el) => el.getAttribute('title') },
    id: { parseHTML: (el) => el.getAttribute('data-id') },
    ...buildAttributes(['class', 'style']),
    ...nodeAttributes(),
}

const rendered = (attrs) => ({
    ...(attrs.src ? { src: attrs.src } : {}),
    ...(attrs.alt !== null && attrs.alt !== undefined ? { alt: attrs.alt } : {}),
    ...(attrs.id ? { 'data-id': attrs.id } : {}),
    ...(attrs.class ? { class: attrs.class } : {}),
})

// TipTap's injectExtensionAttributesToParseRule: the rule's own attrs, then every attribute parser
// applied to the matched element (a non-null value wins).
const rulesFor = () =>
    parseRules().map((rule) => ({
        ...rule,
        getAttrs: (element) => {
            const own = rule.getAttrs ? rule.getAttrs(element) : {}

            if (own === false) {
                return false
            }

            const parsed = {}

            for (const [name, definition] of Object.entries(stockAttributes)) {
                const value = definition.parseHTML(element)

                if (value !== null && value !== undefined) {
                    parsed[name] = value
                }
            }

            return { ...own, ...parsed }
        },
    }))

const attrSpec = Object.fromEntries(Object.keys(stockAttributes).map((name) => [name, { default: null }]))

const schema = new Schema({
    nodes: {
        doc: { content: 'block+' },
        paragraph: { group: 'block', content: 'inline*', parseDOM: [{ tag: 'p' }], toDOM: () => ['p', 0] },
        text: { group: 'inline' },
        image: {
            inline: true,
            group: 'inline',
            draggable: true,
            attrs: attrSpec,
            parseDOM: rulesFor(),
            toDOM: (node) => renderSpec(node.attrs, rendered(node.attrs)),
        },
    },
    // A stand-in for the span mark of CustomAttributesPlugin (priority 50): it takes ANY span.
    marks: {
        customSpan: { attrs: { class: { default: null } }, parseDOM: [{ tag: 'span', priority: 50 }], toDOM: () => ['span', 0] },
    },
})

const parse = (html) => {
    const body = new window.DOMParser().parseFromString(html, 'text/html').body

    return PmDOMParser.fromSchema(schema).parse(body)
}

const serialize = (doc) => {
    const container = window.document.createElement('div')

    container.appendChild(DOMSerializer.fromSchema(schema).serializeFragment(doc.content, { document: window.document }))

    return container.innerHTML
}

const images = (doc) => {
    const found = []

    doc.descendants((node) => {
        if (node.type.name === 'image') {
            found.push(node)
        }
    })

    return found
}

test('the module imports under Node without window', () => {
    assert.equal(typeof factory, 'function')
})

test('constants agree with the PHP node', () => {
    assert.deepEqual(ALIGNMENTS, ['left', 'center', 'right'])
    assert.equal(FIGURE_CLASS, 'asignua-rte-figure')
    assert.equal(CAPTION_CLASS, 'asignua-rte-caption')
    assert.equal(ALIGN_CLASS_PREFIX, 'asignua-rte-align-')
})

test('a plain image is a bare <img> both ways (no markup churn)', () => {
    const source = '<p><img src="https://cdn.test/a.png" alt="A cat" class="rounded"></p>'
    const doc = parse(source)
    const [image] = images(doc)

    assert.equal(image.attrs.caption, null)
    assert.equal(image.attrs.align, null)
    assert.equal(serialize(doc), source)
})

test('a missing alt stays missing and an empty alt (decorative) is preserved', () => {
    const [missing] = images(parse('<p><img src="a.png"></p>'))
    const [decorative] = images(parse('<p><img src="a.png" alt=""></p>'))

    assert.equal(missing.attrs.alt, null)
    assert.equal(decorative.attrs.alt, '')

    assert.equal(serialize(parse('<p><img src="a.png"></p>')), '<p><img src="a.png"></p>')
    assert.equal(serialize(parse('<p><img src="a.png" alt=""></p>')), '<p><img src="a.png" alt=""></p>')
})

test('the span form round-trips: caption, alignment, the img attributes and data-id', () => {
    const source =
        '<p><span class="asignua-rte-figure asignua-rte-align-center"><img src="https://cdn.test/a.png" alt="A cat" data-id="k1" class="rounded"><span class="asignua-rte-caption">Tom, 2024</span></span></p>'
    const doc = parse(source)
    const [image] = images(doc)

    assert.equal(images(doc).length, 1)
    assert.equal(image.attrs.caption, 'Tom, 2024')
    assert.equal(image.attrs.align, 'center')
    assert.equal(image.attrs.alt, 'A cat')
    assert.equal(image.attrs.id, 'k1')
    assert.equal(image.attrs.class, 'rounded', 'the author class sits on the image, not the wrapper')
    assert.equal(doc.textContent, '', 'the caption is an attribute, not paragraph text')
    assert.equal(serialize(doc), source.replace(' data-id="k1"', ' data-id="k1"'))
})

test('the wrapper span is not swallowed by the span mark', () => {
    const doc = parse('<p><span class="asignua-rte-figure"><img src="a.png" alt="x"><span class="asignua-rte-caption">C</span></span></p>')
    let marks = 0

    doc.descendants((node) => {
        marks += node.marks.length
    })

    assert.equal(marks, 0)
    assert.equal(images(doc)[0].attrs.caption, 'C')
})

test('a <figure> typed in the source view is read and written back as the span', () => {
    const doc = parse(
        '<figure class="wp-block asignua-rte-align-right" style="margin:0" data-x="1"><img src="a.png" alt="" class="photo"><figcaption>  Two   <b>lines</b>\n of text </figcaption></figure>',
    )
    const [image] = images(doc)

    assert.equal(image.attrs.align, 'right')
    assert.equal(image.attrs.caption, 'Two lines of text')
    assert.equal(image.attrs.alt, '')
    assert.equal(image.attrs.class, 'photo')
    assert.equal(image.attrs.style, null, 'the wrapper style is not the image style')

    assert.equal(
        serialize(doc),
        '<p><span class="asignua-rte-figure asignua-rte-align-right"><img src="a.png" alt="" class="photo"><span class="asignua-rte-caption">Two lines of text</span></span></p>',
    )
})

test('alignment is read from a class or from data-align, and only the three values count', () => {
    assert.equal(images(parse('<figure class="asignua-rte-align-left"><img src="a.png"></figure>'))[0].attrs.align, 'left')
    assert.equal(images(parse('<figure data-align="center"><img src="a.png"></figure>'))[0].attrs.align, 'center')
    assert.equal(images(parse('<p><img src="a.png" data-align="right"></p>'))[0].attrs.align, 'right')
    assert.equal(images(parse('<figure class="asignua-rte-align-evil" data-align="diagonal"><img src="a.png"></figure>'))[0].attrs.align, null)
    assert.equal(images(parse('<figure class="alignleft"><img src="a.png"></figure>'))[0].attrs.align, null)
    assert.equal(cleanAlign('center" onclick="x'), null)
    assert.equal(cleanAlign('LEFT'), null)
    assert.equal(alignOf(window.document.createElement('span')), null)
})

test('a figure with alignment but no caption keeps the alignment only', () => {
    const doc = parse('<figure class="asignua-rte-align-center"><img src="a.png" alt="A"></figure>')

    assert.equal(
        serialize(doc),
        '<p><span class="asignua-rte-figure asignua-rte-align-center"><img src="a.png" alt="A"></span></p>',
    )
})

test('a caption is plain text: markup is flattened on parse and escaped on render', () => {
    const doc = parse('<figure><img src="a.png"><figcaption><script>alert(1)</script><a href="x">link</a> &amp; &lt;b&gt;</figcaption></figure>')
    const [image] = images(doc)

    assert.ok(!image.attrs.caption.includes('<a'))
    assert.ok(image.attrs.caption.includes('& <b>'))

    const out = serialize(doc)

    assert.ok(!out.includes('<script'))
    assert.ok(!out.includes('<b>'))
    assert.ok(out.includes('&amp; &lt;b&gt;'))

    const node = schema.nodes.image.create({ src: 'a.png', caption: '<img src=x onerror=alert(1)>', align: 'center' })
    const html = serialize(schema.node('doc', null, [schema.node('paragraph', null, [node])]))

    assert.ok(!html.includes('<img src=x'))
    assert.ok(html.includes('&lt;img src=x onerror=alert(1)&gt;'))
})

test('a wrapper that is not one image is left alone', () => {
    const wrap = (inner) => window.document.createRange().createContextualFragment(inner).firstElementChild

    assert.equal(adopt(wrap('<figure><img src="a.png"><img src="b.png"></figure>')), false)
    assert.equal(adopt(wrap('<figure><blockquote>Q</blockquote><figcaption>Who</figcaption></figure>')), false)
    assert.equal(adopt(wrap('<figure><img src="a.png"><p>text</p></figure>')), false)
    assert.equal(adopt(wrap('<figure><img src="a.png"><figcaption>One</figcaption><figcaption>Two</figcaption></figure>')), false)
    assert.equal(adopt(wrap('<figure><img alt="no src"></figure>')), false)
    assert.equal(adopt(wrap('<figure>just text</figure>')), false)

    const kept = parse('<figure><blockquote><p>Q</p></blockquote><figcaption>Who</figcaption></figure>')
    assert.equal(images(kept).length, 0)
})

test('adopt is idempotent and strips the wrapper down to the image', () => {
    const wrapper = window.document.createRange().createContextualFragment(
        '<figure class="asignua-rte-align-left" style="x:y"><img src="a.png" alt="A" class="p"><figcaption>C</figcaption></figure>',
    ).firstElementChild

    assert.equal(adopt(wrapper), true)
    assert.equal(adopt(wrapper), true)
    assert.equal(wrapper.childNodes.length, 0)
    assert.equal(wrapper.getAttribute('src'), 'a.png')
    assert.equal(wrapper.getAttribute('class'), 'p')
    assert.equal(wrapper.hasAttribute('style'), false)
    assert.equal(wrapper.getAttribute('data-asignua-align'), 'left')
    assert.equal(wrapper.getAttribute('data-asignua-caption'), 'C')
})

test('renderSpec: bare <img> without caption and alignment, wrapper otherwise', () => {
    assert.deepEqual(renderSpec({ caption: null, align: null }, { src: 'a' }), ['img', { src: 'a' }])
    assert.deepEqual(renderSpec({ caption: '   ', align: 'sideways' }, { src: 'a' }), ['img', { src: 'a' }])
    assert.deepEqual(renderSpec({ caption: null, align: 'left' }, { src: 'a' }), [
        'span',
        { class: 'asignua-rte-figure asignua-rte-align-left' },
        ['img', { src: 'a' }],
    ])
    assert.deepEqual(renderSpec({ caption: 'C', align: null }, { src: 'a' }), [
        'span',
        { class: 'asignua-rte-figure' },
        ['img', { src: 'a' }],
        ['span', { class: 'asignua-rte-caption' }, 'C'],
    ])
})

test('cleanCaption collapses whitespace and refuses non-strings', () => {
    assert.equal(cleanCaption('  a \n b  '), 'a b')
    assert.equal(cleanCaption('   '), null)
    assert.equal(cleanCaption(null), null)
    assert.equal(cleanCaption(5), null)
})

test('decorations mark exactly the images that have a caption or an alignment', () => {
    const calls = []
    const Decoration = { node: (from, to, attrs) => ({ from, to, attrs }) }
    const DecorationSet = { create: (doc, list) => ((calls.length = 0), calls.push(...list), list) }

    const doc = parse(
        '<p><img src="a.png"> text <img src="b.png" data-align="center"></p><p><span class="asignua-rte-figure"><img src="c.png"><span class="asignua-rte-caption">C</span></span></p>',
    )

    buildDecorations(doc, { Decoration, DecorationSet })

    assert.equal(calls.length, 2)
    assert.deepEqual(calls[0].attrs, { class: 'asignua-rte-figure asignua-rte-align-center' })
    assert.deepEqual(calls[1].attrs, { class: 'asignua-rte-figure', 'data-asignua-caption': 'C' })
    assert.equal(decorationAttrs({ caption: null, align: null }), null)
})

test('the factory returns a WRAPPER extension holding a lower-priority inline image shadow with the two new attributes', () => {
    const make = (kind) => ({ create: (config) => ({ kind, ...config }) })

    globalThis.window = {
        FilamentRichEditor: {
            tiptap: {
                core: { Extension: make('extension'), Node: make('node'), mergeAttributes: (...a) => Object.assign({}, ...a) },
                pmState: { Plugin: class {}, PluginKey: class {} },
                pmView: { Decoration: {}, DecorationSet: {} },
            },
        },
    }

    try {
        const wrapper = factory()

        // Filament 5.9 REPLACES a built-in extension that a custom module returns under the same
        // name; only extensions nested in addExtensions() are appended (and merged by TipTap).
        assert.equal(wrapper.kind, 'extension')
        assert.notEqual(wrapper.name, 'image')

        const [node] = wrapper.addExtensions()

        assert.equal(node.name, 'image')
        assert.equal(node.priority, 50)
        assert.equal(node.inline, true)
        assert.equal(node.group, 'inline')
        assert.deepEqual(Object.keys(node.addAttributes()), ['caption', 'align'])
        assert.equal(node.addAttributes().caption.rendered, false)
        assert.equal(node.addAttributes().align.rendered, false)
        assert.equal(node.addProseMirrorPlugins().length, 1)
    } finally {
        delete globalThis.window
    }
})

test('source view: a typed <figure> that the editor rewrites to the span is not reported as lost markup', () => {
    const parseHtml = (html) => new window.DOMParser().parseFromString(html, 'text/html')
    const differs = (typed, normalized) => materiallyDiffers(typed, normalized, parseHtml)

    const figure =
        '<figure class="asignua-rte-align-center"><img src="a.png" alt="A"><figcaption>Tom  2024</figcaption></figure>'
    const span =
        '<p><span class="asignua-rte-figure asignua-rte-align-center"><img src="a.png" alt="A"><span class="asignua-rte-caption">Tom 2024</span></span></p>'

    assert.equal(differs(figure, span), false)
    assert.equal(differs(span, span), false)

    // The alignment or the caption really changed.
    assert.equal(differs(figure, span.replace('align-center', 'align-left')), true)
    assert.equal(differs(figure, span.replace('Tom 2024', 'Other')), true)
    assert.equal(differs(figure, '<p><img src="a.png" alt="A"></p>'), true)

    // What the editor drops still counts: a wrapper class or style, markup in the caption.
    assert.equal(differs('<figure class="wide" style="margin:0"><img src="a.png"></figure>', '<p><img src="a.png"></p>'), true)
    assert.equal(differs('<figure><img src="a.png"><figcaption>A <b>bold</b> word</figcaption></figure>', '<p><span class="asignua-rte-figure"><img src="a.png"><span class="asignua-rte-caption">A bold word</span></span></p>'), true)
})
