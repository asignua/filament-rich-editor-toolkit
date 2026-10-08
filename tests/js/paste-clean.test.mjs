// Suite for the pure function cleanPastedHtml (resources/js/src/paste-clean.js).
//
// Runner: the built-in `node --test` plus jsdom. Not vitest: CI installs
// dependencies as `npm ci --omit=optional --ignore-scripts`, and the platform
// binaries of esbuild/rollup that vitest stands on arrive precisely as
// optionalDependencies via postinstall - both flags kill them.
//
// We assert INVARIANTS (no class=, style=, mso-, <span, <div), not golden
// strings: the whitespace layout between tags is not part of the contract.

import { test } from 'node:test'
import assert from 'node:assert/strict'
import { existsSync, readdirSync, readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'
import { JSDOM } from 'jsdom'

import { cleanPastedHtml } from '../../resources/js/src/paste-clean.js'

const here = dirname(fileURLToPath(import.meta.url))
const dom = new JSDOM('')
const ORIGIN = 'https://example.test'

const parseHtml = (html) => new dom.window.DOMParser().parseFromString(html, 'text/html')
const clean = (html, options = {}) => cleanPastedHtml(html, { parseHtml, origin: ORIGIN, ...options })

// Text without markup - so we do not depend on whitespace and attribute order.
// Closing block tags are replaced with a space: otherwise textContent glues
// neighbouring paragraphs ("Block" + "Inline" = "BlockInline") and the test sees
// a bug that does not exist.
const BLOCK_CLOSE = /<\/(p|h[1-6]|li|blockquote|td|th|tr|div|section|article)\s*>/gi
// Collapse ONLY ordinary whitespace: \s in JS includes nbsp, and nbsp is
// significant here (Word puts it inside numbers, and it must survive to the output).
const textOf = (html) =>
    parseHtml(String(html).replace(BLOCK_CLOSE, ' '))
        .body.textContent.replace(/[ \t\r\n]+/g, ' ')
        .trim()
const countOf = (html, selector) => parseHtml(html).body.querySelectorAll(selector).length

// --- the module is importable outside a browser -------------------------
// Pins the property the whole suite rests on: `window` (and import.meta) are
// read only inside the default-export factory. Move them to the top level and
// this file would not even load.

test('module imports under Node, without window', () => {
    assert.equal(typeof cleanPastedHtml, 'function')
})

// --- cut-offs -----------------------------------------------------------

test('in-editor paste (data-pm-slice) is not touched at all', () => {
    const html = '<div data-pm-slice="1 1 []"><p class="lead" id="x" style="color:red">Text</p></div>'

    assert.equal(clean(html), html)
})

test('non-strings and empty input are returned as is', () => {
    assert.equal(clean(''), '')
    assert.equal(clean('   '), '   ')
    assert.equal(clean(null), null)
    assert.equal(clean(undefined), undefined)
})

// --- a Word document ----------------------------------------------------

test('the body of <style> does not become text', () => {
    const out = clean(
        '<html><head><style>p.MsoNormal{margin:0;color:red}</style></head>' +
            '<body><p class=MsoNormal>Text</p></body></html>',
    )

    assert.equal(textOf(out), 'Text')
    assert.ok(!out.includes('MsoNormal'))
})

test('conditional comments and Word namespace tags disappear', () => {
    const out = clean(
        '<!--[if gte mso 9]><xml><w:WordDocument/></xml><![endif]-->' +
            '<p>Text<o:p></o:p></p><v:shape id="s"><i>ignore</i></v:shape>',
    )

    assert.equal(textOf(out), 'Text')
    assert.ok(!out.includes('WordDocument'))
    assert.ok(!/<o:p|<v:shape|<xml/i.test(out))
})

test('Word spacer paragraphs are removed', () => {
    const out = clean('<p>One</p><p>&nbsp;</p><p><br></p><p>  </p><p>Two</p>')

    assert.equal(countOf(out, 'p'), 2)
})

test('nbsp indents collapse, a single nbsp between words stays', () => {
    const out = clean('<p>&nbsp;&nbsp;&nbsp;&nbsp;Indent</p><p>10&nbsp;UAH</p>')

    assert.equal(textOf(out).replace(/\u00a0/g, ' '), 'Indent 10 UAH')
    assert.ok(parseHtml(out).body.textContent.includes('10\u00a0UAH'))
})

// --- Word lists ---------------------------------------------------------

const wordItem = (marker, text, level = 1) =>
    `<p class=MsoListParagraphCxSpMiddle style='mso-list:l0 level${level} lfo1'>` +
    `<span style='mso-list:Ignore'>${marker}<span style='font:7.0pt "Times New Roman"'>&nbsp;&nbsp;</span></span>` +
    `${text}</p>`

test('a Word bulleted list becomes <ul>, the fake marker does not leak into the text', () => {
    const out = clean(wordItem('·', 'First') + wordItem('·', 'Second'))

    assert.equal(countOf(out, 'ul'), 1)
    assert.equal(countOf(out, 'ul > li'), 2)
    assert.equal(countOf(out, 'ol'), 0)
    assert.equal(textOf(out), 'First Second')
})

test('a Word numbered list becomes <ol>', () => {
    const out = clean(wordItem('1.', 'First') + wordItem('2.', 'Second'))

    assert.equal(countOf(out, 'ol'), 1)
    assert.equal(countOf(out, 'ol > li'), 2)
    assert.equal(textOf(out), 'First Second')
})

test('nested levels are flattened into the same list', () => {
    const out = clean(wordItem('·', 'Top', 1) + wordItem('o', 'Nested', 2) + wordItem('·', 'Top 2', 1))

    assert.equal(countOf(out, 'ul'), 1)
    assert.equal(countOf(out, 'ul > li'), 3)
    assert.equal(countOf(out, 'ol'), 0, 'the second-level "o" marker is a bullet, not numbering')
})

test('a change of marker type splits the run into two lists', () => {
    const out = clean(wordItem('·', 'Bullet') + wordItem('1.', 'Number'))

    assert.equal(countOf(out, 'ul'), 1)
    assert.equal(countOf(out, 'ol'), 1)
})

test('a paragraph between lists splits the run', () => {
    const out = clean(wordItem('·', 'One') + '<p>Prose</p>' + wordItem('·', 'Two'))

    assert.equal(countOf(out, 'ul'), 2)
})

test('MsoListParagraph without a marker-like start stays a paragraph', () => {
    const out = clean("<p class=MsoListParagraph style='margin-left:36pt'>For example like this</p>")

    assert.equal(countOf(out, 'ul, ol'), 0)
    assert.equal(textOf(out), 'For example like this', 'the first word must not be eaten as a marker')
})

test('real <ul>/<ol> pass through, nesting is preserved', () => {
    const out = clean('<ul><li>One<ul><li>Nested</li></ul></li><li>Two</li></ul>')

    assert.equal(countOf(out, 'ul'), 2)
    assert.equal(countOf(out, 'li'), 3)
})

// --- Google Docs --------------------------------------------------------

test('the Google Docs guid wrapper does not make the document bold', () => {
    const out = clean(
        '<b id="docs-internal-guid-abc" style="font-weight:normal"><p>Plain text</p></b>',
    )

    assert.equal(countOf(out, 'strong, b'), 0)
    assert.equal(textOf(out), 'Plain text')
})

test('bold/italic/underline from inline styles become tags', () => {
    const out = clean(
        '<p><span style="font-weight:700">bold</span> ' +
            '<span style="font-style:italic">italic</span> ' +
            '<span style="text-decoration:underline">underlined</span> ' +
            '<span style="text-decoration:line-through">struck</span></p>',
    )

    assert.equal(countOf(out, 'strong'), 1)
    assert.equal(countOf(out, 'em'), 1)
    assert.equal(countOf(out, 'u'), 1)
    assert.equal(countOf(out, 's'), 1)
    assert.ok(!out.includes('<span'))
})

test('bold on a block element is rescued too', () => {
    const out = clean('<p style="font-weight:bold">Heading as a paragraph</p>')

    assert.equal(countOf(out, 'p > strong'), 1)
})

test('b/i/strike/del/ins are renamed without doubling', () => {
    const out = clean('<p><b style="font-weight:bold">b</b><i>i</i><strike>s</strike><ins>u</ins></p>')

    assert.equal(countOf(out, 'strong'), 1)
    assert.equal(countOf(out, 'strong strong'), 0)
    assert.equal(countOf(out, 'em'), 1)
    assert.equal(countOf(out, 's'), 1)
    assert.equal(countOf(out, 'u'), 1)
})

// --- attributes ---------------------------------------------------------

test('class/id/style disappear everywhere', () => {
    const out = clean(
        '<p class="MsoNormal" id="p1" style="color:#c00;font-size:22pt;background:#ff0">' +
            '<strong class="x" style="color:blue">Text</strong></p>',
    )

    assert.ok(!/class=|id=|style=/.test(out))
    assert.equal(countOf(out, 'strong'), 1)
    assert.equal(textOf(out), 'Text')
})

test('href is kept only for http/https/mailto, other links are unwrapped', () => {
    const out = clean(
        '<p><a href="https://ok.test/x">yes</a> <a href="mailto:a@b.c">mail</a> ' +
            '<a href="javascript:alert(1)">no</a> <a href="#_Toc1">anchor</a> <a name="_Toc1">label</a></p>',
    )

    assert.equal(countOf(out, 'a'), 2)
    assert.equal(countOf(out, 'a[href^="https://"]'), 1)
    assert.equal(countOf(out, 'a[href^="mailto:"]'), 1)
    assert.ok(!out.includes('javascript'))
    assert.equal(textOf(out), 'yes mail no anchor label', 'the text of dead links is preserved')
})

test('only meaningful colspan/rowspan are kept', () => {
    const out = clean(
        '<table><tr><td colspan="2" width="100" style="border:1px">A</td>' +
            '<td colspan="1">B</td><td colspan="abc">C</td></tr></table>',
    )

    assert.equal(countOf(out, 'td[colspan="2"]'), 1)
    assert.equal(countOf(out, 'td[colspan]'), 1)
    assert.ok(!out.includes('width='))
})

// --- images -------------------------------------------------------------

test('an image uploaded through us survives with src/data-id/alt', () => {
    const out = clean('<p><img data-id="k1" src="/ee-media/12" alt="Description" width="600" style="border:0"></p>')

    assert.equal(countOf(out, 'img[data-id="k1"][src="/ee-media/12"][alt="Description"]'), 1)
    assert.ok(!out.includes('width='))
})

test('an absolute URL of our own origin is ours too', () => {
    const out = clean(`<p><img data-id="k1" src="${ORIGIN}/ee-media/12"></p>`)

    assert.equal(countOf(out, 'img'), 1)
})

test('foreign, file: and data: images are removed', () => {
    const out = clean(
        '<p><img data-id="k" src="https://evil.test/x.png"></p>' +
            '<p><img src="file:///C:/tmp/clip_image001.png"></p>' +
            '<p><img src="data:image/png;base64,iVBORw0KGgo="></p>' +
            '<p><img src="//cdn.test/x.png" data-id="k"></p>',
    )

    assert.equal(countOf(out, 'img'), 0)
    assert.equal(countOf(out, 'p'), 0, 'wrapper paragraphs left without an image are removed')
})

test('a paragraph with a single surviving image does not vanish', () => {
    const out = clean('<p><img data-id="k1" src="/ee-media/12"></p>')

    assert.equal(countOf(out, 'p > img'), 1)
})

// --- structure ----------------------------------------------------------

test('a block div is unwrapped, an inline div becomes a paragraph', () => {
    const out = clean('<div class="wrap"><p>Block</p></div><div>Inline</div>')

    assert.ok(!out.includes('<div'))
    assert.equal(countOf(out, 'p'), 2)
    assert.equal(textOf(out), 'Block Inline')
})

test('span and font are unwrapped without losing text', () => {
    const out = clean('<p><font face="Arial"><span lang=UK>Text</span></font></p>')

    assert.ok(!/<span|<font/.test(out))
    assert.equal(textOf(out), 'Text')
})

test('a table with thead/th survives, the caption becomes a paragraph before it', () => {
    const out = clean(
        '<table><caption>Caption</caption><thead><tr><th>H</th></tr></thead>' +
            '<tbody><tr><td>D</td></tr></tbody><colgroup><col></colgroup></table>',
    )

    assert.equal(countOf(out, 'table thead th'), 1)
    assert.equal(countOf(out, 'table tbody td'), 1)
    assert.equal(countOf(out, 'col, colgroup, caption'), 0)
    assert.equal(parseHtml(out).body.firstElementChild.tagName.toLowerCase(), 'p')
    assert.ok(textOf(out).startsWith('Caption'))
})

test('a table inside <li> is moved out after the list', () => {
    const out = clean('<ul><li>Item<table><tr><td>D</td></tr></table></li></ul>')

    assert.equal(countOf(out, 'li table'), 0)
    assert.equal(countOf(out, 'table'), 1)
    assert.equal(countOf(out, 'ul > li'), 1)
})

test('a paragraph that is a direct child of a list is wrapped in <li>', () => {
    const out = clean('<ul><p>Orphan</p></ul>')

    assert.equal(countOf(out, 'ul > li'), 1)
    assert.equal(countOf(out, 'ul > p'), 0)
})

test('scripts and forms disappear together with their contents', () => {
    const out = clean('<p>Yes</p><script>alert(1)</script><form><input value="x"><button>No</button></form>')

    assert.equal(textOf(out), 'Yes')
    assert.ok(!/alert|<input|<button|<form/.test(out))
})

// --- a whole Word document ----------------------------------------------
// Not a "clipboard snapshot" (those belong in fixtures/), but a document
// assembled from documented Word constructs. Keep it here: the small cases above
// missed the whitespace nodes between blocks that broke idempotency.

const WORD_DOCUMENT = `<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word">
<head><meta charset="utf-8"><meta name=Generator content="Microsoft Word 15">
<!--[if gte mso 9]><xml><w:WordDocument><w:View>Normal</w:View></w:WordDocument></xml><![endif]-->
<style><!--
p.MsoNormal, li.MsoNormal {margin:0cm; font-size:11.0pt; color:#C00000;}
@list l0:level1 {mso-level-number-format:bullet;}
--></style></head>
<body lang=UK style='word-wrap:break-word'>
<h1 style='color:#1F4E79;font-size:22.0pt;background:yellow'><span style='mso-fareast-language:UK'>Grant heading</span><o:p></o:p></h1>
<p class=MsoNormal style='color:#C00000'><span style='font-size:14.0pt;color:red'>A paragraph with <b style='mso-bidi-font-weight:normal'>bold</b> and <i>italic</i>.</span><o:p></o:p></p>
<p class=MsoNormal>&nbsp;</p>
<p class=MsoListParagraphCxSpFirst style='margin-left:36.0pt;mso-list:l0 level1 lfo1'><![if !supportLists]><span style='font-family:Symbol;mso-list:Ignore'>&#183;<span style='font:7.0pt "Times New Roman"'>&nbsp;&nbsp;&nbsp; </span></span><![endif]>First condition</p>
<p class=MsoListParagraphCxSpMiddle style='margin-left:72.0pt;mso-list:l0 level2 lfo1'><![if !supportLists]><span style='font-family:"Courier New";mso-list:Ignore'>o<span style='font:7.0pt "Times New Roman"'>&nbsp;&nbsp; </span></span><![endif]>Nested clarification</p>
<p class=MsoListParagraphCxSpLast style='margin-left:36.0pt;mso-list:l0 level1 lfo1'><![if !supportLists]><span style='font-family:Symbol;mso-list:Ignore'>&#183;<span style='font:7.0pt "Times New Roman"'>&nbsp;&nbsp;&nbsp; </span></span><![endif]>Second condition</p>
<p class=MsoNormal>Next <a href="https://example.org/grant">a link</a> and <a href="#_Toc12345">an anchor</a>.</p>
<table class=MsoTableGrid border=1 cellspacing=0 cellpadding=0 style='border-collapse:collapse'>
<tr><td width=200 valign=top style='background:#DEEAF6'><p class=MsoNormal><b>Budget</b></p></td>
<td width=200 colspan=2 style='width:150.0pt'><p class=MsoNormal>50 000&nbsp;UAH</p></td></tr>
</table>
<p class=MsoNormal><img width=300 height=200 src="file:///C:/Temp/msohtmlclip1/01/clip_image001.png" v:shapes="Picture_x0020_1"><o:p></o:p></p>
<p class=MsoNormal>&nbsp;</p>
</body></html>`

test('a full Word document: only the structure remains', () => {
    const out = clean(WORD_DOCUMENT)

    assert.ok(!/\sclass=|\sstyle=|\sid=/.test(out), 'class/style/id left over')
    assert.ok(!/mso-|Mso[A-Z]|<span|<div|<o:|<v:|<w:|<xml|<style/i.test(out), 'Word junk left over')

    assert.equal(countOf(out, 'h1'), 1)
    assert.equal(countOf(out, 'ul'), 1)
    assert.equal(countOf(out, 'ul > li'), 3, 'the nested level is flattened into the same list')
    assert.equal(countOf(out, 'strong'), 2)
    assert.equal(countOf(out, 'em'), 1)
    assert.equal(countOf(out, 'a[href="https://example.org/grant"]'), 1)
    assert.equal(countOf(out, 'a'), 1, 'the anchor is unwrapped')
    assert.equal(countOf(out, 'table td'), 2)
    assert.equal(countOf(out, 'td[colspan="2"]'), 1)
    assert.equal(countOf(out, 'img'), 0, 'the file:/// Word image is removed')
    assert.ok(textOf(out).includes('50 000\u00a0UAH'), 'the single nbsp in the amount survived')
})

// --- "clean format" button mode -----------------------------------------
// The asignuaCleanFormat command passes these same options: cleaning existing
// editor content must keep what the editor deliberately stores - links with
// configured href prefixes and custom blocks. The default (paste) is unchanged.

const CUSTOM_BLOCK =
    '<div data-type="customBlock" data-id="video" data-config="{&quot;url&quot;:&quot;x&quot;}">preview</div>'

test('default: prefixed hrefs are unwrapped, a custom block is destroyed (paste contract)', () => {
    const out = clean(`<p><a href="/internal/opportunity/12">link</a></p>${CUSTOM_BLOCK}`)

    assert.equal(countOf(out, 'a'), 0)
    assert.equal(countOf(out, 'div, [data-type="customBlock"]'), 0)
    assert.equal(textOf(out), 'link preview')
})

test('default keepLinkPrefixes [] drops internal-looking links even when passed explicitly', () => {
    const out = clean('<p><a href="/internal/opportunity/12">link</a></p>', { keepLinkPrefixes: [] })

    assert.equal(countOf(out, 'a'), 0)
    assert.equal(textOf(out), 'link')
})

test('keepLinkPrefixes: a matching href survives, junk on it is cleaned', () => {
    const out = clean(
        '<p><a class="x" style="color:red" href="/internal/opportunity/12">link</a> ' +
            '<a href="/relative/path">foreign relative</a> <a href="javascript:alert(1)">evil</a></p>',
        { keepLinkPrefixes: ['/internal/'] },
    )

    assert.equal(countOf(out, 'a[href="/internal/opportunity/12"]'), 1)
    assert.equal(countOf(out, 'a'), 1, 'other relative and javascript: links are still unwrapped')
    assert.ok(!/class=|style=/.test(out))
})

test('keepLinkPrefixes accepts several prefixes', () => {
    const out = clean('<p><a href="/internal/a">a</a> <a href="/other/b">b</a> <a href="/nope/c">c</a></p>', {
        keepLinkPrefixes: ['/internal/', '/other/'],
    })

    assert.equal(countOf(out, 'a'), 2)
    assert.equal(countOf(out, 'a[href="/nope/c"]'), 0)
})

test('keepLinkPrefixes ignores empty-string prefixes (they would match every href)', () => {
    const out = clean('<p><a href="/relative/path">x</a></p>', { keepLinkPrefixes: [''] })

    assert.equal(countOf(out, 'a'), 0)
})

test('keepCustomBlocks: the block survives with all attributes, the surroundings are cleaned', () => {
    const out = clean(
        `<p class=MsoNormal style='color:red'><span style='font-size:14pt'>Before</span></p>${CUSTOM_BLOCK}<p>After</p>`,
        { keepCustomBlocks: true },
    )

    assert.equal(countOf(out, 'div[data-type="customBlock"][data-id="video"]'), 1)
    const block = parseHtml(out).body.querySelector('[data-type="customBlock"]')
    assert.equal(block.getAttribute('data-config'), '{"url":"x"}')
    assert.equal(block.textContent, 'preview')
    assert.ok(!/class=|style=|<span/.test(out))
    assert.equal(textOf(out), 'Before preview After')
})

test('button mode is idempotent', () => {
    const options = { keepLinkPrefixes: ['/internal/'], keepCustomBlocks: true }
    const source =
        `<p><a href="/internal/content/5">link</a></p>${CUSTOM_BLOCK}` +
        '<div><p style="color:red">Block</p></div>'
    const once = clean(source, options)

    assert.equal(clean(once, options), once)
})

// existingContent: what the button passes. The selection is the editor's own
// serialization, so the nodes in it already passed the schema - they are not
// clipboard junk and must not be destroyed.

const BUTTON = { keepCustomBlocks: true, existingContent: true }

test('existingContent: an embed iframe, a URL image and a code block survive', () => {
    const out = clean(
        '<p style="color:red">Text <img src="https://cdn.other.test/a.png" alt="Remote"></p>' +
            '<iframe src="https://www.youtube-nocookie.com/embed/abc" sandbox="allow-scripts" width="560"></iframe>' +
            '<pre><code class="language-php">$a = 1;\n\n    $b = 2;</code></pre>' +
            '<ul><li><p>Item <img src="https://cdn.other.test/b.png"></p></li></ul>',
        BUTTON,
    )

    assert.equal(countOf(out, 'p > img[src="https://cdn.other.test/a.png"][alt="Remote"]'), 1)
    assert.equal(countOf(out, 'li img[src="https://cdn.other.test/b.png"]'), 1, 'a nested inline image stays inline')
    assert.equal(countOf(out, 'iframe[src="https://www.youtube-nocookie.com/embed/abc"][sandbox][width="560"]'), 1)
    const code = parseHtml(out).body.querySelector('pre > code.language-php')
    assert.ok(code, 'the code block is kept')
    assert.equal(code.textContent, '$a = 1;\n\n    $b = 2;', 'its whitespace is not collapsed')
    assert.ok(!/color:red/.test(out), 'the surroundings are still cleaned')
    assert.equal(countOf(out, 'body > img'), 0, 'an inline image is never hoisted out of its paragraph')
})

test('existingContent: an image in the middle of text keeps its place', () => {
    const out = clean('<p>Before <img src="https://cdn.other.test/a.png"> after</p>', BUTTON)

    assert.equal(countOf(out, 'p'), 1)
    assert.equal(textOf(out), 'Before after')
    const paragraph = parseHtml(out).body.querySelector('p')
    assert.equal(paragraph.childNodes[1].nodeName, 'IMG')
})

test('existingContent: relative, fragment and tel: links stay, script schemes do not', () => {
    const out = clean(
        '<p><a href="/contacts">rel</a> <a href="../x">up</a> <a href="#section">frag</a> ' +
            '<a href="tel:+380441234567">tel</a> <a href="jav\tascript:alert(1)">evil</a> ' +
            '<a href="vbscript:x">vb</a> <a href="data:text/html,x">data</a></p>',
        BUTTON,
    )

    for (const href of ['/contacts', '../x', '#section', 'tel:+380441234567']) {
        assert.equal(countOf(out, `a[href="${href}"]`), 1, `${href} is kept`)
    }

    assert.equal(countOf(out, 'a'), 4)
    assert.ok(!/javascript|vbscript|data:/.test(out))
})

test('paste: tel: links are kept, relative and fragment links are still unwrapped', () => {
    const out = clean('<p><a href="tel:+380441234567">call</a> <a href="/x">rel</a> <a href="#_Toc1">toc</a></p>')

    assert.equal(countOf(out, 'a[href="tel:+380441234567"]'), 1)
    assert.equal(countOf(out, 'a'), 1)
})

test('paste contract is unchanged without existingContent: iframes and foreign images go', () => {
    const out = clean(
        '<p>x <img src="https://cdn.other.test/a.png"></p><iframe src="https://www.youtube-nocookie.com/embed/abc"></iframe>',
        { keepCustomBlocks: true },
    )

    assert.equal(countOf(out, 'img, iframe'), 0)
})

test('existingContent mode is idempotent', () => {
    const source =
        '<p>Before <img src="https://cdn.other.test/a.png"> after <a href="/contacts">c</a></p>' +
        '<iframe src="https://www.youtube-nocookie.com/embed/abc"></iframe><pre><code>a\n  b</code></pre>'
    const once = clean(source, BUTTON)

    assert.equal(clean(once, BUTTON), once)
})

test('existingContent: mentions and merge tags keep their data-id', () => {
    const out = clean(
        '<p style="color:red">Hello <span data-type="mergeTag" data-id="name">Name</span> and ' +
            '<span data-type="mention" data-id="7" data-label="Ann">Ann</span></p>',
        BUTTON,
    )

    assert.equal(countOf(out, 'p > span[data-type="mergeTag"][data-id="name"]'), 1)
    assert.equal(countOf(out, 'p > span[data-type="mention"][data-id="7"]'), 1)
    assert.ok(!/color:red/.test(out))
    assert.equal(clean(out, BUTTON), out)
})

test('existingContent: grid, details and lead nodes are kept, their junk attributes are not', () => {
    const out = clean(
        '<div data-type="grid" data-cols="2" data-from-breakpoint="lg" class="grid-layout" style="--cols: 2">' +
            '<div data-type="gridColumn" data-col-span="1" style="grid-column: span 1"><p style="color:red">A</p></div>' +
            '<div data-type="gridColumn" data-col-span="1"><p>B</p></div></div>' +
            '<details open><summary>More</summary><div data-type="detailsContent"><p>Body</p></div></details>' +
            '<div class="lead extra" style="x:y">Lead text</div>',
        BUTTON,
    )

    assert.equal(countOf(out, 'div[data-type="grid"][data-cols="2"][data-from-breakpoint="lg"]'), 1)
    assert.equal(countOf(out, 'div[data-type="grid"] > div[data-type="gridColumn"][data-col-span="1"]'), 2)
    assert.equal(countOf(out, 'details[open] > summary'), 1)
    assert.equal(countOf(out, 'details > div[data-type="detailsContent"] > p'), 1)
    assert.equal(countOf(out, 'div.lead'), 1)
    assert.equal(textOf(out), 'A B MoreBody Lead text')
    assert.ok(!/color:red|style=|extra|grid-layout/.test(out), 'junk class/style is dropped')
    assert.equal(clean(out, BUTTON), out, 'idempotent')
})

test('paste contract: grid, details and lead wrappers are still flattened', () => {
    const out = clean('<div data-type="grid"><div data-type="gridColumn"><p>A</p></div></div><details><summary>S</summary><p>B</p></details>')

    assert.equal(countOf(out, 'div, details, summary'), 0)
})

test('existingContent: target and rel of a link stay, on paste they go', () => {
    const source = '<p><a href="https://a.test/x" target="_blank" rel="noopener noreferrer" class="c">l</a></p>'
    const out = clean(source, BUTTON)

    assert.equal(countOf(out, 'a[href="https://a.test/x"][target="_blank"][rel="noopener noreferrer"]'), 1)
    assert.ok(!/class=/.test(out))
    assert.equal(countOf(clean(source), 'a[target], a[rel]'), 0)
    assert.equal(countOf(clean('<p><a href="https://a.test/x" target="_top">l</a></p>', BUTTON), 'a[target]'), 0)
})

test('a space inside a link href becomes %20 instead of being removed; the scheme check still sees through control characters', () => {
    const out = clean(
        '<p><a href="https://intranet.test/Shared Documents/Plan 2026.docx">doc</a> ' +
            '<a href="  https://a.test/x  ">trim</a> <a href="java\tscript:alert(1)">evil</a> <a href="java script:alert(1)">evil2</a></p>',
    )

    assert.equal(countOf(out, 'a[href="https://intranet.test/Shared%20Documents/Plan%202026.docx"]'), 1)
    assert.equal(countOf(out, 'a[href="https://a.test/x"]'), 1)
    assert.ok(!/script/.test(out))
    assert.equal(
        countOf(clean('<p><a href="/a b/c">r</a></p>', BUTTON), 'a[href="/a%20b/c"]'),
        1,
        'the same in button mode',
    )
})

// The "Clean format" command, run against a minimal fake of Filament's TipTap/ProseMirror.

import factory from '../../resources/js/src/paste-clean.js'

const commandWith = (selection, extra = {}) => {
    globalThis.window = {
        FilamentRichEditor: {
            tiptap: {
                core: { Extension: { create: (config) => config } },
                pmModel: { DOMSerializer: {}, DOMParser: {} },
            },
        },
    }

    try {
        return factory().addCommands().asignuaCleanFormat()({ state: { selection }, chain: () => ({}), ...extra })
    } finally {
        delete globalThis.window
    }
}

test('clean format: an empty selection and a table cell selection are left alone', () => {
    assert.equal(commandWith({ empty: true }), false)
    assert.equal(commandWith({ empty: false, forEachCell: () => {} }), false)
})

test('clean format: the cleaned selection goes back as an open slice (replaceSelection), not as closed nodes', () => {
    const calls = []
    const slice = { openStart: 1, openEnd: 1 }
    const selection = {
        empty: false,
        content: () => ({ content: { forEach: (fn) => fn({ type: { name: 'paragraph' } }) } }),
    }
    const pm = {
        DOMSerializer: {
            fromSchema: () => ({
                serializeNode: () => Object.assign(dom.window.document.createElement('p'), { textContent: 'words' }),
            }),
        },
        DOMParser: { fromSchema: () => ({ parseSlice: () => slice }) },
    }

    globalThis.window = {
        FilamentRichEditor: { tiptap: { core: { Extension: { create: (config) => config } }, pmModel: pm } },
    }
    globalThis.document = dom.window.document
    globalThis.DOMParser = dom.window.DOMParser

    try {
        const chain = {
            command: (fn) => {
                fn({ tr: { replaceSelection: (given) => calls.push(given) } })

                return chain
            },
            insertContent: () => assert.fail('a closed insertContent would split the paragraph'),
            deleteSelection: () => assert.fail('nothing was removed'),
            run: () => true,
        }

        const result = factory().addCommands().asignuaCleanFormat()({
            state: { selection, schema: {} },
            chain: () => chain,
        })

        assert.equal(result, true)
        assert.deepEqual(calls, [slice])
    } finally {
        delete globalThis.window
        delete globalThis.document
        delete globalThis.DOMParser
    }
})

// --- idempotency --------------------------------------------------------

const IDEMPOTENCE_CASES = [
    WORD_DOCUMENT,
    '<p>Simple</p>',
    wordItem('·', 'Bullet') + wordItem('1.', 'Number'),
    '<div><p class="a" style="color:red">Block</p></div><div>Inline</div>',
    '<table><caption>C</caption><tr><td colspan="2">D</td></tr></table>',
    '<p><span style="font-weight:700">b</span><a href="https://ok.test">l</a></p>',
    '<ul><li>One<ul><li>Two</li></ul></li></ul>',
]

test('cleaning is idempotent', () => {
    for (const source of IDEMPOTENCE_CASES) {
        const once = clean(source)

        assert.equal(clean(once), once, `not idempotent on: ${source}`)
    }
})

// --- fixtures from a real clipboard -------------------------------------
// Empty until captured (put verbatim clipboard snapshots into tests/js/fixtures/
// as *.html). The test deliberately does not fail on a missing or empty
// directory: it comes alive on its own as soon as files appear there.

const fixtureDir = join(here, 'fixtures')
const fixtures = existsSync(fixtureDir) ? readdirSync(fixtureDir).filter((name) => name.endsWith('.html')) : []

for (const name of fixtures) {
    test(`fixture ${name}: only the structure remains`, () => {
        const source = readFileSync(join(fixtureDir, name), 'utf8')
        const out = clean(source)

        assert.ok(!/\sclass=/.test(out), 'class left over')
        assert.ok(!/\sstyle=/.test(out), 'style left over')
        assert.ok(!/\sid=/.test(out), 'id left over')
        assert.ok(!/mso-|Mso[A-Z]/.test(out), 'Word junk left over')
        assert.ok(!/<span|<div|<font|<o:|<v:|<w:/.test(out), 'an uncleaned tag left over')
        assert.equal(clean(out), out, 'not idempotent')
    })
}

// --- clean format over a REAL ProseMirror schema --------------------------
// cleanSelectionMixedSlice rebuilds a Slice around top-level custom blocks; a fake cannot prove the
// open ends it hands to replaceSelection are valid, so these run on prosemirror-model itself.

import { Schema, Slice, Fragment, DOMSerializer, DOMParser as PmDOMParser } from 'prosemirror-model'

const pmSchema = new Schema({
    nodes: {
        doc: { content: 'block+' },
        paragraph: { group: 'block', content: 'text*', toDOM: () => ['p', 0], parseDOM: [{ tag: 'p' }] },
        div: { group: 'block', content: 'block+', toDOM: () => ['div', 0], parseDOM: [{ tag: 'div' }] },
        customBlock: {
            group: 'block',
            atom: true,
            attrs: { id: { default: null }, config: { default: null } },
            toDOM: (node) => ['div', { 'data-type': 'customBlock', 'data-id': node.attrs.id }],
            parseDOM: [{ tag: 'div[data-type="customBlock"]', getAttrs: (el) => ({ id: el.getAttribute('data-id') }) }],
        },
        text: { group: 'inline' },
    },
})

const para = (text) => pmSchema.nodes.paragraph.create(null, text ? pmSchema.text(text) : null)
const block = () => pmSchema.nodes.customBlock.create({ id: 'video', config: '{"url":"x"}' })
const wrap = (...children) => pmSchema.nodes.div.create(null, children)

// Runs asignuaCleanFormat over a selection whose content() is `slice`; returns what was handed
// to tr.replaceSelection (undefined when nothing was inserted).
const runCleanFormat = (slice) => {
    let given
    const chain = {
        command: (fn) => {
            fn({ tr: { replaceSelection: (s) => (given = s) } })

            return chain
        },
        deleteSelection: () => chain,
        run: () => true,
    }

    globalThis.window = {
        FilamentRichEditor: {
            tiptap: {
                core: { Extension: { create: (config) => config } },
                pmModel: {
                    // prosemirror-model reaches for a global `document`, which node lacks: hand it jsdom's.
                    DOMSerializer: {
                        fromSchema: (schema) => {
                            const serializer = DOMSerializer.fromSchema(schema)

                            return {
                                serializeNode: (node) => serializer.serializeNode(node, { document: dom.window.document }),
                            }
                        },
                    },
                    DOMParser: PmDOMParser,
                },
            },
        },
    }
    globalThis.document = dom.window.document
    globalThis.DOMParser = dom.window.DOMParser

    try {
        factory().addCommands().asignuaCleanFormat()({
            state: { selection: { empty: false, content: () => slice }, schema: pmSchema },
            chain: () => chain,
        })
    } finally {
        delete globalThis.window
        delete globalThis.document
        delete globalThis.DOMParser
    }

    return given
}

const texts = (slice) => {
    const out = []

    slice.content.forEach((node) => out.push(node.type.name === 'customBlock' ? '[block]' : node.textContent))

    return out
}

test('clean format: a selection [open paragraph, custom block, open paragraph] keeps the block and both open ends', () => {
    const original = new Slice(Fragment.fromArray([para('ne'), block(), para('tw')]), 1, 1)
    const slice = runCleanFormat(original)

    assert.deepEqual(texts(slice), ['ne', '[block]', 'tw'])
    assert.equal(slice.content.child(1).attrs.id, 'video')
    assert.equal(slice.content.child(1).attrs.config, '{"url":"x"}', 'the block is put back as the same node')
    assert.equal(slice.openStart, 1)
    assert.equal(slice.openEnd, 1)
    assert.doesNotThrow(() => pmSchema.nodes.doc.create(null, [para('a')]).replace(1, 1, slice))
})

test('clean format: a selection that starts or ends with a custom block is closed on that side', () => {
    const startsWith = runCleanFormat(new Slice(Fragment.fromArray([block(), para('tw')]), 1, 1))

    assert.deepEqual(texts(startsWith), ['[block]', 'tw'])
    assert.equal(startsWith.openStart, 0)
    assert.equal(startsWith.openEnd, 1)

    const endsWith = runCleanFormat(new Slice(Fragment.fromArray([para('ne'), block()]), 1, 1))

    assert.deepEqual(texts(endsWith), ['ne', '[block]'])
    assert.equal(endsWith.openStart, 1)
    assert.equal(endsWith.openEnd, 0)
})

test('clean format: an open end deeper than the cleaned edge node is clamped to its depth', () => {
    // div > paragraph is flattened to a paragraph by cleaning, so openStart/openEnd 2 no longer fit.
    const original = new Slice(Fragment.fromArray([wrap(para('ne')), block(), wrap(para('tw'))]), 2, 2)
    const slice = runCleanFormat(original)

    assert.deepEqual(texts(slice), ['ne', '[block]', 'tw'])
    assert.equal(slice.content.firstChild.type.name, 'paragraph')
    assert.equal(slice.openStart, 1)
    assert.equal(slice.openEnd, 1)
    assert.doesNotThrow(() => pmSchema.nodes.doc.create(null, [para('a')]).replace(1, 1, slice))
})
