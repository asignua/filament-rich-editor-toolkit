// Suite for the source view: the pretty printer and the difference detector
// (resources/js/src/source-html.js) and the toggle controller (source-code.js), the latter two
// against a real prosemirror-model schema standing in for the editor's.

import { test } from 'node:test'
import assert from 'node:assert/strict'
import { JSDOM } from 'jsdom'
import { Schema, DOMParser as PmDOMParser, DOMSerializer } from 'prosemirror-model'

import { materiallyDiffers, prettyHtml } from '../../resources/js/src/source-html.js'
import { ACTIVE_CLASS, createSourceView } from '../../resources/js/src/source-code.js'

const dom = new JSDOM('')
const parseHtml = (html) => new dom.window.DOMParser().parseFromString(html, 'text/html')
const pretty = (html, indent = 2) => prettyHtml(html, { indent, parseHtml })
const differs = (typed, normalized) => materiallyDiffers(typed, normalized, parseHtml)

// --- the schema -------------------------------------------------------------------------------

const li = { tag: 'li' }
const schema = new Schema({
    nodes: {
        doc: { content: 'block+' },
        paragraph: { group: 'block', content: 'inline*', parseDOM: [{ tag: 'p' }], toDOM: () => ['p', 0] },
        heading: {
            group: 'block',
            content: 'inline*',
            attrs: { level: { default: 2 } },
            parseDOM: [2, 3].map((level) => ({ tag: `h${level}`, attrs: { level } })),
            toDOM: (node) => [`h${node.attrs.level}`, 0],
        },
        blockquote: { group: 'block', content: 'block+', parseDOM: [{ tag: 'blockquote' }], toDOM: () => ['blockquote', 0] },
        codeBlock: {
            group: 'block',
            content: 'text*',
            code: true,
            marks: '',
            parseDOM: [{ tag: 'pre', preserveWhitespace: 'full' }],
            toDOM: () => ['pre', ['code', 0]],
        },
        bulletList: { group: 'block', content: 'listItem+', parseDOM: [{ tag: 'ul' }], toDOM: () => ['ul', 0] },
        orderedList: { group: 'block', content: 'listItem+', parseDOM: [{ tag: 'ol' }], toDOM: () => ['ol', 0] },
        listItem: { content: 'paragraph block*', parseDOM: [li], toDOM: () => ['li', 0] },
        horizontalRule: { group: 'block', parseDOM: [{ tag: 'hr' }], toDOM: () => ['hr'] },
        hardBreak: { group: 'inline', inline: true, selectable: false, parseDOM: [{ tag: 'br' }], toDOM: () => ['br'] },
        text: { group: 'inline' },
    },
    marks: {
        bold: { parseDOM: [{ tag: 'strong' }, { tag: 'b' }], toDOM: () => ['strong', 0] },
        italic: { parseDOM: [{ tag: 'em' }, { tag: 'i' }], toDOM: () => ['em', 0] },
        link: {
            attrs: { href: {} },
            inclusive: false,
            parseDOM: [{ tag: 'a[href]', getAttrs: (el) => ({ href: el.getAttribute('href') }) }],
            // Like TipTap's link: attributes the author never typed are added on output.
            toDOM: (mark) => ['a', { href: mark.attrs.href, target: '_blank', rel: 'noopener noreferrer nofollow' }, 0],
        },
    },
})

/** What the editor does with a string: parse through the schema, serialise back. */
const roundTrip = (html) => {
    const doc = PmDOMParser.fromSchema(schema).parse(parseHtml(html).body)
    const holder = dom.window.document.createElement('div')

    holder.appendChild(DOMSerializer.fromSchema(schema).serializeFragment(doc.content, { document: dom.window.document }))

    return holder.innerHTML
}

// --- the pretty printer -----------------------------------------------------------------------

test('block elements go on their own lines and containers are indented', () => {
    const out = pretty('<h2>Title</h2><p>One <strong>two</strong></p><ul><li><p>a</p></li><li><p>b</p></li></ul>')

    assert.equal(
        out,
        ['<h2>Title</h2>', '<p>One <strong>two</strong></p>', '<ul>', '  <li>', '    <p>a</p>', '  </li>', '  <li>', '    <p>b</p>', '  </li>', '</ul>'].join('\n'),
    )
})

