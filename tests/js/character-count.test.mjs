// Suite for the counter (resources/js/src/character-count.js).
//
// The shared fixtures (tests/fixtures/text-length.json) are run here against a real
// prosemirror-model schema and in tests/PHP/Unit/PlainTextTest.php against the PHP half:
// both must give the same numbers, or the counter and the validation rule disagree.

import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'
import { JSDOM } from 'jsdom'
import { Schema, DOMParser as PmDOMParser, Node as PmNode } from 'prosemirror-model'

import { parseRules } from '../../resources/js/src/image-properties.js'
import {
    countText,
    createCounter,
    labelFor,
    plainTextOf,
    settingsFrom,
    stateOf,
    textBlocks,
} from '../../resources/js/src/character-count.js'

const here = dirname(fileURLToPath(import.meta.url))
const dom = new JSDOM('')
const fixtures = JSON.parse(readFileSync(join(here, '../fixtures/text-length.json'), 'utf8')).cases

const listItem = (tag) => ({ tag })
const schema = new Schema({
    nodes: {
        doc: { content: 'block+' },
        paragraph: { group: 'block', content: 'inline*', parseDOM: [{ tag: 'p' }], toDOM: () => ['p', 0] },
        heading: { group: 'block', content: 'inline*', parseDOM: [{ tag: 'h1' }, { tag: 'h2' }], toDOM: () => ['h2', 0] },
        blockquote: { group: 'block', content: 'block+', parseDOM: [{ tag: 'blockquote' }], toDOM: () => ['blockquote', 0] },
        codeBlock: {
            group: 'block',
            content: 'text*',
            code: true,
            marks: '',
            parseDOM: [{ tag: 'pre', preserveWhitespace: 'full' }],
            toDOM: () => ['pre', ['code', 0]],
        },
        bulletList: { group: 'block', content: 'listItem+', parseDOM: [listItem('ul')], toDOM: () => ['ul', 0] },
        orderedList: { group: 'block', content: 'listItem+', parseDOM: [listItem('ol')], toDOM: () => ['ol', 0] },
        listItem: { content: 'paragraph block*', parseDOM: [listItem('li')], toDOM: () => ['li', 0] },
        table: { group: 'block', content: 'tableRow+', parseDOM: [{ tag: 'table' }], toDOM: () => ['table', 0] },
        tableRow: { content: 'tableCell+', parseDOM: [{ tag: 'tr' }], toDOM: () => ['tr', 0] },
        tableCell: { content: 'block+', parseDOM: [{ tag: 'td' }, { tag: 'th' }], toDOM: () => ['td', 0] },
        customBlock: {
            group: 'block',
            atom: true,
            parseDOM: [{ tag: 'div[data-type="customBlock"]' }],
            toDOM: () => ['div', { 'data-type': 'customBlock' }],
        },
        mention: {
            group: 'inline',
            inline: true,
            atom: true,
            parseDOM: [{ tag: 'span[data-type="mention"]' }],
            toDOM: () => ['span', { 'data-type': 'mention' }],
        },
        image: {
            group: 'inline',
            inline: true,
            atom: true,
            attrs: { src: { default: null }, caption: { default: null }, align: { default: null } },
            // The REAL parse rules of the image-properties module: a captioned image arrives as a
            // wrapper whose caption must end up in an attribute, never in the text.
            parseDOM: parseRules().map((rule) => ({
                ...rule,
                getAttrs: (el) => {
                    if (rule.getAttrs && rule.getAttrs(el) === false) {
                        return false
                    }

                    return { src: el.getAttribute('src'), caption: el.getAttribute('data-asignua-caption') || null }
                },
            })),
            toDOM: (node) => ['img', { src: node.attrs.src }],
        },
        hardBreak: { group: 'inline', inline: true, selectable: false, parseDOM: [{ tag: 'br' }], toDOM: () => ['br'] },
        text: { group: 'inline' },
    },
    marks: {
        bold: { parseDOM: [{ tag: 'strong' }], toDOM: () => ['strong', 0] },
        italic: { parseDOM: [{ tag: 'em' }], toDOM: () => ['em', 0] },
    },
})

const docOf = (fixture) =>
    fixture.json
        ? PmNode.fromJSON(schema, fixture.json)
        : PmDOMParser.fromSchema(schema).parse(
              new dom.window.DOMParser().parseFromString(fixture.html, 'text/html').body,
          )

for (const fixture of fixtures) {
    test(`shared fixture: ${fixture.name}`, () => {
        const counts = countText(plainTextOf(docOf(fixture)))

        assert.deepEqual(counts, { characters: fixture.characters, words: fixture.words })
    })
}

test('the same document gives the same text from html and from json', () => {
    const both = fixtures.filter((fixture) => fixture.html && fixture.json)

    assert.ok(both.length > 0)

    for (const fixture of both) {
        const fromHtml = plainTextOf(PmDOMParser.fromSchema(schema).parse(new dom.window.DOMParser().parseFromString(fixture.html, 'text/html').body))

        assert.equal(fromHtml, plainTextOf(PmNode.fromJSON(schema, fixture.json)))
    }
})

