// Suite for fullscreen (resources/js/src/fullscreen.js) and the stylesheet loader (ui-style.js).

import { test } from 'node:test'
import assert from 'node:assert/strict'
import { JSDOM } from 'jsdom'

import factory, { ACTIVE_CLASS, LOCK_CLASS, createFullscreen } from '../../resources/js/src/fullscreen.js'
import { ensureStylesheet, paramsOf } from '../../resources/js/src/ui-style.js'

const page = (extra = '') => {
    const { window } = new JSDOM(
        `<!doctype html><html><head></head><body><section id="parent"><div class="fi-fo-rich-editor" id="root"><div class="fi-fo-rich-editor-toolbar"></div></div></section>${extra}</body></html>`,
        { url: 'https://panel.test/admin' },
    )

    window.scrollTo = (x, y) => {
        window.__scrolled = [x, y]
    }

    return window
}

const keydown = (window, key, init = {}) => {
    const event = new window.KeyboardEvent('keydown', { key, bubbles: true, cancelable: true, ...init })

    window.document.dispatchEvent(event)

    return event
}

test('enter adds the class, locks the page and keeps the layout from collapsing; exit undoes it', () => {
    const window = page()
    const root = window.document.getElementById('root')
    const parent = window.document.getElementById('parent')
    const fullscreen = createFullscreen(root, { win: window, doc: window.document, z: 50 })

    root.getBoundingClientRect = () => ({ height: 320 })

    assert.equal(fullscreen.enter(), true)
    assert.equal(fullscreen.isActive(), true)
    assert.ok(root.classList.contains(ACTIVE_CLASS))
    assert.ok(window.document.documentElement.classList.contains(LOCK_CLASS))
    assert.equal(parent.style.minHeight, '320px')
    assert.equal(root.style.getPropertyValue('--asignua-rte-fullscreen-z'), '50')

    assert.equal(fullscreen.enter(), false)

    assert.equal(fullscreen.exit(), true)
    assert.equal(fullscreen.isActive(), false)
    assert.ok(!root.classList.contains(ACTIVE_CLASS))
    assert.ok(!window.document.documentElement.classList.contains(LOCK_CLASS))
    assert.equal(parent.style.minHeight, '')

    assert.equal(fullscreen.exit(), false)
})

test('the previous min-height of the parent is restored, not cleared', () => {
    const window = page()
    const root = window.document.getElementById('root')
    const parent = window.document.getElementById('parent')
    const fullscreen = createFullscreen(root, { win: window, doc: window.document })

    parent.style.minHeight = '10rem'
    root.getBoundingClientRect = () => ({ height: 99 })

    fullscreen.enter()
    fullscreen.exit()

    assert.equal(parent.style.minHeight, '10rem')
})

test('toggle flips the state', () => {
    const window = page()
    const fullscreen = createFullscreen(window.document.getElementById('root'), { win: window, doc: window.document })

    fullscreen.toggle()
    assert.equal(fullscreen.isActive(), true)
    fullscreen.toggle()
    assert.equal(fullscreen.isActive(), false)
})

test('Esc leaves fullscreen and is swallowed; other keys do nothing', () => {
    const window = page()
    const fullscreen = createFullscreen(window.document.getElementById('root'), { win: window, doc: window.document })

    fullscreen.enter()
    keydown(window, 'a')
    assert.equal(fullscreen.isActive(), true)

    const event = keydown(window, 'Escape')

    assert.equal(fullscreen.isActive(), false)
    assert.equal(event.defaultPrevented, true)
})

test('Esc is left to a modal that does not hold the editor, to an open dropdown and to a handler that took it', () => {
    const window = page('<div class="fi-modal fi-modal-open" id="modal"></div>')
    const root = window.document.getElementById('root')
    const fullscreen = createFullscreen(root, { win: window, doc: window.document })

    fullscreen.enter()
    keydown(window, 'Escape')
    assert.equal(fullscreen.isActive(), true, 'another open modal owns Esc')

    window.document.getElementById('modal').remove()
    root.querySelector('.fi-fo-rich-editor-toolbar').innerHTML = '<button aria-expanded="true"></button>'
    keydown(window, 'Escape')
    assert.equal(fullscreen.isActive(), true, 'an open dropdown owns Esc')

    root.querySelector('.fi-fo-rich-editor-toolbar').innerHTML = ''
    window.document.addEventListener('keydown', (event) => event.preventDefault(), true)
    keydown(window, 'Escape')
    assert.equal(fullscreen.isActive(), true, 'a handler that called preventDefault owns Esc')
})

test('a modal that CONTAINS the editor does not block Esc', () => {
    const { window } = new JSDOM(
        '<div class="fi-modal fi-modal-open"><div class="fi-fo-rich-editor" id="root"></div></div>',
    )
    const root = window.document.getElementById('root')
    const fullscreen = createFullscreen(root, { win: Object.assign(window, { scrollTo() {} }), doc: window.document })

    fullscreen.enter()
    window.document.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }))

    assert.equal(fullscreen.isActive(), false)
})