test('indent width is configurable and clamped', () => {
    assert.equal(pretty('<ul><li><p>a</p></li></ul>', 4), '<ul>\n    <li>\n        <p>a</p>\n    </li>\n</ul>')
    assert.equal(pretty('<ul><li><p>a</p></li></ul>', 0), '<ul>\n<li>\n<p>a</p>\n</li>\n</ul>')
    assert.equal(pretty('<ul><li><p>a</p></li></ul>', 99).split('\n')[2], ' '.repeat(16) + '<p>a</p>')
    assert.equal(pretty('<ul><li>a</li></ul>', -3), '<ul>\n<li>a</li>\n</ul>')
})

test('<pre> is printed exactly as parsed, nothing inside is re-indented', () => {
    const out = pretty('<blockquote><pre><code>line 1\n   indented\n\n  <b>x</b>\n</code></pre></blockquote>')

    assert.equal(out, '<blockquote>\n  <pre><code>line 1\n   indented\n\n  <b>x</b>\n</code></pre>\n</blockquote>')
})

test('a newline right after <pre> is written back so the content survives a re-parse', () => {
    const out = pretty('<pre>\n\nx</pre>')
    const again = parseHtml(out).body.querySelector('pre').textContent

    assert.equal(again, parseHtml('<pre>\n\nx</pre>').body.querySelector('pre').textContent)
})

test('void elements get no closing tag, and keep their attributes', () => {
    const out = pretty('<p>a<br>b</p><hr><p><img src="/a.png" alt="x &amp; y"></p>')

    assert.equal(out, '<p>a<br>b</p>\n<hr>\n<p><img src="/a.png" alt="x &amp; y"></p>')
    assert.ok(!out.includes('</br>') && !out.includes('</hr>') && !out.includes('</img>'))
})

test('text and attribute values are escaped, nbsp is visible', () => {
    const out = pretty('<p title="a &quot;q&quot; &lt;b&gt;">1 &lt; 2 &amp; 3&nbsp;4</p>')

    assert.equal(out, '<p title="a &quot;q&quot; <b>">1 &lt; 2 &amp; 3&nbsp;4</p>')
})

test('whitespace between blocks disappears, inline whitespace inside a block stays', () => {
    assert.equal(pretty('\n  <p>a  b</p>\n\n  <p> c </p>  '), '<p>a  b</p>\n<p> c </p>')
})

test('loose inline content next to blocks gets its own line', () => {
    assert.equal(pretty('<ul><li>text <em>x</em><ul><li>deep</li></ul></li></ul>'), '<ul>\n  <li>\n    text <em>x</em>\n    <ul>\n      <li>deep</li>\n    </ul>\n  </li>\n</ul>')
})

test('tables are expanded down to the cells, cell content stays on one line', () => {
    const out = pretty('<table><tbody><tr><th>H</th><td>c <b>1</b></td></tr></tbody></table>')

    assert.equal(out, ['<table>', '  <tbody>', '    <tr>', '      <th>H</th>', '      <td>c <b>1</b></td>', '    </tr>', '  </tbody>', '</table>'].join('\n'))
})

test('comments and script bodies are not mangled', () => {
    assert.equal(pretty('<!-- keep -->'), '<!-- keep -->')
    assert.equal(pretty('<script>if (a < b && c) {}</script>'), '<script>if (a < b && c) {}</script>')
})

test('printing is idempotent and does not change what the parser sees', () => {
    const html = '<h2>T</h2><p>a <a href="/x?a=1&amp;b=2">link</a><br>b</p><ul><li><p>x</p><ul><li><p>y</p></li></ul></li></ul><pre><code>a\n  b</code></pre><hr>'
    const once = pretty(html)

    assert.equal(pretty(once), once)
    assert.equal(differs(html, once), false)
})

test('empty input prints nothing', () => {
    assert.equal(pretty(''), '')
    assert.equal(pretty('   \n '), '')
})

