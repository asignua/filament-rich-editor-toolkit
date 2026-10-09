// Suite for strict paste (resources/js/src/paste-strict.js) and its wiring in paste-clean.js.

import { test } from 'node:test'
import assert from 'node:assert/strict'
import { JSDOM } from 'jsdom'

import { allowedFromToolbar, restrictToToolbar } from '../../resources/js/src/paste-strict.js'

const dom = new JSDOM('')
const parseHtml = (html) => new dom.window.DOMParser().parseFromString(html, 'text/html')
const restrict = (html, keys) => restrictToToolbar(html, new Set(keys), { parseHtml })
const countOf = (html, selector) => parseHtml(html).body.querySelectorAll(selector).length
const textOf = (html) => parseHtml(html).body.textContent

const ALL_MARKS = ['bold', 'italic', 'underline', 'strike', 'subscript', 'superscript', 'code', 'highlight', 'link']

// --- reading the toolbar ---------------------------------------------------

// Shapes as Filament renders them (see the PHP test that pins the real markup): Js::from() turns
// the options array into JSON.parse('{"level":2}') and an empty one into [].
const button = (isActive) =>
    `<button type="button" x-bind:class="{ 'fi-active': editorUpdatedAt &amp;&amp; $getEditor()?.isActive(${isActive}) }"></button>`

const field = (...buttons) => {
    const doc = parseHtml(
        `<div class="fi-fo-rich-editor"><div class="fi-fo-rich-editor-toolbar"><div>${buttons.join('')}</div></div></div>`,
    )

    return doc.querySelector('.fi-fo-rich-editor')
}

test('the allowed set comes from the buttons: marks, lists, table and heading levels', () => {
    const root = field(
        button("'bold', []"),
        button("'link', []"),
        button("'heading', JSON.parse('{\\u0022level\\u0022:2}')"),
        button("'heading', JSON.parse('{\\u0022level\\u0022:4}')"),
        button("'bulletList', []"),
        button("'table', []"),
    )

    assert.deepEqual(
        [...allowedFromToolbar(root)].sort(),
        ['bold', 'bulletList', 'h2', 'h4', 'link', 'table'],
    )
})

test('dropdown groups are read too (the trigger lists every key of the group)', () => {
    const root = parseHtml(
        `<div class="fi-fo-rich-editor"><div class="fi-fo-rich-editor-toolbar"><div x-data="{open:false}">
            <button class="fi-fo-rich-editor-dropdown-tool-trigger" x-bind:class="{ 'fi-active': (editorUpdatedAt &amp;&amp; $getEditor()?.isActive('bulletList', [])) || (editorUpdatedAt &amp;&amp; $getEditor()?.isActive('orderedList', [])) }"></button>
            <div role="menu">${button("'bulletList', []")}${button("'orderedList', []")}</div>
        </div></div></div>`,
    ).querySelector('.fi-fo-rich-editor')

    assert.deepEqual([...allowedFromToolbar(root)].sort(), ['bulletList', 'orderedList'])
})

test('floating toolbars count as offered', () => {
    const doc = parseHtml(
        `<div class="fi-fo-rich-editor"><div class="fi-fo-rich-editor-floating-toolbar">${button("'italic', []")}</div></div>`,
    )

    assert.deepEqual([...allowedFromToolbar(doc.querySelector('.fi-fo-rich-editor'))], ['italic'])
})

test('no toolbar, no buttons, or a non-element: null, so nothing is restricted', () => {
    assert.equal(allowedFromToolbar(null), null)
    assert.equal(allowedFromToolbar(parseHtml('<div class="fi-fo-rich-editor"></div>').body.firstChild), null)
    assert.equal(allowedFromToolbar(field('<button type="button"></button>')), null)
})

test('a button with a custom active expression (alignment) offers nothing to paste', () => {
    const root = field(
        `<button x-bind:class="{ 'fi-active': editorUpdatedAt &amp;&amp; ($getEditor()?.isActive({ textAlign: 'start' })) }"></button>`,
    )

    assert.equal(allowedFromToolbar(root), null)
})

// --- not restricting -------------------------------------------------------

test('null allowed set, empty html and non-strings are returned as is', () => {
    assert.equal(restrictToToolbar('<table></table>', null, { parseHtml }), '<table></table>')
    assert.equal(restrictToToolbar('', new Set(['bold'])), '')
    assert.equal(restrictToToolbar(null, new Set(['bold'])), null)
})

test('when everything is offered the same string comes back untouched', () => {
    const html = '<h2>T</h2><ul><li>a</li></ul><table><tr><td>x</td></tr></table><p><strong>b</strong></p>'
    const all = [...ALL_MARKS, 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'bulletList', 'orderedList', 'table', 'blockquote', 'codeBlock', 'horizontalRule']

    assert.equal(restrict(html, all), html)
})