test('the Esc that leaves fullscreen does not reach the window, where a Filament modal listens for it', () => {
    const window = page()
    const root = window.document.getElementById('root')
    const fullscreen = createFullscreen(root, { win: window, doc: window.document })
    const reached = []

    // Filament: x-on:keydown.window.escape on the modal, i.e. a bubbling listener on `window`.
    window.addEventListener('keydown', (event) => reached.push(event.key))

    const press = () =>
        root.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }))

    // Not in fullscreen: the key is not ours, the modal gets it.
    press()
    assert.deepEqual(reached, ['Escape'])

    fullscreen.enter()
    press()
    assert.equal(fullscreen.isActive(), false)
    assert.deepEqual(reached, ['Escape'], 'consumed by leaving fullscreen: the modal stays open')

    // The next Esc is the modal's again.
    press()
    assert.deepEqual(reached, ['Escape', 'Escape'])

    // When we do not take the key (an open dropdown owns it), it is not swallowed either.
    fullscreen.enter()
    root.querySelector('.fi-fo-rich-editor-toolbar').innerHTML = '<button aria-expanded="true"></button>'
    press()
    assert.equal(fullscreen.isActive(), true)
    assert.equal(reached.length, 3)
})

test('exit puts the page scroll back when it moved', () => {
    const window = page()
    const fullscreen = createFullscreen(window.document.getElementById('root'), { win: window, doc: window.document })

    Object.defineProperty(window, 'scrollY', { value: 480, configurable: true })
    fullscreen.enter()
    Object.defineProperty(window, 'scrollY', { value: 0, configurable: true })
    fullscreen.exit()

    assert.deepEqual(window.__scrolled, [0, 480])
})

test('exit removes the Esc listener; destroy exits', () => {
    const window = page()
    const root = window.document.getElementById('root')
    const fullscreen = createFullscreen(root, { win: window, doc: window.document })

    fullscreen.enter()
    fullscreen.destroy()
    assert.ok(!root.classList.contains(ACTIVE_CLASS))
    assert.ok(!window.document.documentElement.classList.contains(LOCK_CLASS))

    fullscreen.enter()
    keydown(window, 'Escape')
    assert.equal(fullscreen.isActive(), false)
})

// --- the TipTap extension --------------------------------------------------------

const withExtension = (run) => {
    const window = page()
    const root = window.document.getElementById('root')
    const inner = window.document.createElement('div')

    root.appendChild(inner)
    globalThis.window = {
        FilamentRichEditor: { tiptap: { core: { Extension: { create: (config) => config } } } },
        scrollX: 0,
        scrollY: 0,
        scrollTo() {},
    }
    globalThis.document = window.document

    try {
        const extension = factory()
        const context = { editor: { view: { dom: inner } }, storage: extension.addStorage() }

        extension.onCreate.call(context)
        run({ extension, context, root, window })
    } finally {
        delete globalThis.window
        delete globalThis.document
    }
}

test('the command toggles, records the state in storage and marks the transaction', () => {
    withExtension(({ extension, context, root }) => {
        const command = extension.addCommands.call(context).asignuaToggleFullscreen()
        const meta = []
        const tr = { setMeta: (name, value) => meta.push([name, value]) }
        let focused = 0
        const props = { tr, dispatch: () => {}, view: { focus: () => focused++ } }

        assert.equal(command(props), true)
        assert.ok(root.classList.contains(ACTIVE_CLASS))
        assert.equal(context.storage.active, true)
        assert.deepEqual(meta, [['asignuaFullscreen', true]])
        assert.equal(focused, 1)

        command(props)
        assert.ok(!root.classList.contains(ACTIVE_CLASS))
        assert.equal(context.storage.active, false)
    })
})

test('can() (a command without dispatch) has no side effects', () => {
    withExtension(({ extension, context, root }) => {
        const command = extension.addCommands.call(context).asignuaToggleFullscreen()

        assert.equal(command({ tr: {}, dispatch: undefined, view: {} }), true)
        assert.ok(!root.classList.contains(ACTIVE_CLASS))
    })
})

test('destroying the editor leaves fullscreen', () => {
    withExtension(({ extension, context, root }) => {
        extension.addCommands.call(context).asignuaToggleFullscreen()({ tr: { setMeta() {} }, dispatch: () => {}, view: { focus() {} } })
        assert.ok(root.classList.contains(ACTIVE_CLASS))

        extension.onDestroy.call(context)
        assert.ok(!root.classList.contains(ACTIVE_CLASS))
    })
})

// --- ui-style ---------------------------------------------------------------------

test('ensureStylesheet links an http(s) stylesheet once and ignores anything else', () => {
    const window = new JSDOM('<!doctype html><head></head>', { url: 'https://panel.test/admin' }).window

    globalThis.document = window.document

    try {
        assert.equal(ensureStylesheet('https://panel.test/css/asignua/ui.css?v=1'), true)
        assert.equal(window.document.querySelectorAll('link[rel="stylesheet"]').length, 1)

        assert.equal(ensureStylesheet('/css/asignua/ui.css?v=2'), false, 'same path, already linked')
        assert.equal(ensureStylesheet('javascript:alert(1)'), false)
        assert.equal(ensureStylesheet('data:text/css,a{}'), false)
        assert.equal(ensureStylesheet(''), false)
        assert.equal(ensureStylesheet(null), false)
        assert.equal(window.document.querySelectorAll('link[rel="stylesheet"]').length, 1)

        assert.equal(ensureStylesheet('/css/other.css'), true, 'a relative path resolves against the page')
        assert.equal(window.document.querySelectorAll('link[rel="stylesheet"]')[1].href, 'https://panel.test/css/other.css')
    } finally {
        delete globalThis.document
    }
})

test('paramsOf reads a module URL and survives garbage', () => {
    assert.equal(paramsOf('https://x.test/a.js?z=40&css=%2Fa.css').get('z'), '40')
    assert.equal(paramsOf('not a url').get('z'), null)
})