// --- the difference detector ------------------------------------------------------------------

test('identical markup is not a difference, whatever the whitespace or attribute order', () => {
    assert.equal(differs('<p class="a" id="b">x</p>', '<p id="b"   class="a">x</p>'), false)
    assert.equal(differs('<p>a   b\n c</p>\n<p>d</p>', '<p>a b c</p><p>d</p>'), false)
    assert.equal(differs('<ul>\n <li>\n  <p>x</p>\n </li>\n</ul>', '<ul><li><p>x</p></li></ul>'), false)
    assert.equal(differs('<!-- note --><p>x</p>', '<p>x</p>'), false)
})

test('aliases and wrappers the schema adds or removes are not a difference', () => {
    assert.equal(differs('<p><b>x</b> <i>y</i> <strike>z</strike></p>', '<p><strong>x</strong> <em>y</em> <s>z</s></p>'), false)
    assert.equal(differs('just text', '<p>just text</p>'), false)
    assert.equal(differs('<p>a</p>loose <b>b</b>', '<p>a</p><p>loose <strong>b</strong></p>'), false)
    assert.equal(differs('<div><p>a</p></div><span>b</span>', '<p>a</p><p>b</p>'), false)
    assert.equal(differs('<ul><li>a</li></ul>', '<ul><li><p>a</p></li></ul>'), false)
    assert.equal(differs('<pre>a\nb</pre>', '<pre><code>a\nb</code></pre>'), false)
    assert.equal(differs('', '<p></p>'), false)
})

test('attributes the editor added are not a difference, attributes it lost are', () => {
    assert.equal(differs('<p><a href="/x">l</a></p>', '<p><a href="/x" target="_blank" rel="noopener">l</a></p>'), false)
    assert.equal(differs('<p class="a b">x</p>', '<p class="b a c">x</p>'), false)
    assert.equal(differs('<p style="color: red;">x</p>', '<p style="color:red">x</p>'), false)

    assert.equal(differs('<p class="lead">x</p>', '<p>x</p>'), true)
    assert.equal(differs('<p id="x">x</p>', '<p id="y">x</p>'), true)
    assert.equal(differs('<p style="color:red">x</p>', '<p style="color:blue">x</p>'), true)
    assert.equal(differs('<p><a href="/x" title="t">l</a></p>', '<p><a href="/x">l</a></p>'), true)
})

test('lost elements, text, levels and types are a difference', () => {
    assert.equal(differs('<p>a</p><table><tr><td>x</td></tr></table>', '<p>a</p>'), true)
    assert.equal(differs('<p>a <mark>b</mark></p>', '<p>a b</p>'), true)
    assert.equal(differs('<p>a</p><p>b</p>', '<p>a</p>'), true)
    assert.equal(differs('<h1>T</h1>', '<h2>T</h2>'), true)
    assert.equal(differs('<ol><li>a</li></ol>', '<ul><li>a</li></ul>'), true)
    assert.equal(differs('<p>abc</p>', '<p>abd</p>'), true)
    assert.equal(differs('<div class="box"><p>a</p></div>', '<p>a</p>'), true)
    assert.equal(differs('<p>a</p><script>alert(1)</script>', '<p>a</p>'), true)
    assert.equal(differs('<p>a<br>b</p>', '<p>ab</p>'), true)
})

test('whitespace inside <pre> is significant', () => {
    assert.equal(differs('<pre>a  b</pre>', '<pre>a b</pre>'), true)
    assert.equal(differs('<pre>a  b</pre>', '<pre><code>a  b</code></pre>'), false)
})

test('nbsp is not collapsed', () => {
    assert.equal(differs('<p>a&nbsp;&nbsp;b</p>', '<p>a b</p>'), true)
})

// --- round trip through a real schema ---------------------------------------------------------

test('round trip: markup the schema keeps is not reported', () => {
    const typed = pretty(
        '<h2>Title</h2><p>One <b>two</b> <i>three</i> <a href="/x">four</a></p><ol><li>a</li><li>b</li></ol><blockquote><p>q</p></blockquote><pre>code\n  x</pre><hr>',
    )

    assert.equal(differs(typed, roundTrip(typed)), false)
})