// --- tables ------------------------------------------------------------------

test('without a table button a table becomes paragraphs, one per cell', () => {
    const out = restrict(
        '<table><tbody><tr><th>Name</th><th>Qty</th></tr><tr><td>Apple</td><td><p>3</p></td></tr></tbody></table>',
        ['bold'],
    )

    assert.equal(countOf(out, 'table, tr, td, th'), 0)
    assert.deepEqual(
        Array.from(parseHtml(out).body.querySelectorAll('p')).map((p) => p.textContent),
        ['Name', 'Qty', 'Apple', '3'],
    )
})

test('with a table button the table stays', () => {
    const html = '<table><tbody><tr><td>x</td></tr></tbody></table>'

    assert.equal(restrict(html, ['table']), html)
})

test('a nested table is flattened too', () => {
    const out = restrict('<table><tr><td><table><tr><td>inner</td></tr></table></td></tr></table>', ['bold'])

    assert.equal(countOf(out, 'table'), 0)
    assert.equal(textOf(out), 'inner')
})

// --- headings ----------------------------------------------------------------

test('without any heading button headings become paragraphs and keep their inline content', () => {
    const out = restrict('<h1>One <em>two</em></h1><h4>Four</h4>', ['italic'])

    assert.equal(countOf(out, 'h1, h4'), 0)
    assert.equal(countOf(out, 'p'), 2)
    assert.equal(countOf(out, 'p em'), 1)
})

test('a level that is not offered moves to the nearest offered one, on a tie the deeper', () => {
    assert.equal(countOf(restrict('<h1>x</h1>', ['h2', 'h3']), 'h2'), 1)
    assert.equal(countOf(restrict('<h6>x</h6>', ['h2', 'h3']), 'h3'), 1)
    assert.equal(countOf(restrict('<h3>x</h3>', ['h2', 'h4']), 'h4'), 1)
    assert.equal(countOf(restrict('<h2>x</h2>', ['h2', 'h4']), 'h2'), 1)
})

// --- lists -------------------------------------------------------------------

test('without list buttons items become paragraphs, nested ones included', () => {
    const out = restrict('<ul><li>a<ul><li>b</li></ul></li><li><p>c</p></li></ul>', ['bold'])

    assert.equal(countOf(out, 'ul, ol, li'), 0)
    assert.deepEqual(
        Array.from(parseHtml(out).body.querySelectorAll('p')).map((p) => p.textContent),
        ['a', 'b', 'c'],
    )
})

test('with only one kind of list the other is converted to it', () => {
    assert.equal(countOf(restrict('<ol><li>a</li></ol>', ['bulletList']), 'ul > li'), 1)
    assert.equal(countOf(restrict('<ol><li>a</li></ol>', ['bulletList']), 'ol'), 0)
    assert.equal(countOf(restrict('<ul><li>a</li></ul>', ['orderedList']), 'ol > li'), 1)
})

// --- blocks ------------------------------------------------------------------

test('blockquote, code block and rule are removed without their button, text stays', () => {
    const out = restrict(
        '<blockquote><p>q</p></blockquote><pre><code>l1\nl2</code></pre><hr><p>end</p>',
        ['bold'],
    )

    assert.equal(countOf(out, 'blockquote, pre, code, hr'), 0)
    assert.equal(countOf(out, 'p'), 3)
    assert.equal(countOf(out, 'br'), 1)
    assert.equal(textOf(out), 'ql1l2end')
})

test('the code block button keeps pre>code, and a rule button keeps hr', () => {
    const html = '<pre><code>x</code></pre><hr>'

    assert.equal(restrict(html, ['codeBlock', 'horizontalRule']), html)
})

// --- marks -------------------------------------------------------------------

test('a mark without its button is unwrapped; the ones with a button stay', () => {
    const html =
        '<p><strong>b</strong><b>b2</b><em>i</em><u>u</u><s>s</s><sub>sub</sub><sup>sup</sup><code>c</code><mark>m</mark><a href="https://a.test">l</a></p>'
    const out = restrict(html, ['bold', 'link'])

    assert.equal(countOf(out, 'strong, b'), 2)
    assert.equal(countOf(out, 'a[href]'), 1)
    assert.equal(countOf(out, 'em, u, s, sub, sup, code, mark'), 0)
    assert.equal(textOf(out), 'bb2iussubsupcml')
})

test('inline code inside a code block is not "code" (the block button decides)', () => {
    const html = '<pre><code>x</code></pre>'

    assert.equal(restrict(html, ['codeBlock']), html)
})

test('bold, italic, strike and friends map from their alternative tags', () => {
    const out = restrict('<p><i>i</i><del>d</del><strike>k</strike></p>', ['bold'])

    assert.equal(countOf(out, 'i, del, strike'), 0)
    assert.equal(textOf(out), 'idk')
})