test('empty blocks are dropped and blocks are joined with a newline', () => {
    const doc = PmDOMParser.fromSchema(schema).parse(
        new dom.window.DOMParser().parseFromString('<p>a</p><p></p><p>b</p>', 'text/html').body,
    )

    assert.deepEqual(textBlocks(doc), ['a', 'b'])
    assert.equal(plainTextOf(doc), 'a\nb')
})

// --- counting rules -----------------------------------------------------

test('words split on the explicit whitespace list, newline included', () => {
    assert.equal(countText('a\nb').words, 2)
    assert.equal(countText('a b').words, 2)
    assert.equal(countText('a　b').words, 2)
    assert.equal(countText('a﻿b').words, 2)
    assert.equal(countText('  ').words, 0)
    assert.equal(countText('').words, 0)
})

test('characters count code points and ignore newlines only', () => {
    assert.equal(countText('😀😀').characters, 2)
    assert.equal(countText('a b').characters, 3)
    assert.equal(countText('a\nb').characters, 2)
})

// --- state, labels, settings --------------------------------------------

test('state: ok, near from the warn share, over above the limit, the worse of both limits wins', () => {
    const options = { max: 10, maxWords: 3, warn: 0.9 }

    assert.equal(stateOf({ characters: 5, words: 1 }, options), 'ok')
    assert.equal(stateOf({ characters: 9, words: 1 }, options), 'near')
    assert.equal(stateOf({ characters: 10, words: 1 }, options), 'near')
    assert.equal(stateOf({ characters: 11, words: 1 }, options), 'over')
    assert.equal(stateOf({ characters: 5, words: 4 }, options), 'over')
    assert.equal(stateOf({ characters: 5, words: 1 }, { max: null }), 'ok')
    assert.equal(stateOf({ characters: 5000, words: 900 }, {}), 'ok')
})

test('settings come from the query string and fall back to safe defaults', () => {
    const settings = settingsFrom(
        new URLSearchParams('max=500&max-words=80&words=1&warn=0.8&t-chars-max=Z+:count+/+:max&css=/x.css'),
    )

    assert.equal(settings.max, 500)
    assert.equal(settings.maxWords, 80)
    assert.equal(settings.showWords, true)
    assert.equal(settings.warn, 0.8)
    assert.equal(settings.labels.charsMax, 'Z :count / :max')
    assert.equal(settings.css, '/x.css')

    const empty = settingsFrom(new URLSearchParams('max=abc&warn=7'))

    assert.equal(empty.max, null)
    assert.equal(empty.maxWords, null)
    assert.equal(empty.showWords, false)
    assert.equal(empty.warn, 0.9)
})

test('the label shows "count / max" with a limit and the word count when asked', () => {
    const base = settingsFrom(
        new URLSearchParams('t-chars=C:+:count&t-chars-max=C:+:count+/+:max&t-words=W:+:count&t-words-max=W:+:count+/+:max'),
    )
    const counts = { characters: 12, words: 3 }

    assert.equal(labelFor(counts, base), 'C: 12')
    assert.equal(labelFor(counts, { ...base, max: 500 }), 'C: 12 / 500')
    assert.equal(labelFor(counts, { ...base, max: 500, showWords: true }), 'C: 12 / 500 · W: 3')
    assert.equal(labelFor(counts, { ...base, maxWords: 80 }), 'C: 12 · W: 3 / 80')
})

// --- the DOM -------------------------------------------------------------

test('the counter updates text and state, and speaks only when it flips to over', () => {
    const doc = new JSDOM('<div id="host"></div>').window.document
    const host = doc.getElementById('host')
    const settings = settingsFrom(
        new URLSearchParams('max=10&t-chars-max=:count+of+:max&t-over-chars=Too+long'),
    )
    const counter = createCounter(host, settings, doc)
    const status = counter.element.querySelector('[role="status"]')
    const text = counter.element.querySelector('.asignua-rte-count-text')

    counter.update({ characters: 3, words: 1 })
    assert.equal(text.textContent, '3 of 10')
    assert.equal(counter.element.getAttribute('data-state'), 'ok')
    assert.equal(status.textContent, '')

    counter.update({ characters: 11, words: 1 })
    assert.equal(counter.element.getAttribute('data-state'), 'over')
    assert.equal(status.textContent, 'Too long')

    counter.update({ characters: 4, words: 1 })
    assert.equal(counter.element.getAttribute('data-state'), 'ok')
    assert.equal(status.textContent, '')

    // The visible text is not a live region.
    assert.equal(text.getAttribute('aria-live'), null)

    counter.destroy()
    assert.equal(host.children.length, 0)
})

test('the module imports under Node without window', () => {
    assert.equal(typeof countText, 'function')
})