test('round trip: markup the schema does not know is reported', () => {
    for (const typed of [
        '<p class="lead">x</p>',
        '<table><tr><td>x</td></tr></table><p>y</p>',
        '<p>a <mark>b</mark></p>',
        '<h1>T</h1>',
        '<div class="box"><p>x</p></div>',
        '<p>x</p><script>alert(1)</script>',
    ]) {
        assert.equal(differs(typed, roundTrip(typed)), true, typed)
    }
})

// --- the controller ---------------------------------------------------------------------------

const page = () => {
    const { window } = new JSDOM(
        `<!doctype html><html><body><form id="form"><div class="fi-fo-rich-editor" id="root"><div id="host">
            <div class="fi-fo-rich-editor-toolbar">
                <button type="button" id="bold" x-on:click="$getEditor()?.chain().focus().toggleBold().run()"></button>
                <button type="button" id="source" x-on:click="$getEditor()?.commands.asignuaToggleSourceCode()"></button>
                <button type="button" id="full" x-on:click="$getEditor()?.commands.asignuaToggleFullscreen()"></button>
                <button type="button" id="off" disabled x-on:click="x"></button>
            </div>
            <div class="fi-fo-rich-editor-main"><div class="ProseMirror"></div></div>
        </div></div></form></body></html>`,
        { url: 'https://panel.test/admin' },
    )

    return window
}

/** An editor over the real schema: the same getHTML / setContent shape TipTap exposes. */
const fakeEditor = (html) => {
    const editor = {
        isEditable: true,
        html: roundTrip(html),
        calls: [],
        getHTML: () => editor.html,
        commands: {
            setContent: (value, options) => {
                editor.calls.push([value, options])
                editor.html = roundTrip(value)

                return true
            },
            focus: () => {
                editor.focused = true

                return true
            },
        },
    }

    return editor
}

const setup = (html = '<h2>T</h2><p>a</p>', extra = {}) => {
    const window = page()
    const editor = fakeEditor(html)
    const root = window.document.getElementById('root')
    const view = createSourceView(root, editor, { doc: window.document, parseHtml, notice: 'NOTICE', label: 'LABEL', delay: 5, ...extra })

    return { window, editor, root, view, doc: window.document }
}

test('entering shows a labelled, pretty-printed textarea and locks the toolbar except source and fullscreen', () => {
    const { editor, root, view, doc } = setup()

    assert.equal(view.enter(), true)

    const textarea = doc.querySelector('textarea.asignua-rte-source')

    assert.equal(textarea.value, '<h2>T</h2>\n<p>a</p>')
    assert.equal(textarea.getAttribute('aria-label'), 'LABEL')
    assert.equal(textarea.getAttribute('dir'), 'ltr')
    assert.equal(textarea.readOnly, false)
    assert.ok(root.classList.contains(ACTIVE_CLASS))
    assert.equal(doc.getElementById('bold').disabled, true)
    assert.equal(doc.getElementById('source').disabled, false)
    assert.equal(doc.getElementById('full').disabled, false)
    assert.equal(editor.calls.length, 0)

    assert.equal(view.enter(), false)
})

test('a click on a locked tool is swallowed before it reaches the tool, a kept tool is not', () => {
    const { window, view, doc } = setup()
    const reached = []

    doc.getElementById('bold').addEventListener('click', () => reached.push('bold'))
    doc.getElementById('source').addEventListener('click', () => reached.push('source'))
    view.enter()

    doc.getElementById('bold').dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }))
    doc.getElementById('source').dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }))

    assert.deepEqual(reached, ['source'])

    view.exit()
    doc.getElementById('bold').dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }))
    assert.deepEqual(reached, ['source', 'bold'])
})

test('leaving without edits does not touch the editor and shows no notice', () => {
    const { editor, root, view, doc } = setup()

    view.enter()
    view.exit()

    assert.equal(editor.calls.length, 0)
    assert.equal(doc.querySelector('textarea'), null)
    assert.equal(doc.querySelector('.asignua-rte-source-notice'), null)
    assert.ok(!root.classList.contains(ACTIVE_CLASS))
    assert.equal(doc.getElementById('bold').disabled, false)
    assert.equal(doc.getElementById('off').disabled, true, 'a tool that was already disabled stays disabled')
    assert.equal(editor.focused, true)
})