// --- what strict leaves alone --------------------------------------------------

test('nodes without a toolbar counterpart are left alone', () => {
    const html =
        '<div data-type="customBlock" data-id="v"></div><p><img src="/a.png" data-id="1"><span data-type="mention" data-id="2">@a</span></p><iframe src="https://www.youtube-nocookie.com/embed/x"></iframe>'

    assert.equal(restrict(html, ['bold']), html)
})

// --- the in-editor cutoff ------------------------------------------------------

test('a paste from inside an editor is restricted too, and loses its slice marker when it changed', () => {
    const html = '<div data-pm-slice="1 1 []"><h2>T</h2><p>x</p></div>'
    const out = restrict(html, ['bold'])

    assert.equal(countOf(out, 'h2'), 0)
    assert.ok(!out.includes('data-pm-slice'))
})

test('an in-editor paste that needs no change keeps its marker (the same string is returned)', () => {
    const html = '<div data-pm-slice="1 1 []"><p>x</p></div>'

    assert.equal(restrict(html, ['bold']), html)
})

// The toolkit's own tools borrow the ACTIVE state of a stock tool (so that they light up inside a
// link / on an image) but mount an action of their own. Real markup, see the PHP test that pins it.
const toolButton = (isActive, click) =>
    `<button type="button" x-bind:class="{ 'fi-active': editorUpdatedAt &amp;&amp; $getEditor()?.isActive(${isActive}) }" x-on:click="${click}"></button>`

const stockLink = toolButton("'link', []", "$wire.mountAction('link', { editorSelection, ...{ url: 1 } }, JSON.parse('{}'))")
const linkOptions = toolButton("'link', []", "$wire.mountAction('linkOptions', { editorSelection, ...{ newTab: 1 } }, JSON.parse('{}'))")
const imageProperties = toolButton("'image', []", "$wire.mountAction('imageProperties', { editorSelection }, JSON.parse('{}'))")

test('a "Link options" button does not make the link mark allowed (it only borrows the link state)', () => {
    assert.equal(allowedFromToolbar(field(button("'bold', []"), linkOptions)).has('link'), false)
    assert.deepEqual([...allowedFromToolbar(field(button("'bold', []"), linkOptions))], ['bold'])
})

test('the stock Link button still counts, with or without Link options next to it', () => {
    assert.equal(allowedFromToolbar(field(button("'bold', []"), stockLink)).has('link'), true)
    assert.equal(allowedFromToolbar(field(button("'bold', []"), linkOptions, stockLink)).has('link'), true)
})

test('a toolbar of only borrowed-state tools cannot be read, so nothing is restricted (fail open)', () => {
    assert.equal(allowedFromToolbar(field(linkOptions, imageProperties)), null)
})

test('buttons that run a command directly are unaffected by the mounted-action rule', () => {
    const root = field(
        toolButton("'bold', []", "$getEditor()?.chain().focus().toggleBold().run()"),
        toolButton("'bulletList', []", "$getEditor()?.chain().focus().toggleBulletList().run()"),
        toolButton("'heading', JSON.parse('{\\u0022level\\u0022:2}')", "$getEditor()?.chain().focus().toggleHeading({ level: 2 }).run()"),
    )

    assert.deepEqual([...allowedFromToolbar(root)].sort(), ['bold', 'bulletList', 'h2'])
})

// --- wiring in the module ----------------------------------------------------------

import factory from '../../resources/js/src/paste-clean.js'

const withEditor = (url, toolbar, run) => {
    const doc = new JSDOM(`<div class="fi-fo-rich-editor">${toolbar}<div class="fi-fo-rich-editor-content"><div class="ProseMirror"></div></div></div>`).window.document
    const view = { dom: doc.querySelector('.ProseMirror') }

    globalThis.window = { FilamentRichEditor: { tiptap: { core: { Extension: { create: (config) => config } }, pmModel: {} } } }

    try {
        const extension = factory()

        extension.onCreate.call({ editor: { view } })
        run(extension, doc)
    } finally {
        delete globalThis.window
    }
}

test('without strict the module only cleans (no toolbar read)', () => {
    withEditor('', `<div class="fi-fo-rich-editor-toolbar">${button("'bold', []")}</div>`, (extension) => {
        // import.meta.url of the test is a file:// URL without ?strict=1.
        const out = extension.transformPastedHTML('<h2 style="color:red">T</h2>')

        assert.equal(countOf(out, 'h2'), 1)
    })
})

test('the default export hooks the editor on create and clears it on destroy', () => {
    withEditor('', '', (extension) => {
        assert.equal(typeof extension.onCreate, 'function')
        assert.equal(typeof extension.onDestroy, 'function')
        extension.onDestroy()
    })
})
