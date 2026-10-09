// Source (HTML) view for one rich editor: the toolbar button `sourceCode` swaps the editing area
// for a plain <textarea> with the document's HTML, pretty-printed, and back.
//
// HOW THE HTML GETS BACK INTO THE EDITOR. Only through the editor's own schema:
// `editor.commands.setContent(html, { emitUpdate: true })`. TipTap parses that string with a
// DOMParser into a detached document and then with ProseMirror's schema parser, so nothing the
// schema does not know survives, and no user HTML is ever assigned to a live element (this file
// uses no innerHTML/outerHTML at all). `emitUpdate: true` fires the editor's `update` event, and
// Filament's own `update` handler is what copies `editor.getJSON()` into the field state
// (rich-editor.js: `editor.on('update', ...) -> this.state = editor.getJSON()`), so the Livewire
// state follows with no extra wiring. The server sanitizer is untouched.
//
// WHEN IT SYNCS. The textarea is pushed into the editor (a) 400 ms after the last keystroke,
// (b) on `change`/`focusout` (a click on Save blurs it first, so the value is in the state before
// the click is handled), (c) in a capturing `submit` listener on the enclosing <form>, and (d) when
// leaving source mode. So a form saved while still in source mode keeps the source. The
// textarea itself is never rewritten while the user types; it is only replaced on the way in.
//
// THE NOTICE. After leaving, the typed HTML is compared with what the editor now holds
// (`materiallyDiffers`, see source-html.js); a difference shows a non-blocking status message
// above the content: some markup is not supported by this editor and was removed.
//
// While in source mode every toolbar button except `sourceCode` and `fullscreen` is disabled
// (the `disabled` attribute AND a capturing click guard, because Alpine can re-bind `disabled` on
// tools that use `disabledWhenNotActive`). Tab is NOT trapped in the textarea (keyboard users
// could not leave it); the indentation is typed with spaces.
//
// Configuration comes from the query string of this module's URL: `css`, `indent`, and the
// translated strings `t-label` (the textarea's aria-label) and `t-notice`.
//
// IMPORTANT: `window` and `import.meta` are read ONLY inside the default-export factory.

import { ensureStylesheet, paramsOf } from './ui-style.js'
import { materiallyDiffers, prettyHtml } from './source-html.js'

export const ACTIVE_CLASS = 'asignua-rte-source-active'
export const SYNC_DELAY = 400

const KEEP = /asignuaToggle(SourceCode|Fullscreen)/

/**
 * @param {Element} root the field wrapper (.fi-fo-rich-editor)
 * @param {object} editor a TipTap editor (getHTML, commands.setContent, commands.focus, isEditable)
 * @param {{doc?: Document, parseHtml: (html: string) => Document, indent?: number, label?: string, notice?: string, delay?: number}} options
 */