test('leaving with an edit parses it through the editor with emitUpdate and does not complain about supported markup', () => {
    const { editor, view, doc } = setup()

    view.enter()
    doc.querySelector('textarea').value = '<h2>T</h2>\n<p>a <b>b</b></p>'
    view.exit()

    assert.deepEqual(editor.calls, [['<h2>T</h2>\n<p>a <b>b</b></p>', { emitUpdate: true }]])
    assert.equal(editor.getHTML(), '<h2>T</h2><p>a <strong>b</strong></p>')
    assert.equal(doc.querySelector('.asignua-rte-source-notice'), null)
})

test('leaving with markup the schema drops shows a non-blocking status notice, which the next edit clears', () => {
    const { editor, view, doc } = setup()

    view.enter()
    doc.querySelector('textarea').value = '<p class="lead">kept text</p><table><tr><td>x</td></tr></table>'
    view.exit()

    const notice = doc.querySelector('.asignua-rte-source-notice')

    assert.equal(notice.textContent, 'NOTICE')
    assert.equal(notice.getAttribute('role'), 'status')
    assert.ok(!editor.getHTML().includes('table'))

    view.enter()
    assert.equal(doc.querySelector('.asignua-rte-source-notice'), null, 'entering again clears it')
})

test('typing is pushed into the editor after a pause, once per distinct value', async () => {
    const { editor, view, doc, window } = setup()

    view.enter()

    const textarea = doc.querySelector('textarea')

    textarea.value = '<p>one</p>'
    textarea.dispatchEvent(new window.Event('input', { bubbles: true }))
    textarea.value = '<p>one two</p>'
    textarea.dispatchEvent(new window.Event('input', { bubbles: true }))

    assert.equal(editor.calls.length, 0)

    await new Promise((resolve) => setTimeout(resolve, 30))

    assert.equal(editor.calls.length, 1)
    assert.equal(editor.getHTML(), '<p>one two</p>')

    // Already synced: the exit does not set the same content again.
    view.exit()
    assert.equal(editor.calls.length, 1)
})

test('blur (a click on Save) flushes the pending edit at once', () => {
    const { editor, view, doc, window } = setup()

    view.enter()

    const textarea = doc.querySelector('textarea')

    textarea.value = '<p>typed</p>'
    textarea.dispatchEvent(new window.Event('input', { bubbles: true }))
    textarea.dispatchEvent(new window.FocusEvent('focusout', { bubbles: true }))

    assert.equal(editor.getHTML(), '<p>typed</p>')
    assert.equal(editor.calls.length, 1)
})

test('submitting the form while still in source mode keeps the source', () => {
    const { editor, view, doc, window } = setup()

    view.enter()
    doc.querySelector('textarea').value = '<p>unsaved source</p>'
    doc.getElementById('form').dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }))

    assert.equal(editor.getHTML(), '<p>unsaved source</p>')

    // After leaving the form listener is gone.
    view.exit()
    const before = editor.calls.length

    doc.getElementById('form').dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }))
    assert.equal(editor.calls.length, before)
})

test('a read-only editor gets a read-only textarea that never writes back', () => {
    const { editor, view, doc } = setup()

    editor.isEditable = false
    view.enter()

    const textarea = doc.querySelector('textarea')

    assert.equal(textarea.readOnly, true)
    textarea.value = '<p>nope</p>'
    view.exit()
    assert.equal(editor.calls.length, 0)
})

test('the printed source never reaches the live document as markup', () => {
    const { view, doc } = setup('<p>x</p>')

    view.enter()
    doc.querySelector('textarea').value = '<img src=x onerror="window.pwned=1"><script>window.pwned=1</script>'
    view.exit()

    assert.equal(doc.querySelectorAll('img, script').length, 0)
    assert.equal(doc.defaultView.pwned, undefined)
})