export const createSourceView = (root, editor, options) => {
    const doc = options.doc || globalThis.document
    const delay = options.delay ?? SYNC_DELAY
    const toolbar = root.querySelector('.fi-fo-rich-editor-toolbar')
    const main = root.querySelector('.fi-fo-rich-editor-main')
    const host = main && main.parentElement

    let active = false
    let textarea = null
    let notice = null
    let timer = null
    let initial = ''
    let synced = ''
    let syncing = false
    let locked = []
    let form = null

    const clearNotice = () => {
        notice?.remove()
        notice = null
    }

    const showNotice = () => {
        clearNotice()

        if (!options.notice || !host || !main) {
            return
        }

        notice = doc.createElement('div')
        notice.className = 'asignua-rte-source-notice'
        notice.setAttribute('role', 'status')
        notice.textContent = options.notice
        host.insertBefore(notice, main)
    }

    const autosize = () => {
        if (!textarea) {
            return
        }

        // In fullscreen the textarea fills the screen through flex (CSS); an inline height would fight it.
        if (root.classList.contains('asignua-rte-fullscreen')) {
            textarea.style.height = ''

            return
        }

        textarea.style.height = 'auto'
        textarea.style.height = `${textarea.scrollHeight + 2}px`
    }

    /** Push the textarea into the editor, once per distinct value. */
    const flush = () => {
        if (timer !== null) {
            clearTimeout(timer)
            timer = null
        }

        if (!active || !textarea || textarea.readOnly || textarea.value === synced) {
            return false
        }

        synced = textarea.value
        syncing = true

        try {
            editor.commands.setContent(synced, { emitUpdate: true })
        } finally {
            syncing = false
        }

        return true
    }

    const schedule = () => {
        if (timer !== null) {
            clearTimeout(timer)
        }

        timer = setTimeout(flush, delay)
    }

    const onInput = () => {
        autosize()
        schedule()
    }

    const guard = (event) => {
        const button = event.target && event.target.closest ? event.target.closest('button') : null

        if (button && !KEEP.test(button.getAttribute('x-on:click') || button.getAttribute('@click') || '')) {
            event.preventDefault()
            event.stopImmediatePropagation()
        }
    }

    const lockToolbar = () => {
        if (!toolbar) {
            return
        }

        for (const button of Array.from(toolbar.querySelectorAll('button'))) {
            if (KEEP.test(button.getAttribute('x-on:click') || button.getAttribute('@click') || '') || button.disabled) {
                continue
            }

            button.disabled = true
            locked.push(button)
        }

        toolbar.addEventListener('click', guard, true)
    }

    const unlockToolbar = () => {
        toolbar?.removeEventListener('click', guard, true)

        for (const button of locked) {
            button.disabled = false
        }

        locked = []
    }

    const api = {
        isActive: () => active,
        /** True while a sync is calling setContent, so the owner can tell it from the user typing. */
        isSyncing: () => syncing,
        clearNotice,
        get textarea() {
            return textarea
        },
        enter() {
            if (active || !host || !main) {
                return false
            }

            clearNotice()
            active = true
            initial = prettyHtml(editor.getHTML(), { indent: options.indent, parseHtml: options.parseHtml })
            synced = initial

            textarea = doc.createElement('textarea')
            textarea.className = 'asignua-rte-source'
            textarea.value = initial
            textarea.readOnly = editor.isEditable === false
            textarea.spellcheck = false
            textarea.setAttribute('dir', 'ltr')
            textarea.setAttribute('autocapitalize', 'off')
            textarea.setAttribute('autocomplete', 'off')
            textarea.setAttribute('aria-label', options.label || 'HTML')
            textarea.style.minHeight = `${Math.round(main.getBoundingClientRect().height)}px`

            textarea.addEventListener('input', onInput)
            textarea.addEventListener('change', flush)
            textarea.addEventListener('focusout', flush)

            form = root.closest('form')
            form?.addEventListener('submit', flush, true)

            host.insertBefore(textarea, main.nextSibling)
            root.classList.add(ACTIVE_CLASS)
            lockToolbar()
            autosize()
            textarea.focus()

            return true
        },
        exit() {
            if (!active) {
                return false
            }

            flush()
            active = false

            const typed = textarea.value
            const changed = typed !== initial

            form?.removeEventListener('submit', flush, true)
            form = null
            textarea.remove()
            textarea = null
            root.classList.remove(ACTIVE_CLASS)
            unlockToolbar()

            if (changed && materiallyDiffers(typed, editor.getHTML(), options.parseHtml)) {
                showNotice()
            }

            editor.commands.focus?.()

            return true
        },
        toggle() {
            return active ? api.exit() : api.enter()
        },
        destroy() {
            if (timer !== null) {
                clearTimeout(timer)
                timer = null
            }

            if (active) {
                // The editor is going away; the state was already synced by the last flush.
                active = false
                form?.removeEventListener('submit', flush, true)
                textarea?.remove()
                textarea = null
                root.classList.remove(ACTIVE_CLASS)
                unlockToolbar()
            }

            clearNotice()
        },
    }

    return api
}

export default () => {
    const { Extension } = window.FilamentRichEditor.tiptap.core
    const params = paramsOf(import.meta.url)
    const indent = /^\d+$/.test(params.get('indent') || '') ? Number(params.get('indent')) : 2
    const parseHtml = (html) => new window.DOMParser().parseFromString(html, 'text/html')
    let view = null

    return Extension.create({
        name: 'asignuaSourceCode',
        addStorage() {
            return { active: false }
        },
        onCreate() {
            ensureStylesheet(params.get('css'))

            const root = this.editor.view.dom.closest('.fi-fo-rich-editor')

            if (root) {
                view = createSourceView(root, this.editor, {
                    parseHtml,
                    indent,
                    label: params.get('t-label') || undefined,
                    notice: params.get('t-notice') || undefined,
                })
            }
        },
        onUpdate() {
            // The user kept editing: the notice is about the previous round trip.
            if (view && !view.isSyncing() && !view.isActive()) {
                view.clearNotice()
            }
        },
        onDestroy() {
            view?.destroy()
            view = null
        },
        addCommands() {
            const extension = this

            return {
                asignuaToggleSourceCode:
                    () =>
                    ({ tr, dispatch, editor }) => {
                        if (!dispatch || !view) {
                            return Boolean(view)
                        }

                        // Leaving calls setContent (a transaction of its own): the transaction of THIS
                        // command would be stale by then and ProseMirror would reject it.
                        tr.setMeta('preventDispatch', true)

                        view.toggle()
                        extension.storage.active = view.isActive()

                        // Re-evaluates the toolbar button's pressed state.
                        editor.view.dispatch(editor.state.tr.setMeta('asignuaSourceCode', extension.storage.active))

                        return true
                    },
            }
        },
    })
}