test('destroy while in source mode removes everything it added', () => {
    const { root, view, doc } = setup()

    view.enter()
    view.destroy()

    assert.equal(doc.querySelector('textarea'), null)
    assert.ok(!root.classList.contains(ACTIVE_CLASS))
    assert.equal(doc.getElementById('bold').disabled, false)
})

// --- the document changes under the textarea ---------------------------------------------------------

/** What the server does: it puts a new state into the editor (setContent without emitUpdate). */
const changeFromOutside = (editor, html) => {
    editor.html = roundTrip(html)
}

test('a change that comes from outside rebuilds the textarea, so the stale text cannot be pushed back', async () => {
    const { editor, view, doc, window } = setup('<p>id old</p>')

    view.enter()

    const textarea = doc.querySelector('textarea')

    // The server rewrote the document (e.g. saveFileAttachments swapped an attachment id).
    changeFromOutside(editor, '<p>id new</p>')
    assert.equal(view.refresh(), true)
    assert.equal(textarea.value, '<p>id new</p>')

    // The next keystroke or blur must not resurrect the old text.
    textarea.dispatchEvent(new window.FocusEvent('focusout', { bubbles: true }))
    textarea.dispatchEvent(new window.Event('input', { bubbles: true }))
    await new Promise((resolve) => setTimeout(resolve, 30))

    assert.equal(editor.calls.length, 0, 'nothing was pushed: the textarea equals the editor')
    assert.equal(editor.getHTML(), '<p>id new</p>')
})

test('typing after such a change builds on the NEW document', async () => {
    const { editor, view, doc, window } = setup('<p>id old</p>')

    view.enter()
    changeFromOutside(editor, '<p>id new</p>')
    view.refresh()

    const textarea = doc.querySelector('textarea')

    textarea.value = '<p>id new, edited</p>'
    textarea.dispatchEvent(new window.Event('input', { bubbles: true }))
    await new Promise((resolve) => setTimeout(resolve, 30))

    assert.equal(editor.getHTML(), '<p>id new, edited</p>')
})

test('the stale-id scenario without refresh is the bug: a flush overwrites the new document', () => {
    // Documents what refresh() prevents: the textarea still holds the old text and the user types.
    const { editor, view, doc } = setup('<p>id old</p>')

    view.enter()
    const textarea = doc.querySelector('textarea')

    textarea.value = '<p>id old!</p>'
    changeFromOutside(editor, '<p>id new</p>')
    view.exit()

    assert.equal(editor.getHTML(), '<p>id old!</p>')
})

test('unflushed typing survives a change from outside: kept, flagged, never pushed over the new document', async () => {
    const { editor, view, doc, window } = setup('<p>a</p>', { conflict: 'CONFLICT' })

    view.enter()

    const textarea = doc.querySelector('textarea')

    textarea.value = '<p>typed a moment ago</p>'
    textarea.dispatchEvent(new window.Event('input', { bubbles: true }))
    changeFromOutside(editor, '<p>from the server</p>')

    assert.equal(view.refresh(), false)
    await new Promise((resolve) => setTimeout(resolve, 30))

    assert.equal(textarea.value, '<p>typed a moment ago</p>', 'the user text is kept')
    assert.equal(editor.getHTML(), '<p>from the server</p>')
    assert.equal(editor.calls.length, 0, 'the debounced push was cancelled')

    const notice = doc.querySelector('.asignua-rte-source-notice')

    assert.equal(notice.textContent, 'CONFLICT')
    assert.equal(notice.getAttribute('role'), 'status')

    // Every route that used to sync is suspended while stale: blur, change, submit, Ctrl+S.
    textarea.dispatchEvent(new window.FocusEvent('focusout', { bubbles: true }))
    textarea.dispatchEvent(new window.Event('change', { bubbles: true }))
    textarea.dispatchEvent(keydown(window, { key: 's', ctrlKey: true }))
    textarea.value = '<p>typed more</p>'
    textarea.dispatchEvent(new window.Event('input', { bubbles: true }))
    await new Promise((resolve) => setTimeout(resolve, 30))

    assert.equal(editor.calls.length, 0)
    assert.equal(editor.getHTML(), '<p>from the server</p>')
})

test('leaving a stale source view discards the unapplied text (the editor keeps the server document) and clears the message', () => {
    const { editor, view, doc } = setup('<p>a</p>', { conflict: 'CONFLICT' })

    view.enter()
    doc.querySelector('textarea').value = '<p>old ids</p>'
    changeFromOutside(editor, '<p>from the server</p>')
    view.refresh()

    view.exit()

    assert.equal(editor.getHTML(), '<p>from the server</p>')
    assert.equal(editor.calls.length, 0)
    assert.equal(doc.querySelector('.asignua-rte-source-notice'), null)
    assert.equal(doc.querySelector('textarea'), null)
})

test('entering again after a conflict starts clean and syncs normally', () => {
    const { editor, view, doc } = setup('<p>a</p>', { conflict: 'CONFLICT' })

    view.enter()
    doc.querySelector('textarea').value = '<p>x</p>'
    changeFromOutside(editor, '<p>b</p>')
    view.refresh()
    view.exit()
    view.enter()

    const textarea = doc.querySelector('textarea')

    assert.equal(textarea.value, '<p>b</p>')
    textarea.value = '<p>c</p>'
    view.exit()
    assert.equal(editor.getHTML(), '<p>c</p>')
})

test('refresh does nothing when the editor already shows the textarea, or when the source is not open', () => {
    const { view, doc } = setup('<p>a</p>')

    assert.equal(view.refresh(), false, 'not active')
    view.enter()
    assert.equal(view.refresh(), false, 'same text')

    const textarea = doc.querySelector('textarea')

    textarea.setSelectionRange(2, 2)
    view.refresh()
    assert.equal(textarea.selectionStart, 2, 'the caret is not disturbed')
})

test('refresh keeps the caret near where it was: counted from the end when it was nearer to it', () => {
    const { editor, view, doc } = setup('<p>0123456789</p>')

    view.enter()

    const textarea = doc.querySelector('textarea')

    textarea.focus()
    textarea.setSelectionRange(textarea.value.length - 3, textarea.value.length - 3)
    changeFromOutside(editor, '<p>0123456789 and a much longer tail now</p>')
    view.refresh()

    assert.equal(textarea.value.length - textarea.selectionStart, 3)
})

test('our own sync is not a change from outside', () => {
    const { editor, view, doc } = setup('<p>a</p>')

    view.enter()

    const textarea = doc.querySelector('textarea')
    let refreshed = null

    // The real editor emits `update` / a transaction INSIDE setContent: refresh() must see syncing.
    const setContent = editor.commands.setContent

    editor.commands.setContent = (...args) => {
        const result = setContent(...args)

        refreshed = view.refresh()

        return result
    }

    textarea.value = '<p>typed</p>'
    textarea.dispatchEvent(new doc.defaultView.FocusEvent('focusout', { bubbles: true }))

    assert.equal(refreshed, false)
    assert.equal(textarea.value, '<p>typed</p>')
})

// --- keyboard save ------------------------------------------------------------------------------------

const keydown = (window, init) => new window.KeyboardEvent('keydown', { bubbles: true, cancelable: true, ...init })

test('Ctrl/Cmd+S in the textarea flushes at once (it fires neither focusout nor submit)', () => {
    const { editor, view, doc, window } = setup()

    view.enter()

    const textarea = doc.querySelector('textarea')

    textarea.value = '<p>unsaved</p>'
    textarea.dispatchEvent(new window.Event('input', { bubbles: true }))
    assert.equal(editor.calls.length, 0)

    textarea.dispatchEvent(keydown(window, { key: 's', ctrlKey: true }))
    assert.equal(editor.getHTML(), '<p>unsaved</p>', 'synchronously, before the shortcut handler reads the state')

    textarea.value = '<p>more</p>'
    textarea.dispatchEvent(keydown(window, { key: 's', metaKey: true }))
    assert.equal(editor.getHTML(), '<p>more</p>')
})

test('any chord with a modifier flushes; a plain key and a bare modifier press do not', () => {
    const { editor, view, doc, window } = setup()

    view.enter()

    const textarea = doc.querySelector('textarea')

    textarea.value = '<p>one</p>'
    textarea.dispatchEvent(keydown(window, { key: 'a' }))
    textarea.dispatchEvent(keydown(window, { key: 'Control', ctrlKey: true }))
    textarea.dispatchEvent(keydown(window, { key: 'Meta', metaKey: true }))
    assert.equal(editor.calls.length, 0)

    textarea.dispatchEvent(keydown(window, { key: 'Enter', ctrlKey: true }))
    assert.equal(editor.calls.length, 1)
})

// --- the extension wiring ---------------------------------------------------------------------------------

import factory from '../../resources/js/src/source-code.js'

test('the extension refreshes the open source view on a document transaction, not on a selection one', () => {
    const win = page()
    const editor = fakeEditor('<p>old</p>')
    const root = win.document.getElementById('root')

    editor.view = { dom: win.document.querySelector('.ProseMirror') }
    globalThis.window = { FilamentRichEditor: { tiptap: { core: { Extension: { create: (config) => config } } } }, DOMParser: dom.window.DOMParser }
    globalThis.document = win.document

    try {
        const extension = factory()
        const context = { editor, storage: {} }

        // onCreate builds the controller from the real root.
        extension.onCreate.call(context)
        assert.ok(root)

        // Reach the controller through the toggle command.
        const toggle = extension.addCommands.call({ storage: context.storage }).asignuaToggleSourceCode
        const tr = { setMeta: () => tr }

        editor.state = { tr }
        editor.view.dispatch = () => {}
        toggle()({ tr, dispatch: () => {}, editor })

        const textarea = win.document.querySelector('textarea.asignua-rte-source')

        assert.ok(textarea)

        // The server's setContent emits NO update, only a transaction.
        editor.html = roundTrip('<p>from the server</p>')
        extension.onTransaction.call(context, { transaction: { docChanged: false } })
        assert.equal(textarea.value, '<p>old</p>', 'a selection-only transaction changes nothing')

        extension.onTransaction.call(context, { transaction: { docChanged: true } })
        assert.equal(textarea.value, '<p>from the server</p>')

        extension.onDestroy.call(context)
    } finally {
        delete globalThis.window
        delete globalThis.document
    }
})

// --- Livewire morph ---------------------------------------------------------------------------

const withLivewire = (window) => {
    const hooks = []

    window.Livewire = {
        hook(name, callback) {
            const entry = { name, callback }

            hooks.push(entry)

            return () => hooks.splice(hooks.indexOf(entry), 1)
        },
    }

    return { hooks, morph: (el) => hooks.forEach((h) => h.callback({ el })) }
}

test('a Livewire morph restores the active class and the locked buttons; leaving and destroy unregister', () => {
    const { window, root, view, doc } = setup()
    const { hooks, morph } = withLivewire(window)

    view.enter()
    assert.equal(hooks.length, 1)
    assert.equal(hooks[0].name, 'morph.updated')

    root.classList.remove(ACTIVE_CLASS)
    doc.getElementById('bold').disabled = false
    morph(root)

    assert.ok(root.classList.contains(ACTIVE_CLASS))
    assert.equal(doc.getElementById('bold').disabled, true)
    assert.equal(doc.getElementById('source').disabled, false)
    assert.equal(doc.getElementById('off').disabled, true)

    // A button morphed on its own.
    doc.getElementById('bold').disabled = false
    morph(doc.getElementById('bold'))
    assert.equal(doc.getElementById('bold').disabled, true)

    view.exit()
    assert.equal(hooks.length, 0)
    assert.equal(doc.getElementById('bold').disabled, false)

    view.enter()
    assert.equal(hooks.length, 1)
    view.destroy()
    assert.equal(hooks.length, 0)
})

test('the source view works without Livewire on the page', () => {
    const { view, root } = setup()

    assert.equal(view.enter(), true)
    assert.ok(root.classList.contains(ACTIVE_CLASS))
    assert.equal(view.exit(), true)
})
